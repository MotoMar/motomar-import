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

    /** @return array<int, array{id: int, producer: string, slug: string}> */
    public function allProducers(): array
    {
        return $this->db->select('products_producers', ['id', 'producer', 'slug'], [
            'ORDER' => ['producer' => 'ASC'],
        ]);
    }

    public function producerByName(string $name): ?array
    {
        return $this->db->get('products_producers', ['id', 'producer', 'slug'], [
            'producer' => $name,
        ]) ?: null;
    }

    public function createProducer(string $name): array
    {
        $slug = self::slug($name);
        $this->db->insert('products_producers', [
            'producer' => $name,
            'slug'     => $slug,
        ]);
        $id = (int) $this->db->id();
        return ['id' => $id, 'producer' => $name, 'slug' => $slug];
    }

    // ------------------------------------------------------------------ treads

    /** @return array<int, array{id: int, tread: string, season_id: int|null}> */
    public function treadsByProducer(int $producerId): array
    {
        return $this->db->select('tires_treads', ['id', 'tread', 'season_id'], [
            'producer_id' => $producerId,
            'ORDER'       => ['tread' => 'ASC'],
        ]) ?? [];
    }

    public function treadById(int $id): ?array
    {
        return $this->db->get('tires_treads', ['id', 'tread', 'season_id', 'producer_id'], [
            'id' => $id,
        ]) ?: null;
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

    /** @return array<int, array{id: int, season: string}> */
    public function allSeasons(): array
    {
        try {
            $rows = $this->db->select('tires_seasons', ['id', 'season'], ['id' => [1, 2, 3]]) ?? [];

            if (empty($rows)) {
                return [];
            }

            $order = [1 => 0, 2 => 1, 3 => 2];
            usort($rows, fn($a, $b) => ($order[$a['id']] ?? 99) <=> ($order[$b['id']] ?? 99));

            return $rows;
        } catch (\Throwable $e) {
            error_log('TireRepository::allSeasons error: ' . $e->getMessage());
            return [];
        }
    }

    // ------------------------------------------------------------------ dimensions

    public function widthId(string $width): ?int
    {
        return $this->dimensionId('tires_width', 'width', $width)
            ?? $this->createDimension('tires_width', 'width', $width);
    }

    public function profileId(string $profile): ?int
    {
        return $this->dimensionId('tires_profile', 'profile', $profile)
            ?? $this->createDimension('tires_profile', 'profile', $profile);
    }

    public function constructionId(string $construction): ?int
    {
        return $this->dimensionId('tires_construction', 'construction', $construction)
            ?? $this->createDimension('tires_construction', 'construction', $construction);
    }

    public function loadIndexId(string $li): ?int
    {
        $result = $this->db->get('tires_li', 'id', ['code' => $li, 'ORDER' => ['id' => 'ASC']]);
        if ($result !== null) {
            return (int) $result;
        }

        $this->db->insert('tires_li', ['li' => $li, 'code' => $li, 'slug' => self::slug($li)]);
        return (int) $this->db->id();
    }

    public function speedIndexId(string $si): ?int
    {
        $result = $this->db->get('tires_si', 'id', ['code' => $si, 'ORDER' => ['id' => 'ASC']]);
        if ($result !== null) {
            return (int) $result;
        }

        $this->db->insert('tires_si', ['si' => $si, 'code' => $si, 'slug' => self::slug($si)]);
        return (int) $this->db->id();
    }

    // ------------------------------------------------------------------ markers

    public function markersByValues(array $values): array
    {
        if (empty($values)) {
            return [];
        }

        return $this->db->select('markers', ['marker', 'group_id'], ['marker' => $values]) ?: [];
    }

    // ------------------------------------------------------------------ tires

    public function tireByEan(string $ean): ?array
    {
        return $this->db->get('tires', ['id', 'id_tires_tread', 'id_tires_season'], ['ean' => $ean]) ?: null;
    }

    public function getProductById(int $productId): ?array
    {
        return $this->db->get('products', ['id', 'flag_extraoffer'], ['id' => $productId]) ?: null;
    }

    public function tireByRefAndProducer(string $ref, int $producerId): ?array
    {
        return $this->db->get('tires', ['id'], [
            'ref'               => $ref,
            'id_product_producer' => $producerId,
        ]) ?: null;
    }

    // ------------------------------------------------------------------ product updates

    public function updateProductPrice(int $productId, float $price): void
    {
        $this->db->update('products', ['price_catalog_netto' => $price], ['id' => $productId]);

        // Keep price groups in sync (discount = 0, price = ceiling of catalog price)
        $ceiledPrice = (float) ceil($price);
        $this->db->update(
            'products_price_groups',
            ['price_netto' => $ceiledPrice, 'discount' => 0],
            ['id_product' => $productId]
        );
    }

    public function updateProductCatalogPrice(int $productId, float $price): void
    {
        $this->db->update('products', ['price_catalog_netto' => $price], ['id' => $productId]);
    }

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
        ];

        $this->db->update('tires', $fields, ['id' => $tireId]);
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

        if ($current === null) {
            return;
        }

        $fields = [];

        // REF: update if not empty and different
        if ($ref !== '' && $current['ref'] !== $ref) {
            $fields['ref'] = $ref;
        }

        // REF2: update if not empty and different
        if ($ref2 !== '' && $current['ref2'] !== $ref2) {
            $fields['ref2'] = $ref2;
        }

        // Only write to DB if something actually changed
        if (!empty($fields)) {
            $this->db->update('tires', $fields, ['id' => $tireId]);
        }
    }

    public function updateProductName(int $productId, string $name): void
    {
        $slug = self::slug($name . '-' . $productId);
        $this->db->update('products', [
            'name'        => $name,
            'slug'        => $slug,
            'better_slug' => $slug,
        ], ['id' => $productId]);
    }

    public function updateProductNameAndSlug(int $productId, string $name, string $slug): void
    {
        $this->db->update('products', [
            'name'        => $name,
            'slug'        => $slug,
            'better_slug' => $slug,
        ], ['id' => $productId]);
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

        $liId = null;
        $siId = null;
        $li   = '';
        $si   = '';

        if ($row->indices !== '') {
            $idx = SizeParser::parseIndices($row->indices);

            if ($idx !== null) {
                $li   = $idx['li2'] !== '' ? $idx['li'] . '/' . $idx['li2'] : $idx['li'];
                $si   = $idx['si'];
                $liId = $this->loadIndexId($li);
                $siId = $this->speedIndexId($si);
            }
        }

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
            'id_product_category' => Bootstrap::config()['tire_category_id'],
            'slug'               => self::slug($data['name']),
            'better_slug'        => self::slug($data['name']),
            'seo'                => '',
            'enemy_counter'      => 0,
            'ceneo_by_place'     => 0,
            'ceneo_by_price'     => 0,
            'ceneo_id'           => '',
            'skapiec_id'         => '',
            'ceneo'              => '',
        ]);

        $productId = (int) $this->db->id();

        $weight = $this->weightByDimensions(
            (int) $data['width_id'],
            (int) $data['construction_id'],
            (int) $data['profile_id'],
            (int) $data['vehicle_type_id']
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
            'tire_producer_slug'    => self::slug($data['producer_name']),
            'tire_model'            => $data['model_name'],
            'tire_model_slug'       => self::slug($data['model_name']),
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
            'eprel_id'              => $data['eprel_id'] !== null && $data['eprel_id'] !== '' ? (int) $data['eprel_id'] : null,
            'other'                 => $data['other'] ?? null,
            'additional_size'       => $data['additional_size'] ?? '',
            'additional_indexes'    => $data['additional_indexes'] ?? '',
            'id_tires_purpose'      => 35,
            'id_tires_marker'       => 1,
        ]);

        // Create tires_classified_parameters entry with classified parameters
        $classified = $this->classifyTireParameters($productId, $data);
        $this->db->insert('tires_classified_parameters', [
            'id_tire'    => $productId,
            'parameters' => TireParametersBuilder::toJson($classified),
        ]);

        // Create price group entries
        $this->createPriceGroups($productId, $data['price']);

        return $productId;
    }

    // ------------------------------------------------------------------ private

    private function getDictionaryMatcher(): DictionaryMatcher
    {
        if (self::$dictionaryMatcher === null) {
            self::$dictionaryMatcher = new DictionaryMatcher(Bootstrap::pdo());
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
        $groups      = $this->db->select('price_groups', ['id'], []);
        $ceiledPrice = (float) ceil($price);

        foreach ($groups as $group) {
            $this->db->insert('products_price_groups', [
                'id_product'    => $productId,
                'id_price_group' => $group['id'],
                'price_netto'   => $ceiledPrice,
                'discount'      => 0,
            ]);
        }
    }

    public static function slug(string $text): string
    {
        $text = mb_strtolower($text, 'UTF-8');
        $text = (string) iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);
        $text = (string) preg_replace('/[^a-z0-9]+/', '-', $text);

        return trim($text, '-');
    }
}
