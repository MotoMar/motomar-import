<?php

declare(strict_types=1);

namespace App\Eprel;

/**
 * Rejestracja opony w EPREL, sprowadzona do pól, które porównujemy z cennikiem.
 *
 * Budowana z odpowiedzi API po numerze (`/api/products/tyres/{numer}`); eksport
 * dzienny ma te same pola, ale nie ma nazwy handlowej ani GTIN-u.
 */
final readonly class Registration
{
    public function __construct(
        public int $number,
        public string $brand,
        public string $name,
        public string $designation,
        public string $size,
        public ?TyreSize $parsedSize,
        public int $loadIndex,
        public int $loadIndex2,
        public string $speedIndex,
        public string $gtin,
        public string $status,
        public string $energy,
        public string $grip,
        public int $noise,
        public string $noiseClass,
        public bool $snow,
        public bool $mudSnow,
        public bool $reinforced,
        public string $tyreClass,
        public string $modelIdentifier,
    ) {
    }

    /**
     * @param array<mixed> $json ciało odpowiedzi API, już zdekodowane
     */
    public static function fromApi(array $json): ?self
    {
        $number = self::int($json['eprelRegistrationNumber'] ?? null);

        if (0 === $number) {
            return null;
        }

        $details = is_array($json['additionalDetails'] ?? null) ? $json['additionalDetails'] : [];
        $size = self::str($json['sizeDesignation'] ?? null);

        return new self(
            number: $number,
            brand: self::str($json['supplierOrTrademark'] ?? null),
            name: self::str($details['commercialName'] ?? null),
            designation: self::str($json['tyreDesignation'] ?? null),
            size: $size,
            // liczby z `tyreSection`/`aspectRatio`/`rimDiameter` bywają przesunięte („185 R14 C” daje
            // przekrój 185, profil 14, średnicę 0), a przy calowych gubią ułamek — wierzymy napisowi
            parsedSize: TyreSize::parse($size),
            loadIndex: self::int($json['loadCapacityIndex'] ?? null),
            loadIndex2: self::int($json['loadCapacityIndex2'] ?? null),
            speedIndex: self::speed(self::str($json['speedCategorySymbol'] ?? null)),
            gtin: self::str($details['gtinIdentifier'] ?? null),
            status: self::str($json['status'] ?? null),
            energy: self::str($json['energyClass'] ?? null),
            grip: self::str($json['wetGripClass'] ?? null),
            noise: self::int($json['externalRollingNoiseValue'] ?? null),
            noiseClass: self::str($json['externalRollingNoiseClass'] ?? null),
            snow: true === ($json['severeSnowTyre'] ?? null),
            mudSnow: true === ($details['mudSnow'] ?? null),
            reinforced: in_array(self::str($json['loadCapacityIndicator'] ?? null), ['XL', 'RF'], true),
            tyreClass: self::str($json['tyreClass'] ?? null),
            modelIdentifier: self::str($json['modelIdentifier'] ?? null),
        );
    }

    public function label(): string
    {
        return sprintf('%s/%s/%d dB/%s', $this->energy, $this->grip, $this->noise, $this->noiseClass);
    }

    /** EPREL zapisuje symbol w nawiasie, `(Y)`, jako `PAR_Y_PAR`. */
    private static function speed(string $symbol): string
    {
        return 1 === preg_match('/^PAR_([A-Z])_PAR$/', $symbol, $m) ? "({$m[1]})" : $symbol;
    }

    private static function str(mixed $value): string
    {
        return is_string($value) ? trim($value) : '';
    }

    private static function int(mixed $value): int
    {
        return is_int($value) || (is_string($value) && is_numeric($value)) ? (int) $value : 0;
    }
}
