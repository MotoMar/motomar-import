<?php

declare(strict_types=1);

use App\Domain\Csv\TireRow;

/**
 * @param array<string, string> $overrides
 */
function csvRow(array $overrides = []): TireRow
{
    return new TireRow($overrides + [
        'numkat1'   => '1034013',
        'numkat2'   => '',
        'ean'       => '8808563600802',
        'id'        => '',
        'producent' => 'Hankook',
        'rodzaj'    => 'O',
        'bieznik'   => 'iON ST AS IH61',
        'rozmiar'   => '205/50R17',
        'rozmiar2'  => '',
        'indeksy'   => '93V',
        'indeksy2'  => '',
        'inne'      => 'XL',
        'opor'      => 'B',
        'mokre'     => 'D',
        'halas'     => '70',
        'fale'      => 'B',
        'eprel'     => '2068511',
        'netto'     => '734,50',
    ]);
}

it('trims every field', function (): void {
    $row = csvRow(['producent' => '  Hankook  ', 'inne' => "  XL; FR \t", 'ean' => ' 8808563600802 ']);

    expect($row->producerName)->toBe('Hankook')
        ->and($row->extra)->toBe('XL; FR')
        ->and($row->ean)->toBe('8808563600802');
});

it('uppercases the label letters but not the model name', function (): void {
    $row = csvRow(['opor' => 'b', 'mokre' => 'd', 'bieznik' => 'iON ST AS IH61']);

    expect($row->rollingResistance)->toBe('B')
        ->and($row->adhesion)->toBe('D')
        ->and($row->modelName)->toBe('iON ST AS IH61');
});

it('reads prices written the Polish way and the English way', function (string $raw, float $expected): void {
    expect(csvRow(['netto' => $raw])->price)->toBe($expected);
})->with([
    'comma decimal'          => ['734,50', 734.5],
    'dot decimal'            => ['734.50', 734.5],
    'dot thousands'          => ['1.234,56', 1234.56],
    'comma thousands'        => ['1,234.56', 1234.56],
    'no decimals'            => ['852', 852.0],
    'currency and spaces'    => ['1 234,56 PLN', 1234.56],
    'empty'                  => ['', 0.0],
    'zero the Bridgestone way' => ['0,00', 0.0],
]);

it('accepts only a 13-digit numeric EAN', function (string $ean, bool $valid): void {
    expect(csvRow(['ean' => $ean])->hasValidEan())->toBe($valid);
})->with([
    'proper'          => ['8808563600802', true],
    'twelve digits'   => ['880856360080', false],
    'fourteen digits' => ['88085636008021', false],
    'letters'         => ['880856360080X', false],
    'empty'           => ['', false],
]);

it('treats a zero price as no price', function (): void {
    // Whole price lists arrive without prices — Bridgestone and Landspider send
    // 2517 such rows. `update_price` then silently does nothing for them.
    expect(csvRow(['netto' => ''])->hasValidPrice())->toBeFalse()
        ->and(csvRow(['netto' => '0,00'])->hasValidPrice())->toBeFalse()
        ->and(csvRow(['netto' => '0,01'])->hasValidPrice())->toBeTrue();
});

it('calls a label complete only at exactly four characters', function (
    string $rolling,
    string $adhesion,
    string $noise,
    bool $complete,
): void {
    $row = csvRow(['opor' => $rolling, 'mokre' => $adhesion, 'halas' => $noise]);

    expect($row->hasCompleteLabel())->toBe($complete);
})->with([
    'B + D + 70'      => ['B', 'D', '70', true],
    'no noise'        => ['B', 'D', '', false],
    'nothing at all'  => ['', '', '', false],
    'three digit noise' => ['B', 'D', '100', false],   // 5 characters, rejected
    'single digit noise' => ['B', 'D', '7', false],
]);

it('keys the model mapping by producer and model', function (): void {
    expect(csvRow()->mappingKey())->toBe('Hankook|iON ST AS IH61');
});

it('reports a missing producer or model', function (): void {
    expect(csvRow(['producent' => ''])->hasValidProducer())->toBeFalse()
        ->and(csvRow(['bieznik' => '  '])->hasValidModel())->toBeFalse()
        ->and(csvRow()->hasValidProducer())->toBeTrue()
        ->and(csvRow()->hasValidModel())->toBeTrue();
});
