<?php

declare(strict_types=1);

namespace App\Domain\Tire;

use App\Bootstrap;
use App\Domain\Comarch\ComarchQueue;
use App\Domain\Csv\CsvParser;
use App\Domain\Csv\TireRow;
use App\Logger;

final class ImportProcessor
{
    private const VALID_ROLLING   = ['A', 'B', 'C', 'D', 'E', 'F', 'G'];
    private const VALID_ADHESION  = ['A', 'B', 'C', 'D', 'E', 'F', 'G'];
    private const VALID_WAVES_ABC = ['A', 'B', 'C'];
    private const VALID_WAVES_PAR = [')', '))', ')))'];
    private const VALID_WAVES_NUM = ['1', '2', '3'];

    // ENUM indices matching TiresTableMap (group_id 2 = run_flat, group_id 4 = reinforcement)
    private const RUN_FLAT_ENUM      = ['ROF', 'RunFlat', 'Run Flat', 'SSR', 'EXT', 'ZP', 'HRS', 'RFT', 'EMT', 'DSST', 'XRP', 'ZPS'];
    private const REINFORCEMENT_ENUM = ['C', 'CP', 'RF', 'XL'];

    private const MAX_ERRORS = 100;

    private array $options = [
        'update_price'     => true,
        'update_labels'    => true,
        'update_inne'      => true,
        'update_structure' => false,
        'update_pricing'   => false,
        'update_ref'       => false,  // Option to update REF2 from supplier
    ];

    private array $stats = [
        'created'        => 0,
        'updated'        => 0,
        'skipped'        => 0,
        'errors'         => [],
        'errors_capped'  => false,
    ];

    private NameGenerator $nameGenerator;

    public function __construct(
        private readonly TireRepository $repo,
        private readonly Logger $logger,
    ) {
        $this->nameGenerator = new NameGenerator(new SuffixExtractor());
    }

    /**
     * Analyze CSV and detect new producers that need to be created.
     *
     * @return array{name: string, count: int}[] Array of new producers with product counts
     */
    public function detectNewProducers(string $csvPath): array
    {
        $rows = (new CsvParser())->parseFile($csvPath);
        $producerCounts = [];
        $existingProducers = [];

        foreach ($rows as $row) {
            $name = $row->producerName;

            if ($name === '') {
                continue;
            }

            // Check cache first
            if (!isset($existingProducers[$name])) {
                $existingProducers[$name] = $this->repo->producerByName($name) !== null;
            }

            // Count only new producers
            if (!$existingProducers[$name]) {
                $producerCounts[$name] = ($producerCounts[$name] ?? 0) + 1;
            }
        }

        // Convert to array format
        $result = [];
        foreach ($producerCounts as $name => $count) {
            $result[] = ['name' => $name, 'count' => $count];
        }

        // Sort by count (most products first)
        usort($result, fn($a, $b) => $b['count'] <=> $a['count']);

        return $result;
    }

    /**
     * Dry-run: count rows that would be created, updated, or skipped.
     * No DB writes — uses the same lookup logic as processRow().
     *
     * @return array{will_create: int, will_update: int, will_skip: int}
     */
    public function preview(string $csvPath, array $mapping): array
    {
        $rows   = (new CsvParser())->parseFile($csvPath);
        $counts = ['will_create' => 0, 'will_update' => 0, 'will_skip' => 0];

        foreach ($rows as $row) {
            $producer = $this->repo->producerByName($row->producerName);

            if ($producer === null || !isset($mapping[$row->mappingKey()])) {
                ++$counts['will_skip'];
                continue;
            }

            $existing = $row->hasValidEan()
                ? $this->repo->tireByEan($row->ean)
                : null;

            if ($existing === null && $row->ref1 !== '') {
                $existing = $this->repo->tireByRefAndProducer($row->ref1, $producer['id']);
            }

            if ($existing !== null) {
                ++$counts['will_update'];
            } else {
                ++$counts['will_create'];
            }
        }

        return $counts;
    }

