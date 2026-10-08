<?php

declare(strict_types=1);

namespace App\Domain\Tire;

/**
 * Which tires a bulk operation touches: listed tires, everything on some treads
 * or producers, or the whole table.
 *
 * Kept apart from the query so the one decision that matters — that "all" is
 * never what an empty list means — is made in one place and tested.
 */
final class TireSelection
{
    /**
     * @param list<int> $ids
     */
    private function __construct(
        private readonly ?string $column,
        private readonly array $ids,
        private readonly string $label,
    ) {
    }

    /** @param list<int> $ids */
    public static function tires(array $ids): self
    {
        return new self('t.id', self::nonEmpty($ids, 'opon'), 'opony ' . implode(',', $ids));
    }

    /** @param list<int> $ids */
    public static function treads(array $ids): self
    {
        return new self('t.id_tires_tread', self::nonEmpty($ids, 'bieżników'), 'bieżniki ' . implode(',', $ids));
    }

    /** @param list<int> $ids */
    public static function producers(array $ids): self
    {
        return new self('t.id_product_producer', self::nonEmpty($ids, 'producentów'), 'producenci ' . implode(',', $ids));
    }

    public static function all(): self
    {
        return new self(null, [], 'wszystkie opony');
    }

    /**
     * From CLI options: exactly one of --tire, --tread, --producer (comma
     * separated ids) or --all.
     *
     * @param array<string, mixed> $options as returned by getopt()
     */
    public static function fromOptions(array $options): self
    {
        $given = array_values(array_filter(
            ['tire', 'tread', 'producer', 'all'],
            static fn (string $key): bool => array_key_exists($key, $options),
        ));

        if (1 !== count($given)) {
            throw new \InvalidArgumentException('Podaj dokładnie jedno z: --tire, --tread, --producer, --all.');
        }

        if ('all' === $given[0]) {
            return self::all();
        }

        $raw = $options[$given[0]];

        if (!is_string($raw) || 1 !== preg_match('/^\d+(,\d+)*$/', $raw)) {
            throw new \InvalidArgumentException("--{$given[0]} przyjmuje id po przecinku, np. --{$given[0]}=12,34.");
        }

        $ids = array_map('intval', explode(',', $raw));

        return match ($given[0]) {
            'tire'     => self::tires($ids),
            'tread'    => self::treads($ids),
            'producer' => self::producers($ids),
        };
    }

    public function isAll(): bool
    {
        return null === $this->column;
    }

    public function label(): string
    {
        return $this->label;
    }

    /**
     * The WHERE fragment and its parameters. Ids are bound, never interpolated.
     *
     * @return array{0: string, 1: array<string, int>}
     */
    public function where(): array
    {
        if (null === $this->column) {
            return ['1 = 1', []];
        }

        $params = [];

        foreach ($this->ids as $i => $id) {
            $params[":sel{$i}"] = $id;
        }

        return [$this->column . ' IN (' . implode(', ', array_keys($params)) . ')', $params];
    }

    /**
     * @param list<int> $ids
     *
     * @return list<int>
     */
    private static function nonEmpty(array $ids, string $what): array
    {
        $ids = array_values(array_unique(array_filter($ids, static fn (int $id): bool => $id > 0)));

        if ([] === $ids) {
            throw new \InvalidArgumentException("Pusta lista {$what} — całość wybiera się wprost, przez all().");
        }

        return $ids;
    }
}
