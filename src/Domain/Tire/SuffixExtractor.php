<?php

declare(strict_types=1);

namespace App\Domain\Tire;

/**
 * Extracts ordered suffix strings for tire product names from pre-classified parameters.
 *
 * This class is a pure formatting/ordering layer. It does NOT classify tokens,
 * read raw database columns, or touch the dictionary. All classification is done
 * upstream by {@see TireParametersBuilder}, which produces the canonical
 * `tires_classified_parameters.parameters` JSON.
 *
 * Input:  vehicle type ID + decoded classified parameters array (kind => codes[])
 * Output: flat ordered array of suffix strings for the product name
 *
 * The ordering per vehicle type is defined in {@see VehicleTypeSuffixOrder}.
 *
 * Filtering rules applied here are strictly name-formatting concerns:
 *   - C and CP reinforcements are excluded from suffixes because they are
 *     embedded in the size block (e.g. "195/70R15C") by {@see NameGenerator}.
 */
class SuffixExtractor
{
    /**
     * Reinforcement values embedded in the size block, not emitted as suffixes.
     *
     * These are handled by NameGenerator::resolveReinforcement() and appended
     * directly to the tire size string. Emitting them again as suffixes would
     * produce duplicates like "195/70R15C ... C".
     */
    private const SIZE_EMBEDDED_REINFORCEMENTS = ['C', 'CP'];

    /**
     * Extract ordered suffix strings for a tire product name.
     *
     * @param int                     $vehicleType          Vehicle type ID (1–10)
     * @param array<string, string[]> $classifiedParameters Decoded classified parameters
     *                                                      from TireParametersBuilder / tires_classified_parameters JSON.
     *                                                      Keyed by dictionary kind, values are arrays of code strings.
     *                                                      Example: ['reinforcement' => ['XL'], 'homologation' => ['MO', '*']]
     *
     * @return string[] Flat ordered array of suffix strings for the product name.
     *                  Empty array when the vehicle type is unsupported or no suffixes apply.
     */
    public function extractSuffixes(int $vehicleType, array $classifiedParameters): array
    {
        $order = VehicleTypeSuffixOrder::forVehicleType($vehicleType);

        if (empty($order) || empty($classifiedParameters)) {
            return [];
        }

        $result = [];

        foreach ($order as $kind) {
            $values = $classifiedParameters[$kind] ?? [];

            if (empty($values)) {
                continue;
            }

            foreach ($values as $value) {
                $value = trim((string) $value);

                if ('' === $value) {
                    continue;
                }

                // C and CP are part of the size block, not suffixes.
                if ('reinforcement' === $kind && \in_array($value, self::SIZE_EMBEDDED_REINFORCEMENTS, true)) {
                    continue;
                }

                $result[] = $value;
            }
        }

        return $result;
    }

    /**
     * Get the suffix order for a given vehicle type.
     *
     * Delegates to {@see VehicleTypeSuffixOrder::forVehicleType()}.
     * Kept for backward compatibility and convenience in tests/debugging.
     *
     * @param int $vehicleType Vehicle type ID (1–10)
     *
     * @return string[] Array of dictionary kind names in order, or empty array if unknown type
     */
    public function getSuffixOrder(int $vehicleType): array
    {
        return VehicleTypeSuffixOrder::forVehicleType($vehicleType);
    }

    /**
     * Get all supported vehicle type IDs.
     *
     * Delegates to {@see VehicleTypeSuffixOrder::supportedTypes()}.
     *
     * @return int[] Array of vehicle type IDs (1–10)
     */
    public function getSupportedVehicleTypes(): array
    {
        return VehicleTypeSuffixOrder::supportedTypes();
    }
}
