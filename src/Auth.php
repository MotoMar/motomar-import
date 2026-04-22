<?php

declare(strict_types=1);

namespace App;

final class Auth
{
    private const SESSION_KEY_ID    = 'ti_auth_id';
    private const SESSION_KEY_EMAIL = 'ti_auth_email';

    public static function check(): bool
    {
        return isset($_SESSION[self::SESSION_KEY_ID]);
    }

    public static function id(): ?int
    {
        return isset($_SESSION[self::SESSION_KEY_ID]) ? (int) $_SESSION[self::SESSION_KEY_ID] : null;
    }

    public static function email(): ?string
    {
        return $_SESSION[self::SESSION_KEY_EMAIL] ?? null;
    }

    public static function login(int $id, string $email): void
    {
        session_regenerate_id(true);
        $_SESSION[self::SESSION_KEY_ID]    = $id;
        $_SESSION[self::SESSION_KEY_EMAIL] = $email;
    }

    public static function logout(): void
    {
        unset($_SESSION[self::SESSION_KEY_ID], $_SESSION[self::SESSION_KEY_EMAIL]);
    }
}
