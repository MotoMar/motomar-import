<?php

declare(strict_types=1);

namespace App\Domain\Tire;

final class SizeParser
{
    /**
     * Parses tire size string into components.
     *
     * Supported formats (ordered by match priority):
     *  1. Metric slash + letter:  205/55R16, 205/65R17.5, 130/90B16, 195/65R420, 145/70R13C
     *  2. Metric slash + dash:    100/90-19, 13.6/12-38, 2.75/3.75-18, 4.00/4.80-8
     *  3. X + separator (R/-):    31x10.50R15, 1300x530-533, 16x6.50-8, 25x10.00R12
     *  4. X without rim:          26x2.00, 6x1.25 (bicycle / small wheels)
     *  5. Alpha moto + separator: MH90-21, MT90B16, MU85B16
     *  6. Alpha moto no sep:      MT9016
     *  7. Load range L/LR:        11L-14, 11LR16, 14.9 LR20, 21.5L-16.1
     *  8. Int width + letter:     195R14, 195R14C, 18R22.5
     *  9. Decimal width + letter: 7.50R16, 8.25R16, 9.5R17.5
     * 10. Diagonal (width-diam):  11.2-24, 6.00-16, 10-15.3, 14.9-38
     *
     * @return array{width: string, profile: string, construction: string, diameter: string}|null
     */
    public static function parseSize(string $size): ?array
    {
        $size = strtoupper(trim($size));

        // 1. Metric with slash + letter construction: 205/55R16, 205/65R17.5, 130/90B16, 195/65R420
        if (preg_match('/^(\d+(?:\.\d+)?)\/(\d+(?:\.\d+)?)([A-Z]+)(\d+(?:\.\d+)?)/', $size, $m)) {
            return [
                'width'        => $m[1],
                'profile'      => $m[2],
                'construction' => $m[3] . ' ' . $m[4],
                'diameter'     => $m[4],
            ];
        }

        // 2. Metric with slash + dash: 100/90-19, 13.6/12-38, 2.75/3.75-18
        if (preg_match('/^(\d+(?:\.\d+)?)\/(\d+(?:\.\d+)?)-(\d+(?:\.\d+)?)/', $size, $m)) {
            return [
                'width'        => $m[1],
                'profile'      => $m[2],
                'construction' => '- ' . $m[3],
                'diameter'     => $m[3],
            ];
        }

        // 3. X-format with R/letter or dash: 31x10.50R15, 1300x530-533, 16x6.50-8, 25x10.00R12
        if (preg_match('/^(\d+(?:\.\d+)?)X(\d+(?:\.\d+)?)([A-Z]+|-)(\d+(?:\.\d+)?)/', $size, $m)) {
            return [
                'width'        => $m[1] . 'X' . $m[2],
                'profile'      => '',
                'construction' => $m[3] . ' ' . $m[4],
                'diameter'     => $m[4],
            ];
        }

        // 4. X-format without rim: 26x2.00, 6x1.25 (bicycle/small wheels)
        if (preg_match('/^(\d+(?:\.\d+)?)X(\d+(?:\.\d+)?)$/', $size, $m)) {
            return [
                'width'        => $m[1] . 'X' . $m[2],
                'profile'      => '',
                'construction' => '-',
                'diameter'     => '',
            ];
        }

        // 5. Alphanumeric moto with separator: MH90-21, MT90B16, MU85B16
        if (preg_match('/^([A-Z]{2})(\d+)([A-Z]|-)(\d+)/', $size, $m)) {
            return [
                'width'        => $m[1] . $m[2],
                'profile'      => '',
                'construction' => $m[3] . ' ' . $m[4],
                'diameter'     => $m[4],
            ];
        }

        // 6. Alphanumeric moto without separator: MT9016
        if (preg_match('/^([A-Z]{2})(\d{2})(\d{2})/', $size, $m)) {
            return [
                'width'        => $m[1] . $m[2],
                'profile'      => '',
                'construction' => '- ' . $m[3],
                'diameter'     => $m[3],
            ];
        }

        // 7. Load range: 11L-14, 11LR16, 14.9 LR20, 21.5L-16.1
        if (preg_match('/^(\d+(?:\.\d+)?)\s?(LR?)-?(\d+(?:\.\d+)?)/', $size, $m)) {
            return [
                'width'        => $m[1],
                'profile'      => '',
                'construction' => $m[2] . ' ' . $m[3],
                'diameter'     => $m[3],
            ];
        }

        // 8. Integer width + letters + diameter (no profile): 195R14, 195R14C, 18R22.5
        if (preg_match('/^(\d+)([A-Z]+)(\d+(?:\.\d+)?)/', $size, $m)) {
            return [
                'width'        => $m[1],
                'profile'      => '0',
                'construction' => $m[2] . ' ' . $m[3],
                'diameter'     => $m[3],
            ];
        }

        // 9. Decimal width + letters + diameter (no profile): 7.50R16, 8.25R16, 9.5R17.5
        if (preg_match('/^(\d+\.\d+)([A-Z]+)(\d+(?:\.\d+)?)/', $size, $m)) {
            return [
                'width'        => str_replace('.', '', $m[1]),
                'profile'      => '0',
                'construction' => $m[2] . ' ' . $m[3],
                'diameter'     => $m[3],
            ];
        }

        // 10. Diagonal (width-diameter): 11.2-24, 6.00-16, 10-15.3, 14.9-38
        if (preg_match('/^(\d+(?:\.\d+)?)-(\d+(?:\.\d+)?)/', $size, $m)) {
            return [
                'width'        => $m[1],
                'profile'      => '',
                'construction' => '- ' . $m[2],
                'diameter'     => $m[2],
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
