<?php

declare(strict_types=1);

namespace App\Domain\Tire;

/**
 * Helper class for tire reinforcement logic.
 *
 * Provides constants and methods for working with tire reinforcement codes
 * (C, CP, RF, XL) that are shared across multiple tire domain classes.
 */
final class ReinforcementHelper
{
    /**
     * Reinforcement codes ordered from weakest to strongest.
     * Used to determine priority when multiple reinforcements are present.
     */
    public const PRIORITY = ['C', 'CP', 'RF', 'XL'];

    /**
     * Reinforcement codes that are embedded in the tire size string.
     * Example: "195/70R15C" - the C is part of the size, not a suffix.
     */
    public const SIZE_EMBEDDED = ['C', 'CP'];

    /**
     * Pick the strongest reinforcement from an array of codes.
     *
     * When multiple reinforcement codes are present, returns the one with
     * highest priority according to PRIORITY constant (XL > RF > CP > C).
     * If no valid codes are found, returns the first code or empty string.
     *
     * @param string[] $codes Array of reinforcement code strings
     *
     * @return string The strongest reinforcement code, or empty string
     */
    public static function pickStrongest(array $codes): string
    {
        if (empty($codes)) {
            return '';
        }

        // Fast path: single code
        if (1 === \count($codes)) {
            return trim($codes[0]);
        }

        // Build priority lookup map
        $priorityMap = array_flip(self::PRIORITY);
        $best = null;
        $bestPriority = -1;

        foreach ($codes as $code) {
            $code = trim($code);
            if ('' === $code) {
                continue;
            }

            $priority = $priorityMap[$code] ?? -1;
            if ($priority > $bestPriority) {
                $best = $code;
                $bestPriority = $priority;
            }
        }

        return $best ?? trim($codes[0]);
    }

    /**
     * Check if a reinforcement code should be embedded in the size string.
     *
     * @param string $code Reinforcement code (e.g., "C", "XL", "RF")
     *
     * @return bool True if the code should be embedded in size (C or CP)
     */
    public static function isEmbeddedInSize(string $code): bool
    {
        return \in_array($code, self::SIZE_EMBEDDED, true);
    }
}
