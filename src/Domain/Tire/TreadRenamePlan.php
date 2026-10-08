<?php

declare(strict_types=1);

namespace App\Domain\Tire;

/**
 * Sprawdza zamówione w kroku 2 zmiany nazw bieżników, zanim cokolwiek trafi
 * do bazy, i składa z nich listę do wykonania.
 *
 * Zmiana nazwy nie może zrobić drugiego bieżnika o tej samej nazwie — ani
 * dosłownie, ani po złożeniu (`Bravuris 3 HM` i `Bravuris 3HM` to jeden model,
 * takich par sprzątamy ponad sto: AKN-678). Przemianowanie na nazwę innego
 * bieżnika to scalenie, a nie zmiana nazwy, więc tu jest błędem.
 */
final class TreadRenamePlan
{
    /**
     * @param list<TreadRename> $renames
     * @param list<string>      $errors
     */
    private function __construct(
        public readonly array $renames,
        public readonly array $errors,
    ) {
    }

    /**
     * Nazwa złożona do porównań: małe litery, bez spacji i znaków. `+` zostaje,
     * bo znaczy inny model (`R168` i `R168+`).
     */
    public static function key(string $name): string
    {
        return (string) preg_replace('/[^\p{L}\p{N}+]+/u', '', mb_strtolower($name, 'UTF-8'));
    }

    public static function normaliseName(string $name): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $name));
    }

    /**
     * @param list<array{model: string, tread_id: int, producer_id: int, current: string, new: string}> $requests         jedno na model z CSV z zaznaczonym „nadpisz”
     * @param array<int, list<array{id: int, tread: string}>>                                            $treadsByProducer istniejące bieżniki producentów z $requests
     * @param array<int, list<string>>                                                                   $createdByProducer nazwy bieżników, które ten import założy
     */
    public static function build(array $requests, array $treadsByProducer, array $createdByProducer = []): self
    {
        $errors = [];
        /** @var array<int, TreadRename> $byTread */
        $byTread = [];

        foreach ($requests as $request) {
            $new = self::normaliseName($request['new']);
            $label = "„{$request['model']}”";

            if ('' === $new) {
                $errors[] = "{$label}: pusta nazwa bieżnika do nadpisania.";
                continue;
            }

            if ($new === $request['current']) {
                continue;
            }

            $earlier = $byTread[$request['tread_id']] ?? null;

            if (null !== $earlier) {
                if ($earlier->newName !== $new) {
                    $errors[] = "{$label}: bieżnik „{$request['current']}” ma już nadpisanie na „{$earlier->newName}” z innego modelu, a tu „{$new}”.";
                }
                continue;
            }

            foreach ($treadsByProducer[$request['producer_id']] ?? [] as $tread) {
                if ($tread['id'] !== $request['tread_id'] && self::key($tread['tread']) === self::key($new)) {
                    $errors[] = "{$label}: „{$new}” to już bieżnik #{$tread['id']} „{$tread['tread']}” — to scalenie, nie zmiana nazwy.";
                    continue 2;
                }
            }

            foreach ($createdByProducer[$request['producer_id']] ?? [] as $created) {
                if (self::key($created) === self::key($new)) {
                    $errors[] = "{$label}: „{$new}” zakłada też ten import jako nowy bieżnik „{$created}”.";
                    continue 2;
                }
            }

            foreach ($byTread as $other) {
                if ($other->producerId === $request['producer_id'] && self::key($other->newName) === self::key($new)) {
                    $errors[] = "{$label}: „{$new}” dostaje już bieżnik #{$other->treadId} „{$other->oldName}”.";
                    continue 2;
                }
            }

            $byTread[$request['tread_id']] = new TreadRename($request['tread_id'], $request['producer_id'], $request['current'], $new);
        }

        return new self([] === $errors ? array_values($byTread) : [], $errors);
    }
}
