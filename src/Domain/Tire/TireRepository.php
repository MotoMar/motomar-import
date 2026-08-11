<?php

declare(strict_types=1);

namespace App\Domain\Tire;

use App\Bootstrap;
use App\Domain\Csv\TireRow;
use Medoo\Medoo;

final class TireRepository
{
    private Medoo $db;

    private static ?DictionaryMatcher $dictionaryMatcher = null;
    private static ?TireParametersBuilder $parametersBuilder = null;

    public function __construct()
    {
        $this->db = Bootstrap::db();
    }

    // ------------------------------------------------------------------ producers

    /** @return array<int, array<string, mixed>> */
    public function allProducers(): array
    {
        return $this->db->select('products_producers', ['id', 'producer', 'slug'], [
            'ORDER' => ['producer' => 'ASC'],
        ]) ?? [];
    }

    /** @return array<string, mixed>|null */
    public function producerByName(string $name): ?array
    {
        $row = $this->db->get('products_producers', ['id', 'producer', 'slug'], [
            'producer' => $name,
        ]);

        return is_array($row) ? $row : null;
    }

    /** @return array<string, mixed> */
    public function createProducer(string $name, int $classification = 2): array
    {
        $slug = self::slug($name);
        $this->db->insert('products_producers', [
            'producer' => $name,
            'slug'     => $slug,
            'id_product_category' => 1, // Always tires for import
            'classification' => $classification,
        ]);
        $id = (int) $this->db->id();
        return ['id' => $id, 'producer' => $name, 'slug' => $slug, 'classification' => $classification];
    }

    /**
     * Get producer classification options (ekonomiczna/średnia/premium)
     *
     * @return array<int, array{id: int, name: string}>
     */
    public function getProducerClassifications(): array
    {
        return [
            ['id' => 1, 'name' => 'Ekonomiczna'],
            ['id' => 2, 'name' => 'Średnia'],
            ['id' => 3, 'name' => 'Premium'],
        ];
    }

    // ------------------------------------------------------------------ treads

    /** @return array<int, array<string, mixed>> */
    public function treadsByProducer(int $producerId): array
    {
        return $this->db->select('tires_treads', ['id', 'tread', 'season_id'], [
            'producer_id' => $producerId,
            'ORDER'       => ['tread' => 'ASC'],
        ]) ?? [];
    }

    /** @return array<string, mixed>|null */
    public function treadById(int $id): ?array
    {
        $row = $this->db->get('tires_treads', ['id', 'tread', 'season_id', 'producer_id'], [
            'id' => $id,
        ]);

        return is_array($row) ? $row : null;
    }

    public function createTread(int $producerId, string $name, int $seasonId): int
    {
        $slug = self::slug($name);

        $this->db->insert('tires_treads', [
            'producer_id' => $producerId,
            'tread'       => $name,
            'slug'        => $slug,
            'season_id'   => $seasonId,
        ]);

        $treadId = (int) $this->db->id();

        $this->db->insert('tires_seasons_treads', [
            'id_tire_season' => $seasonId,
            'id_tire_tread'  => $treadId,
        ]);

        return $treadId;
    }

    // ------------------------------------------------------------------ seasons

    /** @return array<int, array<string, mixed>> */
    public function allSeasons(): array
    {
        try {
            $rows = $this->db->select('tires_seasons', ['id', 'season'], ['id' => [1, 2, 3]]) ?? [];

            if (empty($rows)) {
                return [];
            }

            $order = [1 => 0, 2 => 1, 3 => 2];
            usort(
                $rows,
                static fn (array $a, array $b): int =>
                    ($order[RowField::integer($a, 'id')] ?? 99) <=> ($order[RowField::integer($b, 'id')] ?? 99),
            );

            return $rows;
        } catch (\Throwable $e) {
            error_log('TireRepository::allSeasons error: ' . $e->getMessage());
            return [];
        }
    }

    // ------------------------------------------------------------------ dimensions

    public function widthId(string $width): int
    {
        return $this->dimensionId('tires_width', 'width', $width)
            ?? $this->createDimension('tires_width', 'width', $width);
    }