    /**
     * Create multiple producers at once with name mapping and classification.
     * Used when user corrects producer names before import.
     *
     * @param array<string, array{name: string, classification: int}> $producerMapping
     *        CSV name => ['name' => 'Corrected name', 'classification' => 2]
     *        e.g. ['NEXEN' => ['name' => 'Nexen Tire', 'classification' => 2]]
     */
    public function createProducers(array $producerMapping): void
    {
        foreach ($producerMapping as $csvName => $data) {
            // Support both old format (string) and new format (array)
            if (is_string($data)) {
                $correctedName = $data;
                $classification = 2; // Default: Średnia
            } else {
                $correctedName = $data['name'];
                $classification = (int) ($data['classification'] ?? 2);
            }

            $existing = $this->repo->producerByName($correctedName);

            if ($existing === null) {
                $this->logger->info('Creating producer', [
                    'csv_name' => $csvName,
                    'corrected_name' => $correctedName,
                    'classification' => $classification,
                ]);
                $this->repo->createProducer($correctedName, $classification);
            }
        }

        // Clear cache after creating producers
        $this->producerCache = [];
    }

    /**
     * @param  array<string, array{tread_id: int, season_id: int, is_new: bool}> $mapping  mappingKey → resolved tread
     * @param  array{update_price?: bool, update_labels?: bool, update_inne?: bool, update_structure?: bool, update_pricing?: bool, update_ref?: bool} $options
     */
    public function run(string $csvPath, array $mapping, array $options = []): array
    {
        $this->options = array_merge($this->options, $options);
        $this->producerCache = []; // Reset cache

        $rows = (new CsvParser())->parseFile($csvPath);

        foreach ($rows as $row) {
            try {
                $this->processRow($row, $mapping);
            } catch (\Throwable $e) {
                $msg = sprintf('[EAN %s | %s %s] %s', $row->ean, $row->producerName, $row->modelName, $e->getMessage());
                $this->logger->warning('Row import failed', ['error' => $msg]);

                if (count($this->stats['errors']) < self::MAX_ERRORS) {
                    $this->stats['errors'][] = $msg;
                } else {
                    $this->stats['errors_capped'] = true;
                }
            }
        }

        return $this->stats;
    }

    // -------------------------------------------------------------------------

    private array $producerCache = [];

    private function processRow(TireRow $row, array $mapping): void
    {
        // Use cached producer lookup (avoid repeated DB queries)
        if (!isset($this->producerCache[$row->producerName])) {
            $this->producerCache[$row->producerName] = $this->repo->producerByName($row->producerName);

            // Auto-create producer if not exists
            if ($this->producerCache[$row->producerName] === null) {
                if ($row->producerName === '') {
                    ++$this->stats['skipped'];
                    $this->logger->debug('Empty producer name, skipping');
                    return;
                }

                $this->logger->info('Creating new producer', ['producer' => $row->producerName]);
                $this->producerCache[$row->producerName] = $this->repo->createProducer($row->producerName);
            }
        }

        $producer = $this->producerCache[$row->producerName];

        if ($producer === null) {
            ++$this->stats['skipped'];
            return;
        }

        $entry = $mapping[$row->mappingKey()] ?? null;

        if ($entry === null) {
            ++$this->stats['skipped'];
            return;
        }

        $treadId  = (int) $entry['tread_id'];
        $seasonId = (int) $entry['season_id'];

        $existing = $row->hasValidEan()
            ? $this->repo->tireByEan($row->ean)
            : null;

        if ($existing === null && $row->ref1 !== '') {
            $existing = $this->repo->tireByRefAndProducer($row->ref1, $producer['id']);
        }

        if ($existing !== null) {
            $this->update($existing['id'], $row, $producer);
            ++$this->stats['updated'];
            return;
        }

        $this->create($row, $producer, $treadId, $seasonId);
        ++$this->stats['created'];
    }

