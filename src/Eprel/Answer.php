<?php

declare(strict_types=1);

namespace App\Eprel;

/**
 * Odpowiedź API na jeden numer rejestracji, zanim ktokolwiek ją zinterpretuje.
 *
 * Żądanie, które nie doszło, ma status 0 i powód w `$error` — nie puste ciało,
 * którego nie dałoby się odróżnić od serwera, który odpowiedział niczym.
 */
final readonly class Answer
{
    public function __construct(
        public int $status,
        public string $body,
        public string $error = '',
    ) {
    }

    /**
     * Czy EPREL coś rozstrzygnął: rejestracja (200 z JSON-em), jej brak (404) albo
     * odmowa z nazwanym powodem (`UNVERIFIED_SUPPLIER`, `BLOCKED_MODEL`).
     *
     * Gołe 403 bez kodu to nasz brakujący `Referer`, nie decyzja EPREL-u; 429 i 5xx
     * to limit albo awaria. Tylko rozstrzygnięte odpowiedzi trafiają do cache'u.
     */
    public function isDecisive(): bool
    {
        return (200 === $this->status && null !== $this->json())
            || 404 === $this->status
            || ($this->status >= 400 && $this->status < 500 && 429 !== $this->status && '' !== $this->apiCode());
    }

    /**
     * Czy ponowienie ma sens: żądanie nie doszło, limit (429) albo awaria (5xx).
     *
     * Gołe 403 nie: to odmowa (brak nagłówka, blokada IP), a ponawianie jej
     * w pętli kończy się blokadą — 2026-10-14 tak zablokowaliśmy sobie EPREL.
     */
    public function isRetryable(): bool
    {
        return 0 === $this->status || 429 === $this->status || $this->status >= 500;
    }

    /** @return array<mixed>|null */
    public function json(): ?array
    {
        try {
            $decoded = json_decode($this->body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }

    public function apiCode(): string
    {
        $code = $this->json()['code'] ?? null;

        return is_string($code) ? $code : '';
    }
}
