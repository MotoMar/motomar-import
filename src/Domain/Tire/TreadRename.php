<?php

declare(strict_types=1);

namespace App\Domain\Tire;

/**
 * Zmiana nazwy istniejącego bieżnika zamówiona w kroku 2 importu.
 */
final class TreadRename
{
    public function __construct(
        public readonly int $treadId,
        public readonly int $producerId,
        public readonly string $oldName,
        public readonly string $newName,
    ) {
    }

    /** @return array{tread_id: int, producer_id: int, old: string, new: string} */
    public function toArray(): array
    {
        return [
            'tread_id'    => $this->treadId,
            'producer_id' => $this->producerId,
            'old'         => $this->oldName,
            'new'         => $this->newName,
        ];
    }

    /** @param array<string, mixed> $row */
    public static function fromArray(array $row): self
    {
        return new self(
            RowField::integer($row, 'tread_id'),
            RowField::integer($row, 'producer_id'),
            RowField::text($row, 'old'),
            RowField::text($row, 'new'),
        );
    }
}
