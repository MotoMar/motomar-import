<?php

declare(strict_types=1);

namespace App\Eprel;

/**
 * Porównywanie nazw z cennika z tym, co wpisał dostawca w EPREL.
 *
 * Żadna strona nie pisze starannie: `DEBICA` i `Dębica`, `NEXENTIRE` i `Nexen`,
 * `FALKEN ZE310EC` z modelem doklejonym do marki. Dlatego najpierw składamy nazwę
 * do małych liter i cyfr, potem porównujemy.
 */
final class Names
{
    /** Krótsza nazwa porównywana prefiksem; `GT` pasowałoby do połowy rejestru. */
    private const int MIN_PREFIX = 4;

    public static function fold(string $value): string
    {
        $ascii = strtr(mb_strtolower($value), [
            'ą' => 'a', 'ć' => 'c', 'ę' => 'e', 'ł' => 'l', 'ń' => 'n', 'ó' => 'o', 'ś' => 's', 'ź' => 'z', 'ż' => 'z',
            'ä' => 'a', 'é' => 'e', 'è' => 'e', 'ö' => 'o', 'ü' => 'u', 'š' => 's', 'ž' => 'z', 'č' => 'c',
        ]);

        return (string) preg_replace('/[^a-z0-9]/', '', $ascii);
    }

    /**
     * Ta sama marka, także gdy jedna strona ma doklejone słowo: `NEXENTIRE`/`Nexen`,
     * `MOMO`/`Momo Tires`, `FALKEN ZE310EC`/`Falken`.
     */
    public static function sameBrand(string $a, string $b): bool
    {
        [$x, $y] = [self::fold($a), self::fold($b)];

        if ('' === $x || '' === $y) {
            return false;
        }

        [$shorter, $longer] = strlen($x) <= strlen($y) ? [$x, $y] : [$y, $x];

        return $shorter === $longer || (strlen($shorter) >= self::MIN_PREFIX && str_starts_with($longer, $shorter));
    }

    /**
     * Nazwa handlowa z rejestru da się rozpoznać jako nasz bieżnik: jedna zawiera
     * drugą po złożeniu (`SP602` w `Perfectus Sp-602`). Pusta po którejś stronie
     * niczemu nie przeczy.
     */
    public static function sameTread(string $ours, string $theirs): bool
    {
        [$x, $y] = [self::fold($ours), self::fold($theirs)];

        return '' === $x || '' === $y || str_contains($x, $y) || str_contains($y, $x);
    }
}
