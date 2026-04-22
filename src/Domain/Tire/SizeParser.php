<?php

declare(strict_types=1);

namespace App\Domain\Tire;

final class SizeParser
{
    /**
     * Parses tire size string into components.
     * Handles: 145/70R13, 205/55R16, 195R14C, 185/60R15 88H XL
     *
     * @return array{width: string, profile: string, construction: string, diameter: string}|null
     */
    public static function parseSize(string $size): ?array
    {
        $size = strtoupper(trim($size));

        // Standard: 145/70R13 or 145/70R13C
        if (preg_match('/^(\d+)\/(\d+)([A-Z]+)(\d+)/', $size, $m)) {
            return [
                'width'        => $m[1],
                'profile'      => $m[2],
                'construction' => $m[3] . ' ' . $m[4],
                'diameter'     => $m[4],
            ];
        }

        // Without profile: 195R14 or 195R14C
        if (preg_match('/^(\d+)([A-Z]+)(\d+)/', $size, $m)) {
            return [
                'width'        => $m[1],
                'profile'      => '0',
                'construction' => $m[2] . ' ' . $m[3],
                'diameter'     => $m[3],
            ];
        }

        return null;
    }

    /**
     * Parses tire index string into load index and speed index.
     * Handles: 71T, 91/89H, 80Q, 100/98R
     *
     * @return array{li: string, li2: string, si: string}|null
     */
    public static function parseIndices(string $indices): ?array
    {
        $indices = strtoupper(trim($indices));

        if ($indices === '') {
            return null;
        }

        // Dual load index: 91/89H
        if (preg_match('/^(\d+)\/(\d+)([A-Z]+)$/', $indices, $m)) {
            return ['li' => $m[1], 'li2' => $m[2], 'si' => $m[3]];
        }

        // Single: 71T or 100R
        if (preg_match('/^(\d+)([A-Z]+)$/', $indices, $m)) {
            return ['li' => $m[1], 'li2' => '', 'si' => $m[2]];
        }

        return null;
    }
}