    private function update(int $tireId, TireRow $row, array $producer): void
    {
        $this->logger->info("UPDATE: Processing tire {$tireId}", [
            'ean' => $row->ean,
            'producer' => $producer['producer'],
            'model' => $row->modelName,
        ]);

        // Check flag_extraoffer before updating price (don't update for special offers/leżaki)
        if ($this->options['update_price'] && $row->hasValidPrice()) {
            $product = $this->repo->getProductById($tireId);
            if ($product !== null && !$product['flag_extraoffer']) {
                $this->repo->updateProductPrice($tireId, $row->price);
            }
        }

        if ($this->options['update_labels']) {
            $labels = $this->buildLabelUpdate($row);
            $this->repo->updateTireLabels($tireId, $labels);
            $this->updateCompoundLabel($tireId, $row);
        }

        if ($this->options['update_inne'] && $row->extra !== '') {
            $this->repo->updateTireInne($tireId, $this->resolveInne($row->extra));
            $shortcuts      = Bootstrap::config()['vehicle_type_shortcuts'];
            $vehicleTypeId  = $shortcuts[$row->vehicleTypeShortcut] ?? 0;
            $this->repo->createTireParameters($tireId, $row->extra, $vehicleTypeId);
        }

        if ($this->options['update_structure']) {
            $this->repo->updateTireStructure($tireId, $row);
        }

        if ($this->options['update_pricing'] && $row->hasValidPrice()) {
            if ($row->hasValidEan()) {
                $tireByEan = $this->repo->tireByEan($row->ean);
                if ($tireByEan !== null) {
                    $this->repo->updateProductCatalogPrice($tireByEan['id'], $row->price);
                }
            }
        }

        // Update REF (and optionally REF2) if option enabled
        // EAN is immutable - it's the search key
        if ($this->options['update_ref']) {
            $this->repo->updateTireRef($tireId, $row->ref1, $row->ref2);
        }

        // Generate proper product name using NameGenerator (like old system)
        $this->updateProductNameUsingGenerator($tireId);

        ComarchQueue::addProduct($tireId);
    }

    private function create(TireRow $row, array $producer, int $treadId, int $seasonId): void
    {
        $this->logger->info("CREATE: Creating new tire", [
            'ean' => $row->ean,
            'producer' => $producer['producer'],
            'model' => $row->modelName,
        ]);

        $size = SizeParser::parseSize($row->size);

        if ($size === null) {
            throw new \RuntimeException("Cannot parse tire size: '{$row->size}'");
        }

        $widthId        = $this->repo->widthId($size['width']);
        $profileId      = $this->repo->profileId($size['profile']);
        $constructionId = $this->repo->constructionId($size['construction']);

        $liId = null;
        $siId = null;
        $li   = '';
        $si   = '';

        if ($row->indices !== '') {
            $idx = SizeParser::parseIndices($row->indices);

            if ($idx !== null) {
                $li   = $idx['li2'] !== '' ? $idx['li'] . '/' . $idx['li2'] : $idx['li'];
                $si   = $idx['si'];
                $liId = $this->repo->loadIndexId($li);
                $siId = $this->repo->speedIndexId($si);
            }
        }

        $shortcuts      = Bootstrap::config()['vehicle_type_shortcuts'];
        $vehicleTypeId  = $shortcuts[$row->vehicleTypeShortcut] ?? 0;

        $name = sprintf(
            '%s %s %s%s',
            $producer['producer'],
            $row->modelName,
            $row->size,
            $row->indices !== '' ? ' ' . $row->indices : ''
        ); // Temporary name, will be regenerated by NameGenerator
        $inne = $this->resolveInne($row->extra);

        $productId = $this->repo->createTire([
            'ean'                => $row->ean,
            'name'               => $name,
            'price'              => $row->price,
            'ref'                => $row->ref1,
            'ref2'               => $row->ref2,
            'producer_id'        => $producer['id'],
            'producer_name'      => $producer['producer'],
            'model_name'         => $row->modelName,
            'width_id'           => $widthId,
            'profile_id'         => $profileId,
            'construction_id'    => $constructionId,
            'li_id'              => $liId,
            'si_id'              => $siId,
            'li'                 => $li,
            'si'                 => $si,
            'vehicle_type_id'    => $vehicleTypeId,
            'tread_id'           => $treadId,
            'season_id'          => $seasonId,
            'size'               => $row->size,
            'width'              => $size['width'],
            'profile'            => $size['profile'],
            'diameter'           => $size['diameter'],
            'rolling_resistance' => $row->rollingResistance,
            'adhesion'           => $row->adhesion,
            'noise'              => $row->noise,
            'waves'              => $row->waves,
            'eprel_id'           => $row->eprelId !== '' ? $row->eprelId : null,
            'other'              => $row->extra !== '' ? $row->extra : null,
            'additional_size'    => $row->size2,
            'additional_indexes' => $row->indices2,
        ] + $inne);

        if ($row->extra !== '') {
            $this->repo->createTireParameters($productId, $row->extra, $vehicleTypeId);
        }

        // Generate proper product name using NameGenerator (like old system)
        $this->updateProductNameUsingGenerator($productId);

        ComarchQueue::addProduct($productId);
    }

