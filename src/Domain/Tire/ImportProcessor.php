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
    ];

    private array $stats = [
        'created'        => 0,
        'updated'        => 0,
        'skipped'        => 0,
        'errors'         => [],
        'errors_capped'  => false,
    ];

    public function __construct(
        private readonly TireRepository $repo,
        private readonly Logger $logger,
    ) {}

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
     * @param  array<string, array{tread_id: int, season_id: int, is_new: bool}> $mapping  mappingKey → resolved tread
     * @param  array{update_price?: bool, update_labels?: bool, update_inne?: bool, update_structure?: bool, update_pricing?: bool} $options
     */
    public function run(string $csvPath, array $mapping, array $options = []): array
    {
        $this->options = array_merge($this->options, $options);

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

    private function processRow(TireRow $row, array $mapping): void
    {
        $producer = $this->repo->producerByName($row->producerName);

        if ($producer === null) {
            ++$this->stats['skipped'];
            $this->logger->debug('Producer not found, skipping', ['producer' => $row->producerName]);
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
        if ($this->options['update_price'] && $row->hasValidPrice()) {
            $this->repo->updateProductPrice($tireId, $row->price);
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

        $this->repo->updateTireEanRef($tireId, $row->ean, $row->ref2);
        $this->repo->updateProductName($tireId, $this->buildName($row, $producer));

        // For existing products, also update name based on classified parameters if they were updated
        if ($this->options['update_inne'] && $row->extra !== '') {
            $this->updateProductNameWithClassifiedParams($tireId, $row, $producer);
        }

        ComarchQueue::addProduct($tireId);
    }

    private function create(TireRow $row, array $producer, int $treadId, int $seasonId): void
    {
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

        $name = $this->buildName($row, $producer);
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

        $this->repo->updateProductName($productId, $name);

        if ($row->extra !== '') {
            $this->repo->createTireParameters($productId, $row->extra, $vehicleTypeId);
        }

        // Generate proper name based on classified parameters
        $this->updateProductNameWithClassifiedParams($productId, $row, $producer);

        ComarchQueue::addProduct($productId);
    }

    private function buildName(TireRow $row, array $producer): string
    {
        return sprintf(
            '%s %s %s%s',
            $producer['producer'],
            $row->modelName,
            $row->size,
            $row->indices !== '' ? ' ' . $row->indices : ''
        );
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

    private function updateProductNameWithClassifiedParams(int $productId, TireRow $row, array $producer): void
    {
        // Get classified parameters from database
        $classifiedJson = Bootstrap::db()->get('tires_classified_parameters', 'parameters', ['id_tire' => $productId]);
        if ($classifiedJson === null) {
            return; // No classified parameters, keep original name
        }

        $classified = TireParametersBuilder::fromJson($classifiedJson);
        if (empty($classified)) {
            return; // Empty parameters, keep original name
        }

        // Build tireRow-like data for name generation
        $tireRow = [
            'producer'     => $producer['producer'],
            'tread'        => $row->modelName,
            'tire_size'    => $row->size,
            'tire_li'      => $row->indices !== '' ? explode('/', $row->indices)[0] : '',
            'tire_si'      => $row->indices !== '' ? explode('/', $row->indices)[1] ?? '' : '',
            'id_vehicles_type' => Bootstrap::config()['vehicle_type_shortcuts'][$row->vehicleTypeShortcut] ?? 1,
            'reinforcement' => null, // Will be resolved from classified parameters
        ];

        // Generate name using similar logic to main system
        $name = $this->generateNameFromClassifiedParams($tireRow, $classified);

        if ($name !== '') {
            $this->repo->updateProductName($productId, $name);
        }
    }

    private function generateNameFromClassifiedParams(array $tireRow, array $classified): string
    {
        $parts = [];

        // 1. Producer
        $producer = trim((string) ($tireRow['producer'] ?? ''));
        if ('' !== $producer) {
            $parts[] = $producer;
        }

        // 2. Tread (model name)
        $tread = trim((string) ($tireRow['tread'] ?? ''));
        if ('' !== $tread) {
            $parts[] = $tread;
        }

        // 3. Size components + reinforcement (if applicable)
        $size = trim((string) ($tireRow['tire_size'] ?? ''));
        if ('' !== $size) {
            $parsedSize = SizeParser::parseSize($size);
            if ($parsedSize !== null) {
                $reinforcement = $this->resolveReinforcementFromClassified($classified);

                // Add size components separately for proper slug generation
                $parts[] = $parsedSize['width'];
                $parts[] = $parsedSize['profile'];
                // Extract construction letter (R, D, etc.) and diameter separately
                $constructionParts = explode(' ', $parsedSize['construction']);
                $parts[] = strtolower($constructionParts[0] ?? 'r'); // Default to 'r' if parsing fails
                $parts[] = $parsedSize['diameter'];

                // Add reinforcement if applicable (but not as part of size for slug)
                if ($reinforcement !== '' && !\in_array($reinforcement, ['C', 'CP'], true)) {
                    $parts[] = $reinforcement;
                }
            } else {
                // Fallback to full size if parsing fails
                $parts[] = $size;
            }
        }

        // 4. LI/SI formatted
        $li = trim((string) ($tireRow['tire_li'] ?? ''));
        $si = trim((string) ($tireRow['tire_si'] ?? ''));
        $lisi = $this->formatLiSi($li, $si);
        if ('' !== $lisi) {
            $parts[] = $lisi;
        }

        // 5. Suffixes from classified parameters
        $vehicleType = (int) ($tireRow['id_vehicles_type'] ?? 1);
        $suffixes = $this->extractSuffixes($vehicleType, $classified);
        foreach ($suffixes as $suffix) {
            $suffix = trim((string) $suffix);
            if ('' !== $suffix) {
                $parts[] = $suffix;
            }
        }

        // Assemble with single spaces
        $name = implode(' ', $parts);
        $name = preg_replace('/\s{2,}/', ' ', $name);

        return trim($name);
    }

    private function resolveReinforcementFromClassified(array $classified): string
    {
        $reinforcements = $classified['reinforcement'] ?? [];
        if (empty($reinforcements)) {
            return '';
        }

        // Pick strongest: C < CP < RF < XL
        $priority = ['C' => 1, 'CP' => 2, 'RF' => 3, 'XL' => 4];
        $best = '';
        $bestPriority = 0;

        foreach ($reinforcements as $code) {
            $code = trim((string) $code);
            $p = $priority[$code] ?? 0;
            if ($p > $bestPriority) {
                $best = $code;
                $bestPriority = $p;
            }
        }

        return $best;
    }

    private function formatLiSi(string $li, string $si): string
    {
        $li = trim($li);
        $si = trim($si);

        if ('' === $li && '' === $si) {
            return '';
        }

        return $li . '/' . $si;
    }

    private function extractSuffixes(int $vehicleType, array $classified): array
    {
        // Simplified suffix extraction - in full system this uses SuffixExtractor
        $suffixes = [];

        // Add runflat if present
        if (!empty($classified['runflat'] ?? [])) {
            $suffixes[] = implode(' ', $classified['runflat']);
        }

        // Add other common suffixes based on vehicle type
        // This is a simplified version - full system has more complex logic

        return $suffixes;
    }
}
