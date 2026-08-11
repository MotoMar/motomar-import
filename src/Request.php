<?php

declare(strict_types=1);

namespace App;

/**
 * Reads request input, which PHP hands over as `mixed`.
 *
 * Every controller was casting `$_POST['x']` on the spot, which is a guess made
 * in a dozen places. The guess belongs in one: a field that is not a string is
 * not a field we were sent, and an array parameter that is not an array is the
 * same.
 */
final class Request
{
    public static function post(string $key, string $default = ''): string
    {
        $value = $_POST[$key] ?? null;

        return is_string($value) ? $value : $default;
    }

    /**
     * @return array<string, mixed>
     */
    public static function postArray(string $key): array
    {
        $value = $_POST[$key] ?? null;

        if (!is_array($value)) {
            return [];
        }

        $typed = [];

        foreach ($value as $index => $item) {
            $typed[(string) $index] = $item;
        }

        return $typed;
    }

    public static function query(string $key, string $default = ''): string
    {
        $value = $_GET[$key] ?? null;

        return is_string($value) ? $value : $default;
    }

    public static function server(string $key): string
    {
        $value = $_SERVER[$key] ?? null;

        return is_string($value) ? $value : '';
    }

    /**
     * One entry of `$_FILES`, or an empty array when nothing was uploaded.
     *
     * @return array<string, mixed>
     */
    public static function file(string $key): array
    {
        $file = $_FILES[$key] ?? null;

        if (!is_array($file)) {
            return [];
        }

        $typed = [];

        foreach ($file as $index => $item) {
            $typed[(string) $index] = $item;
        }

        return $typed;
    }
}
