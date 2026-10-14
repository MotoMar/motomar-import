<?php

declare(strict_types=1);

namespace App\Eprel;

/**
 * Rozmiar opony odczytany z napisu — tak samo z cennika (`205/55R16`) i z EPREL-u
 * (`205/55 R16`, `275/35ZRF20 102W`), żeby obie strony dało się porównać.
 *
 * Cztery zapisy, sprawdzane w tej kolejności, bo późniejsze trafiają też w środek
 * wcześniejszych (`7.50R16` zawiera `50R16`):
 * - flotacyjny `31x10.50R15` — szerokość to cały `31x10.50`, profilu nie ma; EPREL pisze go też
 *   z `×` albo `*` i z myślnikiem zamiast R (`31×10.50-15LT`);
 * - metryczny `205/55R16`;
 * - calowy `7.50R16`, `10.00R20` — szerokość z ułamkiem, profilu nie ma; EPREL pisze go też bez
 *   kropki (`500R12`, `750R16C`, `1000R20`), a szerokość metryczna kończy się zawsze na 5, więc
 *   `…00` i `…50` to cale razy sto;
 * - bez profilu `185R14` (opony C i stare rozmiary).
 */
final readonly class TyreSize
{
    private function __construct(
        public string $width,
        public string $profile,
        public string $rim,
    ) {
    }

    public static function parse(string $designation): ?self
    {
        $s = strtoupper(str_replace(['×', '*'], 'X', (string) preg_replace('/\s+/', '', $designation)));
        $rim = '(\d{2}(?:\.\d)?)';

        return match (true) {
            1 === preg_match("#(\d{2})X(\d{1,2}(?:\.\d{1,2})?)(?:-|Z?R?F?){$rim}#", $s, $m)
                => new self($m[1] . 'x' . self::decimal($m[2]), '', self::decimal($m[3])),
            // `RR`: literówka dostawców w rejestrze (`245/45 RR20`, `325/30 ZRR21`)
            1 === preg_match("#(\d{3})/(\d{2,3})Z?R{0,2}F?{$rim}#", $s, $m)
                => new self($m[1], $m[2], self::decimal($m[3])),
            1 === preg_match("#(\d{1,2}\.\d{1,2})Z?RF?{$rim}#", $s, $m)
                => new self(self::decimal($m[1]), '', self::decimal($m[2])),
            1 === preg_match("#(?<![\d.])(\d{1,2})(00|50)Z?RF?{$rim}#", $s, $m)
                => new self(self::decimal("{$m[1]}.{$m[2]}"), '', self::decimal($m[3])),
            1 === preg_match("#(?<![\d.])(\d{3})Z?RF?{$rim}#", $s, $m)
                => new self($m[1], '', self::decimal($m[2])),
            default => null,
        };
    }

    /** `7.5` i `7.50`, `16` i `16.0` to ten sam wymiar. */
    private static function decimal(string $number): string
    {
        return rtrim(rtrim(sprintf('%.2f', (float) $number), '0'), '.');
    }
}
