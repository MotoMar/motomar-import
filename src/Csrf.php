<?php

declare(strict_types=1);

namespace App;

final class Csrf
{
    private const SESSION_KEY = '_csrf_token';

    public static function token(): string
    {
        $token = $_SESSION[self::SESSION_KEY] ?? null;

        if (!is_string($token) || $token === '') {
            $token = bin2hex(random_bytes(32));
            $_SESSION[self::SESSION_KEY] = $token;
        }

        return $token;
    }

    public static function validate(string $token): bool
    {
        $stored = $_SESSION[self::SESSION_KEY] ?? null;

        return is_string($stored) && $stored !== '' && hash_equals($stored, $token);
    }

    public static function field(): string
    {
        return sprintf(
            '<input type="hidden" name="_csrf" value="%s">',
            htmlspecialchars(self::token(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
        );
    }
}
