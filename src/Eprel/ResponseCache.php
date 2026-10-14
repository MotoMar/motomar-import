<?php

declare(strict_types=1);

namespace App\Eprel;

/**
 * Rozstrzygnięte odpowiedzi API na dysku, po jednym pliku na numer.
 *
 * Pełny przebieg Enisa pyta o ~44 tys. numerów dwa razy (przed poprawkami i na
 * końcu), a API ma limit 50 tys. żądań na 5 minut: dwa przebiegi pod rząd dały
 * 2026-10-14 14 200 błędów pobrania. Rejestracja zmienia się rzadko, więc
 * odpowiedź sprzed kilku dni jest dobra; starsza jest pobierana od nowa.
 */
final readonly class ResponseCache
{
    public function __construct(
        private string $directory,
        private int $maxAgeSeconds,
    ) {
    }

    public function get(int $number): ?Answer
    {
        $path = $this->path($number);

        if (!is_file($path) || filemtime($path) < time() - $this->maxAgeSeconds) {
            return null;
        }

        $stored = json_decode((string) file_get_contents($path), true);

        if (!is_array($stored) || !is_int($stored['status'] ?? null) || !is_string($stored['body'] ?? null)) {
            return null;
        }

        return new Answer($stored['status'], $stored['body']);
    }

    public function put(int $number, Answer $answer): void
    {
        if (!$answer->isDecisive()) {
            return;
        }

        $path = $this->path($number);
        $directory = dirname($path);

        if (!is_dir($directory) && !mkdir($directory, 0o775, true) && !is_dir($directory)) {
            throw new \RuntimeException("Nie mogę utworzyć katalogu: {$directory}");
        }

        file_put_contents($path, json_encode(['status' => $answer->status, 'body' => $answer->body], JSON_THROW_ON_ERROR));
    }

    /** Po tysiąc plików w katalogu, nie 44 tysiące w jednym. */
    private function path(int $number): string
    {
        return sprintf('%s/%03d/%d.json', $this->directory, $number % 1000, $number);
    }
}
