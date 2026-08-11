<?php

declare(strict_types=1);

namespace App\Domain\Tire;

/**
 * Reads a single field out of a database row.
 *
 * Rows arrive from PDO as `array<string, mixed>` — every column is a string,
 * a number or null, but nothing in the type system says so. Casting `mixed`
 * straight to `string` is exactly the kind of guess static analysis is there to
 * stop, and an array shape would be a lie: the same row travels through code
 * that adds and drops keys.
 *
 * So the guess is made once, here, where it is visible: anything scalar becomes
 * the value, anything else becomes the empty default.
 */
final class RowField
{
    /**
     * @param array<string, mixed> $row
     */
    public static function text(array $row, string $key): string
    {
        $value = $row[$key] ?? null;

        return is_scalar($value) ? trim((string) $value) : '';
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function integer(array $row, string $key): int
    {
        $value = $row[$key] ?? null;

        return is_numeric($value) ? (int) $value : 0;
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function decimal(array $row, string $key): float
    {
        $value = $row[$key] ?? null;

        return is_numeric($value) ? (float) $value : 0.0;
    }

    /**
     * Reads a column that may legitimately hold NULL, such as a LEFT JOIN.
     *
     * @param array<string, mixed> $row
     */
    public static function nullableText(array $row, string $key): ?string
    {
        $value = $row[$key] ?? null;

        return is_scalar($value) ? (string) $value : null;
    }
}
