<?php

declare(strict_types=1);

namespace App\Domain\Tire;

/**
 * Single source of truth for classification kind ordering per vehicle type.
 *
 * Defines which dictionary kinds apply to each vehicle type (1–10) and the
 * order in which their values are classified and stored into
 * `tires_classified_parameters`.
 *
 * ┌─────────────────────────────────────────────────────────────────────────┐
 * │ How this differs from VehicleTypeSuffixOrder                           │
 * ├─────────────────────────────────────────────────────────────────────────┤
 * │ VehicleTypeSuffixOrder                                                 │
 * │   Controls which kinds appear in the generated product name suffixes.  │
 * │   Only kinds that contribute visible text to the product name are      │
 * │   listed there.                                                        │
 * │                                                                        │
 * │ VehicleTypeClassificationOrder (this class)                            │
 * │   Controls which kinds are classified/stored from tires.other tokens   │
 * │   into tires_classified_parameters. This is a SUPERSET of the suffix   │
 * │   order — it includes every suffix kind plus additional data-only      │
 * │   kinds that are stored for downstream use but never rendered in the   │
 * │   product name.                                                        │
 * │                                                                        │
 * │   Extra kinds added beyond suffix order:                               │
 * │     • tube_type        — added to types 1, 2, 3, 9 (already present   │
 * │                          in types 4–8, 10 for suffix generation)       │
 * │     • tire_technology  — added to types 1, 2, 3, 7, 8, 9, 10          │
 * │                          (already present in 4, 5, 6 for suffixes)     │
 * │     • country          — added to ALL types 1–10 (never shown in name)│
 * └─────────────────────────────────────────────────────────────────────────┘
 *
 * This class is consumed by:
 *   - TireParametersBuilder — to know the full set of kinds to classify
 *     tokens into (broader than what SuffixExtractor needs)
 *
 * No other class should define or duplicate these arrays.
 */
final class VehicleTypeClassificationOrder
{
    /**
     * Ordered classification kinds per vehicle type.
     *
     * Each entry starts with the same kinds as {@see VehicleTypeSuffixOrder}
     * in the same order, followed by classification-only kinds that are
     * stored but not rendered in the product name.
     *
     * @var array<int, string[]>
     */
    private const array ORDER = [
        1  => [
            'reinforcement', 'runflat', 'rim_protector', 'homologation', 'seal', 'silent',
            'season', 'white_wall', 'ply_rating', 'lt_designation', 'line_code', 'ev', 'studded',
            'tube_type',        // classification-only: not in suffix order for this type
            'tire_technology',  // classification-only: not in suffix order for this type
            'country',          // classification-only: not in suffix order for this type
        ],
        2  => [
            'reinforcement', 'runflat', 'rim_protector', 'homologation', 'seal', 'silent',
            'season', 'ply_rating', 'line_code',
            'tube_type',        // classification-only: not in suffix order for this type
            'tire_technology',  // classification-only: not in suffix order for this type
            'country',          // classification-only: not in suffix order for this type
        ],
        3  => [
            'reinforcement', 'runflat', 'rim_protector', 'homologation', 'seal', 'silent',
            'season', 'white_wall', 'ply_rating', 'lt_designation', 'line_code', 'ev',
            'tube_type',        // classification-only: not in suffix order for this type
            'tire_technology',  // classification-only: not in suffix order for this type
            'country',          // classification-only: not in suffix order for this type
        ],
        4  => [
            'tube_type', 'ply_rating', 'season', 'reinforcement', 'tire_technology',
            'highway_service', 'heavy_duty',
            'country',    // classification-only: not in suffix order for this type
        ],
        5  => [
            'tube_type', 'ply_rating', 'tire_technology', 'reinforcement',
            'highway_service', 'heavy_duty', 'studded',
            'country',    // classification-only: not in suffix order for this type
        ],
        6  => [
            'mounting_type', 'tube_type', 'ply_rating', 'tread_pattern', 'tire_color',
            'side_position', 'tire_technology', 'highway_service', 'heavy_duty',
            'country',    // classification-only: not in suffix order for this type
        ],
        7  => [
            'tube_type', 'reinforcement', 'season', 'white_wall', 'highway_service',
            'competition', 'studded',
            'tire_technology',  // classification-only: not in suffix order for this type
            'country',          // classification-only: not in suffix order for this type
        ],
        8  => [
            'ply_rating', 'tube_type', 'highway_service',
            'tire_technology',  // classification-only: not in suffix order for this type
            'country',          // classification-only: not in suffix order for this type
        ],
        9  => [
            'compound_code', 'compound',
            'tube_type',        // classification-only: not in suffix order for this type
            'tire_technology',  // classification-only: not in suffix order for this type
            'country',          // classification-only: not in suffix order for this type
        ],
        10 => [
            'tube_type', 'reinforcement', 'white_wall', 'season', 'highway_service',
            'tire_technology',  // classification-only: not in suffix order for this type
            'country',          // classification-only: not in suffix order for this type
        ],
    ];

    /**
     * Get the ordered classification kinds for a given vehicle type.
     *
     * Returns the full set of kinds that should be classified from
     * tires.other tokens, including both suffix kinds and data-only kinds.
     *
     * @param int $vehicleType Vehicle type ID (1–10)
     *
     * @return string[] Ordered array of dictionary kind names, empty if the type is unknown
     */
    public static function forVehicleType(int $vehicleType): array
    {
        return self::ORDER[$vehicleType] ?? [];
    }

    /**
     * Check whether a vehicle type has a defined classification order.
     *
     * @param int $vehicleType Vehicle type ID
     */
    public static function isSupported(int $vehicleType): bool
    {
        return isset(self::ORDER[$vehicleType]);
    }

    /**
     * Get all supported vehicle type IDs.
     *
     * @return int[] Vehicle type IDs (1–10)
     */
    public static function supportedTypes(): array
    {
        return array_keys(self::ORDER);
    }

    /**
     * Check whether a given kind is present in the classification order for a vehicle type.
     *
     * This includes both suffix kinds and classification-only kinds like
     * `country` and `tube_type` (for passenger types).
     *
     * @param int    $vehicleType Vehicle type ID
     * @param string $kind        Dictionary kind name
     */
    public static function hasKind(int $vehicleType, string $kind): bool
    {
        return \in_array($kind, self::ORDER[$vehicleType] ?? [], true);
    }

    /**
     * Get all unique kinds used across all vehicle types for classification.
     *
     * This is a superset of {@see VehicleTypeSuffixOrder::allKinds()} — it
     * includes every suffix kind plus classification-only kinds like `country`.
     *
     * Useful for validation and reporting — e.g. ensuring the dictionary
     * covers every kind that appears in any classification order.
     *
     * @return string[] Unique kind names (unordered)
     */
    public static function allKinds(): array
    {
        $kinds = [];

        foreach (self::ORDER as $order) {
            foreach ($order as $kind) {
                $kinds[$kind] = true;
            }
        }

        return array_keys($kinds);
    }
}