    /**
     * Update product name using NameGenerator (same as old system)
     */
    private function updateProductNameUsingGenerator(int $productId): void
    {
        try {
            $pdo = Bootstrap::pdo();
            $tireDataFetcher = new TireDataFetcher($pdo);

            // Fetch tire data with classified parameters
            $tireRow = $tireDataFetcher->fetchTireById($productId);

            if ($tireRow === null) {
                $this->logger->warning("Cannot generate name: tire {$productId} not found");
                return;
            }

            // Decode classified parameters from JSON
            $classifiedParams = TireDataFetcher::decodeClassifiedParameters($tireRow);

            // Generate name and slug using NameGenerator
            $nameAndSlug = $this->nameGenerator->generateWithSlug($tireRow, $classifiedParams);

            $oldName = $tireRow['current_name'] ?? '';

            // DEBUG: Log name generation
            $this->logger->info("Name generation for tire {$productId}", [
                'old_name' => $oldName,
                'new_name' => $nameAndSlug['name'],
                'tire_size' => $tireRow['tire_size'] ?? 'N/A',
                'producer' => $tireRow['producer'] ?? 'N/A',
                'tread' => $tireRow['tread'] ?? 'N/A',
            ]);

            // Archive old name
            if ($oldName !== '' && $oldName !== $nameAndSlug['name']) {
                $this->repo->archiveOldName($productId, $oldName);
            }

            // Update product name and slug
            $this->repo->updateProductNameAndSlug(
                $productId,
                $nameAndSlug['name'],
                $nameAndSlug['slug']
            );

        } catch (\Throwable $e) {
            $this->logger->warning("Name generation failed for tire {$productId}: " . $e->getMessage());
        }
    }

    private function buildLabelUpdate(TireRow $row): array
    {
        if (!$row->hasCompleteLabel()) {
            return [];
        }

        $labels = [];

        if (in_array($row->rollingResistance, self::VALID_ROLLING, true)) {
            $labels['rolling_resistance'] = $row->rollingResistance;
        }
        if (in_array($row->adhesion, self::VALID_ADHESION, true)) {
            $labels['adhesion'] = $row->adhesion;
        }
        if ($row->noise !== '' && (int) $row->noise > 50 && (int) $row->noise < 100) {
            $labels['noise'] = $row->noise;
        }
        if (
            in_array($row->waves, self::VALID_WAVES_ABC, true)
            || in_array($row->waves, self::VALID_WAVES_PAR, true)
            || in_array($row->waves, self::VALID_WAVES_NUM, true)
        ) {
            $labels['waves'] = $row->waves;
        }

        return $labels;
    }

