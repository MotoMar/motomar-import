<?php

declare(strict_types=1);

namespace App\Eprel;

/** Jedno pole, w którym cennik i rejestracja EPREL się nie zgadzają. */
final readonly class Mismatch
{
    public function __construct(
        public string $field,
        public string $ours,
        public string $theirs,
    ) {
    }

    /** `pole: nasze → ich` — ten zapis czyta `tools/enis/apply_eprel.py`. */
    public function describe(): string
    {
        return "{$this->field}: {$this->ours} → {$this->theirs}";
    }
}