    public function profileId(string $profile): int
    {
        return $this->dimensionId('tires_profile', 'profile', $profile)
            ?? $this->createDimension('tires_profile', 'profile', $profile);
    }

    public function constructionId(string $construction): int
    {
        return $this->dimensionId('tires_construction', 'construction', $construction)
            ?? $this->createDimension('tires_construction', 'construction', $construction);
    }

    public function loadIndexId(string $li): int
    {
        $result = $this->db->get('tires_li', 'id', ['code' => $li, 'ORDER' => ['id' => 'ASC']]);
        if ($result !== null) {
            return (int) $result;
        }

        $this->db->insert('tires_li', ['li' => $li, 'code' => $li, 'slug' => self::slug($li)]);
        return (int) $this->db->id();
    }

    public function speedIndexId(string $si): int
    {
        $result = $this->db->get('tires_si', 'id', ['code' => $si, 'ORDER' => ['id' => 'ASC']]);
        if ($result !== null) {
            return (int) $result;
        }

        $this->db->insert('tires_si', ['si' => $si, 'code' => $si, 'slug' => self::slug($si)]);
        return (int) $this->db->id();
    }

    // ------------------------------------------------------------------ markers

    /**
     * @param string[] $values
     *
     * @return array<int, array<string, mixed>>
     */
    public function markersByValues(array $values): array
    {
        if (empty($values)) {
            return [];
        }

        return $this->db->select('markers', ['marker', 'group_id'], ['marker' => $values]) ?: [];
    }

    // ------------------------------------------------------------------ tires

    /** @return array<string, mixed>|null */
    public function tireByEan(string $ean): ?array
    {
        $row = $this->db->get('tires', ['id', 'id_tires_tread', 'id_tires_season'], ['ean' => $ean]);

        return is_array($row) ? $row : null;
    }

    /** @return array<string, mixed>|null */
    public function tireByRefAndProducer(string $ref, int $producerId): ?array
    {
        $row = $this->db->get('tires', ['id'], [
            'ref'               => $ref,
            'id_product_producer' => $producerId,
        ]);

        return is_array($row) ? $row : null;
    }

    // ------------------------------------------------------------------ product updates

    public function updateProductPrice(int $productId, float $price): void
    {
        $this->db->update('products', ['price_catalog_netto' => $price], ['id' => $productId]);
    }

    /**
     * Write the marker columns for an existing tire.
     *
     * `other` is written here alongside `all_markers`. Leaving it out is what
     * produced 368 tires with markers but no raw string: they were created by
     * the legacy import without `other` and every price list since then filled
     * `all_markers` while `other` stayed empty. The two columns describe the
     * same thing and have to move together.
     */
    /** @param array<string, mixed> $inne */
    public function updateTireInne(int $tireId, array $inne): void
    {
        $fields = [
            'run_flat'         => $inne['run_flat'],
            'reinforcement'    => $inne['reinforcement'],
            'ex_run_flat'      => $inne['ex_run_flat'],
            'ex_reinforcement' => $inne['ex_reinforcement'],
            'ex_rim_protector' => $inne['ex_rim_protector'],
            'ex_approval'      => $inne['ex_approval'],
            'ex_other'         => $inne['ex_other'],
            'all_markers'      => $inne['all_markers'],
            'other'            => $inne['other'],
        ];

        $this->db->update('tires', $fields, ['id' => $tireId]);
    }

    /**
     * Rebuild `tires_classified_parameters` for an existing tire from `other`.
     *
     * The classification is what the product name, the Allegro offer title and
     * the offer parameters are all built from, and until now only the creation
     * path wrote it. A tire that gained `EV` in this year's price list kept a
     * classification from the day it was created.
     *
     * The vehicle type comes from the database rather than the CSV shortcut:
     * an unknown shortcut resolves to 0, and type 0 has no classification order,
     * which would quietly store `{}` over a good classification.
     */
    public function refreshClassifiedParameters(int $tireId, string $other): void
    {
        $vehicleTypeId = $this->db->get('tires', 'id_vehicles_type', ['id' => $tireId]);

        if (!is_numeric($vehicleTypeId)) {
            return;
        }

        $vehicleTypeId = (int) $vehicleTypeId;

        $parameters = $this->classifyTireParameters($tireId, [
            'vehicle_type_id' => $vehicleTypeId,
            'other'           => $other,
        ]);

        // Whatever the classifier cannot produce, it must not delete.
        $existing = TireParametersBuilder::fromJson(
            RowField::nullableText(
                $this->classifiedParametersRow($tireId),
                'parameters',
            ),
        );

        $parameters = TireParametersBuilder::preserveUnclassifiableKinds(
            $parameters,
            $existing,
            VehicleTypeClassificationOrder::forVehicleType($vehicleTypeId),
        );

        TireParametersBuilder::upsert(Bootstrap::pdo(), $tireId, $parameters);
    }

