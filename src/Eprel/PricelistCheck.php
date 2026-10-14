<?php

declare(strict_types=1);

namespace App\Eprel;

use App\Domain\Csv\TireRow;

/**
 * Co wiersz cennika mówi o oponie, wobec tego, co EPREL trzyma pod jego numerem.
 *
 * Pole porównujemy tylko wtedy, gdy wypełniły je obie strony — milczenie którejś
 * nie jest sprzecznością. Etykietę drukujemy z cennika, więc klasy i decybele
 * muszą się zgadzać co do joty. Nazwy pól i zapis `pole: nasze → ich` to kontrakt
 * z `tools/enis/apply_eprel.py`.
 */
final class PricelistCheck
{
    /** @return list<Mismatch> */
    public static function mismatches(TireRow $row, Registration $registration): array
    {
        $found = [];
        $add = static function (string $field, string $ours, string $theirs) use (&$found): void {
            $found[] = new Mismatch($field, $ours, $theirs);
        };
        $flag = static function (string $field, bool $ours, bool $theirs) use ($add): void {
            if ($ours !== $theirs) {
                $add($field, $ours ? 'tak' : 'nie', $theirs ? 'tak' : 'nie');
            }
        };

        if ('' !== $registration->brand && !Names::sameBrand($registration->brand, $row->producerName)) {
            $add('marka', $row->producerName, $registration->brand);
        }

        self::compareSize($row, $registration, $add);

        [$li, $li2, $si] = self::indices($row->indices);
        $theirIndices = $registration->loadIndex . (0 !== $registration->loadIndex2 ? '/' . $registration->loadIndex2 : '');

        if (0 !== $li && 0 !== $registration->loadIndex
            && ($li !== $registration->loadIndex || (0 !== $li2 && $li2 !== $registration->loadIndex2))) {
            $add('indeks nośności', $li . (0 !== $li2 ? "/{$li2}" : ''), $theirIndices);
        }

        if ('' !== $si && '' !== $registration->speedIndex && 0 !== strcasecmp(trim($registration->speedIndex, '()'), $si)) {
            $add('indeks prędkości', $si, $registration->speedIndex);
        }

        if ('' !== $row->ean && '' !== $registration->gtin && ltrim($row->ean, '0') !== ltrim($registration->gtin, '0')) {
            $add('EAN', $row->ean, $registration->gtin);
        }

        foreach ([
            ['opór toczenia', $row->rollingResistance, $registration->energy],
            ['przyczepność', $row->adhesion, $registration->grip],
            ['klasa hałasu', strtoupper($row->waves), $registration->noiseClass],
        ] as [$field, $ours, $theirs]) {
            if ('' !== $ours && '' !== $theirs && 0 !== strcasecmp($ours, $theirs)) {
                $add($field, $ours, $theirs);
            }
        }

        if ('' !== $row->noise && 0 !== $registration->noise && (int) $row->noise !== $registration->noise) {
            $add('hałas dB', $row->noise, (string) $registration->noise);
        }

        $marks = array_map(static fn (string $t): string => strtoupper(trim($t)), explode(';', $row->extra));
        $flag('3PMSF', in_array('3PMSF', $marks, true), $registration->snow);

        // `mudSnow` jest w rejestrze przeważnie puste, więc przeczyć nam może tylko rejestracja, która M+S deklaruje
        if ($registration->mudSnow && !in_array('M+S', $marks, true)) {
            $add('M+S', 'nie', 'tak');
        }

        $flag('XL', in_array('XL', $marks, true), $registration->reinforced);

        // opona dostawcza (C) to w rejestrze klasa C2; C1 to osobowa
        if ('' !== $registration->tyreClass) {
            $flag('C', in_array('C', $marks, true), 'C2' === $registration->tyreClass);
        }

        if ('' !== $registration->status && 'PUBLISHED' !== $registration->status) {
            $add('status', 'PUBLISHED', $registration->status);
        }

        return $found;
    }

    /** @param callable(string, string, string): void $add */
    private static function compareSize(TireRow $row, Registration $registration, callable $add): void
    {
        $ours = TyreSize::parse($row->size);
        $theirs = $registration->parsedSize;

        if (null === $ours || null === $theirs) {
            $add('rozmiar', $row->size . (null === $ours ? ' (nieczytelny)' : ''), $registration->size);

            return;
        }

        if ($ours->width !== $theirs->width) {
            $add('szerokość', $ours->width, $theirs->width);
        }

        // pełny profil (`185R14`) nie ma liczby po żadnej stronie albo ma ją tylko u jednej — to nie sprzeczność
        if ('' !== $ours->profile && '' !== $theirs->profile && $ours->profile !== $theirs->profile) {
            $add('profil', $ours->profile, $theirs->profile);
        }

        if ($ours->rim !== $theirs->rim) {
            $add('średnica', $ours->rim, $theirs->rim);
        }
    }

    /** @return array{0: int, 1: int, 2: string} */
    private static function indices(string $indices): array
    {
        if (1 !== preg_match('/^(\d+)(?:\/(\d+))?\s*([A-Z]+)$/i', trim($indices), $m)) {
            return [0, 0, ''];
        }

        return [(int) $m[1], (int) $m[2], strtoupper($m[3])];
    }
}
