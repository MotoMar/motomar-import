<?php

declare(strict_types=1);

namespace App\Domain\Tire;

/**
 * Single source of truth for suffix kind ordering per vehicle type.
 *
 * Defines which dictionary kinds apply to each vehicle type (1–10) and the
 * order in which their values appear in the product name suffix chain.
 *
 * This class is consumed by:
 *   - TireParametersBuilder — to know which kinds to classify tokens into
 *   - SuffixExtractor       — to know the display order for name suffixes
 *
 * No other class should define or duplicate these arrays.
 *
 * The ordering is meaningful:
 *   - For passenger-group types (1, 2, 3): reinforcement comes first, then
 *     runflat, rim protector, homologation, etc.
 *   - For truck/agri/industrial types (4–6): tube_type and ply_rating lead.
 *   - For motorcycle/quad/scooter (7, 8, 10): tube_type leads.
 *   - For gokart (9): compound_code and compound are the only suffixes.
 */
final class VehicleTypeSuffixOrder
{
    /**
     * Ordered suffix kinds per vehicle type.
     *
     * Each entry is an array of dictionary kind names in the order they
     * should appear in the final product name.
     *
     * @var array<int, string[]>
     */
    private const ORDER = [
        1  => ['reinforcement', 'runflat', 'rim_protector', 'homologation', 'seal', 'silent', 'season', 'white_wall', 'ply_rating', 'lt_designation', 'line_code', 'ev', 'studded'],
        2  => ['reinforcement', 'runflat', 'rim_protector', 'homologation', 'seal', 'silent', 'season', 'ply_rating', 'line_code'],
        3  => ['reinforcement', 'runflat', 'rim_protector', 'homologation', 'seal', 'silent', 'season', 'white_wall', 'ply_rating', 'lt_designation', 'line_code', 'ev'],
        4  => ['tube_type', 'ply_rating', 'season', 'reinforcement', 'tire_technology', 'highway_service', 'heavy_duty'],
        5  => ['tube_type', 'ply_rating', 'tire_technology', 'reinforcement', 'highway_service', 'heavy_duty', 'studded'],
        6  => ['mounting_type', 'tube_type', 'ply_rating', 'tread_pattern', 'tire_color', 'side_position', 'tire_technology', 'highway_service', 'heavy_duty'],
        7  => ['tube_type', 'reinforcement', 'season', 'white_wall', 'highway_service', 'competition', 'studded'],
        8  => ['ply_rating', 'tube_type', 'highway_service'],
        9  => ['compound_code', 'compound'],
        10 => ['tube_type', 'reinforcement', 'white_wall', 'season', 'highway_service'],
    ];

    /**
     * Get the ordered suffix kinds for a given vehicle type.
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
     * Check whether a vehicle type has a defined suffix order.
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
     * Check whether a given kind is present in the suffix order for a vehicle type.
     *
     * @param int    $vehicleType Vehicle type ID
     * @param string $kind        Dictionary kind name
     */
    public static function hasKind(int $vehicleType, string $kind): bool
    {
        return \in_array($kind, self::ORDER[$vehicleType] ?? [], true);
    }

    /**
     * Get all unique kinds used across all vehicle types.
     *
     * Useful for validation and reporting — e.g. ensuring the dictionary
     * covers every kind that appears in any suffix order.
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