    /**
     * @return array<string, mixed>
     */
    private function classifiedParametersRow(int $tireId): array
    {
        $row = $this->db->get('tires_classified_parameters', ['parameters'], ['id_tire' => $tireId]);

        return is_array($row) ? $row : [];
    }

    public function weightByDimensions(int $widthId, int $constructionId, int $profileId, int $vehicleTypeId): float
    {
        $result = $this->db->get('tires_weights', 'weight', [
            'id_tires_width'        => $widthId,
            'id_tires_construction' => $constructionId,
            'id_tires_profile'      => $profileId,
            'id_vehicle_type'       => $vehicleTypeId,
        ]);

        return $result !== null ? (float) $result : 999.0;
    }

    /** @param array<string, mixed> $fields */
    public function updateTireLabels(int $tireId, array $fields): void
    {
        if (empty($fields)) {
            return;
        }

        $this->db->update('tires', $fields, ['id' => $tireId]);
    }

    public function createTireParameters(int $tireId, string $inne, int $vehicleTypeId): void
    {
        $tokens = array_values(array_filter(array_map('trim', explode(';', $inne))));

        foreach ($tokens as $token) {
            $parameterId = $this->db->get('tires_parameters', 'id', [
                'parameter'       => $token,
                'id_vehicle_type' => $vehicleTypeId,
            ]);

            if ($parameterId === null) {
                $this->db->insert('tires_parameters', [
                    'parameter'       => $token,
                    'id_vehicle_type' => $vehicleTypeId,
                ]);
                $parameterId = (int) $this->db->id();
            } else {
                $parameterId = (int) $parameterId;
            }

            $linked = $this->db->get('tires_tires_parameters', 'id', [
                'id_tire'           => $tireId,
                'id_tire_parameter' => $parameterId,
            ]);

            if ($linked === null) {
                $this->db->insert('tires_tires_parameters', [
                    'id_tire'           => $tireId,
                    'id_tire_parameter' => $parameterId,
                ]);
            }
        }
    }

    /**
     * Update REF and REF2 (supplier references) if different from current values.
     * EAN is NOT updated - it's the immutable search key for products.
     *
     * REF can change when supplier changes their numbering system.
     * REF2 is rarely used but can also be updated.
     */
    public function updateTireRef(int $tireId, string $ref, string $ref2): void
    {
        // Get current values
        $current = $this->db->get('tires', ['ref', 'ref2'], ['id' => $tireId]);

        if (!is_array($current)) {
            return;
        }

        $fields = [];

        // REF: update if not empty and different
        if ($ref !== '' && RowField::text($current, 'ref') !== $ref) {
            $fields['ref'] = $ref;
        }

        // REF2: update if not empty and different
        if ($ref2 !== '' && RowField::text($current, 'ref2') !== $ref2) {
            $fields['ref2'] = $ref2;
        }

        // Only write to DB if something actually changed
        if (!empty($fields)) {
            $this->db->update('tires', $fields, ['id' => $tireId]);
        }
    }

    /**
     * Store a regenerated name and slug.
     *
     * `better_slug` is the address the shop serves from, so it always follows
     * the name. `slug` is the older one and is only written for a product that
     * has just been created — overwriting it on an existing product retires a
     * URL that is already indexed and linked. On production the two disagree
     * for 109 739 of 118 983 tires, which is what that column is for.
     *
     * `regenerateProductsNames` in motomar-php makes the same distinction.
     */
    public function updateProductNameAndSlug(
        int $productId,
        string $name,
        string $slug,
        bool $isNewProduct = false,
    ): void {
        $fields = [
            'name'        => $name,
            'better_slug' => $slug,
        ];

        if ($isNewProduct) {
            $fields['slug'] = $slug;
        }

        $this->db->update('products', $fields, ['id' => $productId]);
    }

