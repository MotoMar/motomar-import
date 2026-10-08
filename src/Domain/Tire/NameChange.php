<?php

declare(strict_types=1);

namespace App\Domain\Tire;

/**
 * What regenerating one product would change, before anything is written.
 */
final class NameChange
{
    /**
     * @param array<string, string[]>      $parameters   classification the name was built from
     * @param array<string, string[]>|null $reclassified fresh classification to store, or null when it is unchanged or was not recomputed
     */
    public function __construct(
        public readonly int $tireId,
        public readonly string $oldName,
        public readonly string $newName,
        public readonly string $oldSlug,
        public readonly string $newSlug,
        public readonly string $oldModel,
        public readonly string $newModel,
        public readonly array $parameters,
        public readonly ?array $reclassified,
    ) {
    }

    public function nameChanged(): bool
    {
        return $this->oldName !== $this->newName || $this->oldSlug !== $this->newSlug;
    }

    public function modelChanged(): bool
    {
        return $this->oldModel !== $this->newModel;
    }

    public function isNoop(): bool
    {
        return !$this->nameChanged() && !$this->modelChanged() && null === $this->reclassified;
    }
}
