<?php

declare(strict_types=1);

namespace App;

final class Csrf
{
    private const SESSION_KEY = '_csrf_token';

    public static function token(): string
    {
        if (empty($_SESSION[self::SESSION_KEY])) {
            $_SESSION[self::SESSION_KEY] = bin2hex(random_bytes(32));
        }

        return $_SESSION[self::SESSION_KEY];
    }

    public static function validate(string $token): bool
    {
        $stored = $_SESSION[self::SESSION_KEY] ?? '';

        return $stored !== '' && hash_equals($stored, $token);
    }

    public static function field(): string
    {
        return sprintf(
            '<input type="hidden" name="_csrf" value="%s">',
            htmlspecialchars(self::token(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
        );
    }
}