    public function archiveOldName(int $productId, string $oldName): void
    {
        $this->db->update('products', [
            'old_name' => $oldName,
        ], ['id' => $productId]);
    }

    public function updateTireStructure(int $tireId, TireRow $row): void
    {
        $size = SizeParser::parseSize($row->size);

        if ($size === null) {
            return;
        }

        $widthId        = $this->widthId($size['width']);
        $profileId      = $this->profileId($size['profile']);
        $constructionId = $this->constructionId($size['construction']);

        $li   = '';
        $si   = '';

        if ($row->indices !== '') {
            $idx = SizeParser::parseIndices($row->indices);

            if ($idx !== null) {
                $li = $idx['li2'] !== '' ? $idx['li'] . '/' . $idx['li2'] : $idx['li'];
                $si = $idx['si'];
            }
        }

        $liId = $this->loadIndexId($li);
        $siId = $this->speedIndexId($si);

        $this->db->update('tires', [
            'id_tires_width'        => $widthId,
            'id_tires_profile'      => $profileId,
            'id_tires_construction' => $constructionId,
            'id_tires_li'           => $liId,
            'id_tires_si'           => $siId,
            'tire_size'             => $row->size,
            'tire_width'            => $size['width'],
            'tire_profile'          => $size['profile'],
            'tire_diameter'         => $size['diameter'],
            'tire_li'               => $li,
            'tire_si'               => $si,
        ], ['id' => $tireId]);
    }

    // ------------------------------------------------------------------ product creation

    /**
     * Creates a new product + tire record.
     * Returns the product ID (= tire ID).
     */
    /** @param array<string, mixed> $data */
    public function createTire(array $data): int
    {
        $this->db->insert('products', [
            'ean'                => $data['ean'],
            'name'               => $data['name'],
            'old_name'           => '',
            'flag_news'          => 0,
            'flag_recommend'     => 0,
            'flag_available'     => 0,
            'flag_sale'          => 0,
            'flag_extraoffer'    => 0,
            'flag_special_price_or_discount' => 0,
            'flag_abatement'     => 0,
            'price_catalog_netto' => $data['price'],
            'id_product_category' => Bootstrap::tireCategoryId(),
            'slug'               => self::slug(RowField::text($data, 'name')),
            'better_slug'        => self::slug(RowField::text($data, 'name')),
            'seo'                => '',
            'enemy_counter'      => 0,
            'ceneo_by_place'     => 0,
            'ceneo_by_price'     => 0,
            'ceneo_id'           => '',
            'skapiec_id'         => '',
            'ceneo'              => '',
            'created_at'         => date('Y-m-d H:i:s'),
        ]);

        $productId = (int) $this->db->id();

        $weight = $this->weightByDimensions(
            RowField::integer($data, 'width_id'),
            RowField::integer($data, 'construction_id'),
            RowField::integer($data, 'profile_id'),
            RowField::integer($data, 'vehicle_type_id')
        );

        $this->db->insert('tires', [
            'id'                    => $productId,
            'ref'                   => $data['ref'],
            'ref2'                  => $data['ref2'],
            'ean'                   => $data['ean'],
            'id_product_producer'   => $data['producer_id'],
            'id_tires_width'        => $data['width_id'],
            'id_tires_profile'      => $data['profile_id'],
            'id_tires_construction' => $data['construction_id'],
            'id_tires_si'           => $data['si_id'],
            'id_tires_li'           => $data['li_id'],
            'id_vehicles_type'      => $data['vehicle_type_id'],
            'weight'                => $weight,
            'id_tires_tread'        => $data['tread_id'],
            'id_tires_season'       => $data['season_id'],
            'rolling_resistance'    => $data['rolling_resistance'],
            'adhesion'              => $data['adhesion'],
            'noise'                 => $data['noise'],
            'waves'                 => $data['waves'],
            'tire_producer'         => $data['producer_name'],
            'tire_producer_slug'    => self::slug(RowField::text($data, 'producer_name')),
            'tire_model'            => $data['model_name'],
            'tire_model_slug'       => self::slug(RowField::text($data, 'model_name')),
            'tire_size'             => $data['size'],
            'tire_width'            => $data['width'],
            'tire_profile'          => $data['profile'],
            'tire_diameter'         => $data['diameter'],
            'tire_li'               => $data['li'],
            'tire_si'               => $data['si'],
            'reinforcement'         => $data['reinforcement'] ?? null,
            'run_flat'              => $data['run_flat'] ?? null,
            'ex_run_flat'           => $data['ex_run_flat'] ?? '',
            'ex_reinforcement'      => $data['ex_reinforcement'] ?? '',
            'ex_rim_protector'      => $data['ex_rim_protector'] ?? '',
            'ex_approval'           => $data['ex_approval'] ?? '',
            'ex_other'              => $data['ex_other'] ?? '',
            'all_markers'           => $data['all_markers'] ?? '',
            'has_all_parameters'    => 0,
            'tread_version'         => '',
            'eprel_id'              => '' !== RowField::text($data, 'eprel_id') ? RowField::integer($data, 'eprel_id') : null,
            'other'                 => $data['other'] ?? null,
            'additional_size'       => $data['additional_size'] ?? '',
            'additional_indexes'    => $data['additional_indexes'] ?? '',
            'id_tires_purpose'      => 35,
            'id_tires_marker'       => 1,
        ]);

        // Create tires_classified_parameters entry with classified parameters.
        // Upsert rather than insert: a leftover row for a recycled id would
        // otherwise abort the whole CSV row on a duplicate key.
        $classified = $this->classifyTireParameters($productId, $data);
        TireParametersBuilder::upsert(Bootstrap::pdo(), $productId, $classified);

        // Create price group entries
        $this->createPriceGroups($productId, RowField::decimal($data, 'price'));

        return $productId;
    }

