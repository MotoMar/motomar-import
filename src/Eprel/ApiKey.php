<?php

declare(strict_types=1);

namespace App\Eprel;

/**
 * Klucz API EPREL (`EPREL_API_KEY`) ze zmiennej środowiskowej albo z `.env`.
 *
 * Potrzebny tylko do eksportu dziennego; pobieranie po numerze rejestracji
 * jest otwarte.
 */
final class ApiKey
{
    public static function get(string $envFile): string
    {
        $fromEnv = getenv('EPREL_API_KEY');

        if (is_string($fromEnv) && '' !== trim($fromEnv)) {
            return trim($fromEnv);
        }

        $contents = is_file($envFile) ? (string) file_get_contents($envFile) : '';

        if (1 === preg_match('/^\s*(?:export\s+)?EPREL_API_KEY\s*=\s*["\']?([^"\'\s#]+)/m', $contents, $m)) {
            return $m[1];
        }

        throw new \RuntimeException('Brak EPREL_API_KEY — ustaw zmienną środowiskową albo dopisz ją do .env. Klucz jest potrzebny tylko do eksportu dziennego.');
    }
}