    private function resolveInne(string $inne): array
    {
        $result = [
            'run_flat'         => null,
            'reinforcement'    => null,
            'ex_run_flat'      => '',
            'ex_reinforcement' => '',
            'ex_rim_protector' => '',
            'ex_approval'      => '',
            'ex_other'         => '',
            'all_markers'      => '',
        ];

        $tokens = array_values(array_filter(array_map('trim', explode(';', $inne))));

        if (empty($tokens)) {
            return $result;
        }

        $markers    = $this->repo->markersByValues($tokens);
        $byGroup    = [];
        $knownNames = [];

        foreach ($markers as $m) {
            $byGroup[(int) $m['group_id']][] = $m['marker'];
            $knownNames[] = $m['marker'];
        }

        $allMarkers = array_unique(array_merge($knownNames, $tokens));
        $result['all_markers'] = implode(', ', $allMarkers);

        if (!empty($byGroup[1])) {
            $result['ex_approval'] = implode(', ', array_unique($byGroup[1]));
        }

        if (!empty($byGroup[2])) {
            $unique = array_unique($byGroup[2]);
            $result['ex_run_flat'] = implode(', ', $unique);
            foreach ($unique as $marker) {
                $idx = array_search($marker, self::RUN_FLAT_ENUM, true);
                if ($idx !== false) {
                    $result['run_flat'] = $idx;
                    break;
                }
            }
        }

        if (!empty($byGroup[3])) {
            $result['ex_rim_protector'] = implode(', ', array_unique($byGroup[3]));
        }

        if (!empty($byGroup[4])) {
            $unique = array_unique($byGroup[4]);
            $result['ex_reinforcement'] = implode(', ', $unique);
            foreach ($unique as $marker) {
                $idx = array_search($marker, self::REINFORCEMENT_ENUM, true);
                if ($idx !== false) {
                    $result['reinforcement'] = $idx;
                    break;
                }
            }
        }

        $otherMarkers = [];
        foreach ($byGroup as $gid => $gmarkers) {
            if (!in_array($gid, [1, 2, 3, 4], true)) {
                array_push($otherMarkers, ...$gmarkers);
            }
        }
        if (!empty($otherMarkers)) {
            $result['ex_other'] = implode(', ', array_unique($otherMarkers));
        }

        return $result;
    }

    private function updateCompoundLabel(int $tireId, TireRow $row): void
    {
        $db = Bootstrap::db();

        // New label (A/B/C waves)
        if (in_array($row->waves, self::VALID_WAVES_ABC, true)) {
            $label = $row->rollingResistance . $row->adhesion . $row->noise . $row->waves;
            if (strlen($label) === 5) {
                $db->update('tires', ['label_2021' => $label], ['id' => $tireId]);
            }
        }

        // Old label with parenthesis waves
        if (in_array($row->waves, self::VALID_WAVES_PAR, true)) {
            $numWaves = strlen($row->waves);
            $label    = $row->rollingResistance . $row->adhesion . $row->noise . $numWaves;
            if (strlen($label) === 5) {
                $db->update('tires', [
                    'waves'      => $row->waves,
                    'label_2020' => $label,
                ], ['id' => $tireId]);
            }
        }

        // Old label with numeric waves
        if (in_array($row->waves, self::VALID_WAVES_NUM, true)) {
            $label    = $row->rollingResistance . $row->adhesion . $row->noise . $row->waves;
            $wavesStr = str_repeat(')', (int) $row->waves);
            if (strlen($label) === 5) {
                $db->update('tires', [
                    'waves'      => $wavesStr,
                    'label_2020' => $label,
                ], ['id' => $tireId]);
            }
        }
    }

}