    // ------------------------------------------------------------------ private

    private function getDictionaryMatcher(): DictionaryMatcher
    {
        if (self::$dictionaryMatcher === null) {
            self::$dictionaryMatcher = DictionaryMatcher::fromPdo(Bootstrap::pdo());
        }
        return self::$dictionaryMatcher;
    }

    private function getParametersBuilder(): TireParametersBuilder
    {
        if (self::$parametersBuilder === null) {
            self::$parametersBuilder = new TireParametersBuilder();
        }
        return self::$parametersBuilder;
    }

    /**
     * @param array<string, mixed> $tireData
     *
     * @return array<string, string[]>
     */
    private function classifyTireParameters(int $tireId, array $tireData): array
    {
        try {
            $matcher = $this->getDictionaryMatcher();
            $builder = $this->getParametersBuilder();
            
            $tireRow = [
                'tire_id'           => $tireId,
                'id_vehicles_type'  => $tireData['vehicle_type_id'] ?? 1,
                'other'             => $tireData['other'] ?? '',
            ];
            
            return $builder->buildParameters($tireRow, $matcher);
        } catch (\Throwable $e) {
            // Log error but don't fail import
            error_log("Tire parameter classification failed for tire {$tireId}: " . $e->getMessage());
            return [];
        }
    }

    private function dimensionId(string $table, string $column, string $value): ?int
    {
        $result = $this->db->get($table, 'id', [$column => $value]);

        return $result !== null ? (int) $result : null;
    }

    private function createDimension(string $table, string $column, string $value): int
    {
        $this->db->insert($table, [$column => $value, 'slug' => self::slug($value)]);

        return (int) $this->db->id();
    }

    private function createPriceGroups(int $productId, float $price): void
    {
        $groups      = $this->db->select('price_groups', ['id'], []) ?? [];
        $ceiledPrice = (float) ceil($price);

        foreach ($groups as $group) {
            $this->db->insert('products_price_groups', [
                'id_product'    => $productId,
                'id_price_group' => RowField::integer($group, 'id'),
                'price_netto'   => $ceiledPrice,
                'discount'      => 0,
            ]);
        }
    }

    // ------------------------------------------------------------------ pricings

