<?php

declare(strict_types=1);

namespace App\Domain\Tire;

/**
 * Formats Load Index (LI) and Speed Index (SI) values into a combined string.
 *
 * Handles dual-value LI and/or SI (values containing '/') according to tire naming conventions:
 * - Both dual:    "116/114" + "R/S"  → "116R/114S"
 * - Only LI dual: "121/118" + "S"    → "121/118S"
 * - Only SI dual: "91"      + "H/V"  → "91H/V"
 * - Both single:  "91"      + "H"    → "91H"
 */
class LiSiFormatter
{
    /**
     * Format LI and SI into a combined string.
     *
     * @param string $li Load index value, e.g. "91" or "121/118"
     * @param string $si Speed index value, e.g. "H" or "R/S"
     *
     * @return string Formatted LI/SI string, or empty string if either is empty
     */
    public static function format(string $li, string $si): string
    {
        $li = trim($li);
        $si = trim($si);

        if ('' === $si || '' === $li) {
            return '';
        }

        $liParts = (false !== strpos($li, '/')) ? explode('/', $li) : null;
        $siParts = (false !== strpos($si, '/')) ? explode('/', $si) : null;

        // Both LI and SI have dual values: "116R/114S"
        if (null !== $liParts && null !== $siParts) {
            return "{$liParts[0]}{$siParts[0]}/{$liParts[1]}{$siParts[1]}";
        }

        // Only LI has dual values: "121/118S"
        if (null !== $liParts && null === $siParts) {
            return "{$liParts[0]}/{$liParts[1]}{$si}";
        }

        // Only SI has dual values: "91H/V"
        if (null === $liParts && null !== $siParts) {
            return "{$li}{$siParts[0]}/{$siParts[1]}";
        }

        // Both single values: "91H"
        return "{$li}{$si}";
    }
}
