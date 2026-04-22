<?php

declare(strict_types=1);

namespace App\Domain\Csv;

final readonly class TireRow
{
    public string $ref1;
    public string $ref2;
    public string $ean;
    public string $externalId;
    public string $producerName;
    public string $vehicleTypeShortcut;
    public string $modelName;
    public string $size;
    public string $size2;
    public string $indices;
    public string $indices2;
    public string $extra;
    public string $rollingResistance;
    public string $adhesion;
    public string $noise;
    public string $waves;
    public string $eprelId;
    public float $price;

    public function __construct(array $data)
    {
        $this->ref1                = trim($data['numkat1']);
        $this->ref2                = trim($data['numkat2']);
        $this->ean                 = trim($data['ean']);
        $this->externalId          = trim($data['id']);
        $this->producerName        = trim($data['producent']);
        $this->vehicleTypeShortcut = trim($data['rodzaj']);
        $this->modelName           = trim($data['bieznik']);
        $this->size                = trim($data['rozmiar']);
        $this->size2               = trim($data['rozmiar2']);
        $this->indices             = trim($data['indeksy']);
        $this->indices2            = trim($data['indeksy2']);
        $this->extra               = trim($data['inne']);
        $this->rollingResistance   = strtoupper(trim($data['opor']));
        $this->adhesion            = strtoupper(trim($data['mokre']));
        $this->noise               = trim($data['halas']);
        $this->waves               = trim($data['fale']);
        $this->eprelId             = trim($data['eprel']);
        $this->price               = self::parsePrice($data['netto']);
    }

    private static function parsePrice(string $raw): float
    {
        $raw = preg_replace('/[^\d.,]/', '', trim($raw));
        $lastComma  = strrpos($raw, ',');
        $lastPeriod = strrpos($raw, '.');
        if ($lastComma !== false && $lastPeriod !== false) {
            // Both separators: the one appearing last is the decimal separator
            $raw = $lastComma > $lastPeriod
                ? str_replace(['.', ','], ['', '.'], $raw)   // 1.234,56 → 1234.56
                : str_replace(',', '', $raw);                 // 1,234.56 → 1234.56
        } elseif ($lastComma !== false) {
            $raw = str_replace(',', '.', $raw);               // 12,50 → 12.50
        }
        return (float) $raw;
    }

    public function hasValidProducer(): bool
    {
        return $this->producerName !== '';
    }

    public function hasValidModel(): bool
    {
        return $this->modelName !== '';
    }

    public function hasValidPrice(): bool
    {
        return $this->price > 0.0;
    }

    public function hasValidEan(): bool
    {
        return strlen($this->ean) === 13 && ctype_digit($this->ean);
    }

    public function hasCompleteLabel(): bool
    {
        return strlen($this->rollingResistance . $this->adhesion . $this->noise) === 4;
    }

    public function mappingKey(): string
    {
        return $this->producerName . '|' . $this->modelName;
    }
}