    /**
     * Create a pricing history record and insert pricings_tires entries.
     * Mirrors the logic from pricingSave in the old panel.
     *
     * @param int[]  $tireIds  Tire IDs that had their price updated
     * @param int    $producerId
     * @param string $producerName
     * @param int    $rowsUpdated
     */
    public function createPricingRecord(array $tireIds, int $producerId, string $producerName, int $rowsUpdated): void
    {
        if (empty($tireIds)) {
            return;
        }

        $pdo  = Bootstrap::pdo();
        $born = date('Y-m-d');

        // Insert pricing history
        $stmt = $pdo->prepare("
            INSERT INTO pricings SET
                user_id = 0,
                producer_id = :producer_id,
                producer_name = :producer_name,
                rows_all = :rows_all,
                rows_updated = :rows_updated,
                rows_same = 0,
                rows_unknown = 0,
                rows_other = 0,
                unknown_codes = '',
                file_name = 'import',
                notice = 'motomar-import',
                born = :born,
                created = NOW(),
                visible = 1
        ");
        $stmt->execute([
            'producer_id'   => $producerId,
            'producer_name' => $producerName,
            'rows_all'      => count($tireIds),
            'rows_updated'  => $rowsUpdated,
            'born'          => $born,
        ]);

        $pricingId = (int) $pdo->lastInsertId();

        // Insert pricings_tires (ON DUPLICATE KEY UPDATE for idempotency)
        $stmt = $pdo->prepare("
            INSERT INTO pricings_tires (tire_id, pricing_id, born, created)
            VALUES (:tire_id, :pricing_id, :born, NOW())
            ON DUPLICATE KEY UPDATE
                pricing_id = VALUES(pricing_id),
                born = VALUES(born),
                created = VALUES(created)
        ");

        foreach ($tireIds as $tireId) {
            $stmt->execute([
                'tire_id'    => $tireId,
                'pricing_id' => $pricingId,
                'born'       => $born,
            ]);
        }
    }

    /**
     * Get last pricing date for each producer (from pricings table).
     *
     * @param  string[] $producerNames
     * @return array<string, string|null>  producer_name => last born date (or null if never)
     */
    public function getLastPricingDates(array $producerNames): array
    {
        if (empty($producerNames)) {
            return [];
        }

        $pdo = Bootstrap::pdo();
        $placeholders = implode(',', array_fill(0, count($producerNames), '?'));

        $stmt = $pdo->prepare("
            SELECT producer_name, MAX(born) AS last_born
            FROM pricings
            WHERE producer_name IN ({$placeholders}) AND visible = 1
            GROUP BY producer_name
        ");
        $stmt->execute(array_values($producerNames));

        $result = [];
        foreach ($producerNames as $name) {
            $result[$name] = null;
        }
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $result[$row['producer_name']] = $row['last_born'];
        }

        return $result;
    }

    /**
     * Get producers whose last pricing is older than 12 months,
     * limited to producers that have entries in pricings_tires (used by stock engines).
     *
     * @return array<int, array{producer: string, last_born: string|null}>
     */
    public function getOutdatedPricingProducers(): array
    {
        $pdo = Bootstrap::pdo();
        $cutoff = date('Y-m-d', strtotime('-12 months'));

        $stmt = $pdo->prepare("
            SELECT
                pp.producer,
                MAX(p.born) AS last_born
            FROM
                products_producers pp
            INNER JOIN
                tires t ON t.id_product_producer = pp.id
            INNER JOIN
                pricings_tires pt ON pt.tire_id = t.id
            LEFT JOIN
                pricings p ON p.producer_name = pp.producer AND p.visible = 1
            WHERE
                pp.id_product_category = 1
            GROUP BY
                pp.producer
            HAVING
                last_born IS NULL OR last_born < :cutoff
            ORDER BY
                last_born ASC, pp.producer ASC
        ");
        $stmt->execute(['cutoff' => $cutoff]);

        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    public static function slug(string $text): string
    {
        $text = mb_strtolower($text, 'UTF-8');
        $text = (string) iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);
        $text = (string) preg_replace('/[^a-z0-9]+/', '-', $text);

        return trim($text, '-');
    }
}
