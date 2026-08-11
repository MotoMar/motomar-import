<?php

declare(strict_types=1);

use App\Domain\Tire\TireParametersBuilder;

/**
 * @param array<string, mixed> $overrides
 * @return array<string, mixed>
 */
function tireRow(string $other, int $vehicleType = 1, array $overrides = []): array
{
    return ['other' => $other, 'id_vehicles_type' => $vehicleType] + $overrides;
}

it('classifies each token into its dictionary kind', function (): void {
    $result = (new TireParametersBuilder())->buildParameters(
        tireRow('XL;FR;MO;3PMSF;M+S;EV'),
        dictionary(),
    );

    expect($result)->toBe([
        'reinforcement' => ['XL'],
        'rim_protector' => ['FR'],
        'homologation'  => ['MO'],
        'season'        => ['3PMSF', 'M+S'],
        'ev'            => ['EV'],
    ]);
});

it('accepts both separator styles found in the column', function (string $other): void {
    $result = (new TireParametersBuilder())->buildParameters(tireRow($other), dictionary());

    expect($result)->toBe(['reinforcement' => ['XL'], 'rim_protector' => ['FP']]);
})->with([
    'no spaces'   => 'XL;FP',
    'with spaces' => 'XL; FP',
    'trailing'    => 'XL;FP;',
    'empty token' => 'XL;;FP',
]);

it('matches case-insensitively but stores the canonical code', function (): void {
    $result = (new TireParametersBuilder())->buildParameters(
        tireRow('xl;contiseal'),
        dictionary(),
    );

    expect($result)->toBe(['reinforcement' => ['XL'], 'seal' => ['ContiSeal']]);
});

it('resolves a code shared by two kinds using the classification order', function (): void {
    // RF is both a reinforcement and a rim protector. For vehicle type 1
    // reinforcement comes first in VehicleTypeClassificationOrder, so it wins —
    // and nothing lands in rim_protector.
    $result = (new TireParametersBuilder())->buildParameters(tireRow('RF'), dictionary());

    expect($result)->toBe(['reinforcement' => ['RF']]);
});

it('keeps only the strongest reinforcement', function (): void {
    $result = (new TireParametersBuilder())->buildParameters(tireRow('C;XL'), dictionary());

    expect($result)->toBe(['reinforcement' => ['XL']]);
});

it('merges separate TL and TT into the canonical pair', function (): void {
    $result = (new TireParametersBuilder())->buildParameters(
        tireRow('TT;TL', vehicleType: 7),
        dictionary(),
    );

    expect($result)->toBe(['tube_type' => ['TL/TT']]);
});

it('normalises TT/TL to TL/TT', function (): void {
    $result = (new TireParametersBuilder())->buildParameters(
        tireRow('TT/TL', vehicleType: 7),
        dictionary(),
    );

    expect($result)->toBe(['tube_type' => ['TL/TT']]);
});

it('drops tokens the dictionary does not know', function (): void {
    // AL turns up 55 times on Continentals and is in no dictionary. It must not
    // land in some neighbouring kind just because nothing else claimed it.
    $result = (new TireParametersBuilder())->buildParameters(tireRow('XL;AL;EV'), dictionary());

    expect($result)->toBe(['reinforcement' => ['XL'], 'ev' => ['EV']]);
});

it('deduplicates repeated tokens and keeps the order they appeared in', function (): void {
    // Within one kind the codes stay in column order, not dictionary order —
    // the suffix order in the product name is decided per kind, not per code.
    $result = (new TireParametersBuilder())->buildParameters(
        tireRow('M+S;3PMSF;M+S'),
        dictionary(),
    );

    expect($result)->toBe(['season' => ['M+S', '3PMSF']]);
});

it('returns nothing for an empty column', function (?string $other): void {
    $row = ['other' => $other, 'id_vehicles_type' => 1];

    expect((new TireParametersBuilder())->buildParameters($row, dictionary()))->toBe([]);
})->with(['empty' => '', 'null' => null, 'whitespace' => '   ', 'separators only' => ';;']);

it('returns nothing for a vehicle type with no classification order', function (): void {
    // An unknown CSV shortcut resolves to 0. Storing the empty result over a
    // good classification is the failure mode; the import path now reads the
    // vehicle type from the database instead of the shortcut.
    $result = (new TireParametersBuilder())->buildParameters(
        tireRow('XL;FR;3PMSF', vehicleType: 0),
        dictionary(),
    );

    expect($result)->toBe([]);
});

it('classifies only the kinds the vehicle type allows', function (): void {
    // Type 4 (truck) has no `ev` and no `season` in its order, so EV falls out
    // even though the dictionary knows it.
    $result = (new TireParametersBuilder())->buildParameters(
        tireRow('C;EV;TL', vehicleType: 4),
        dictionary(),
    );

    expect($result)->toBe(['tube_type' => ['TL'], 'reinforcement' => ['C']]);
});

it('encodes an empty classification as an empty JSON object', function (): void {
    expect(TireParametersBuilder::toJson([]))->toBe('{}');
});

it('survives a round trip through JSON', function (): void {
    $parameters = ['reinforcement' => ['XL'], 'season' => ['3PMSF', 'M+S']];

    expect(TireParametersBuilder::fromJson(TireParametersBuilder::toJson($parameters)))
        ->toBe($parameters);
});

it('decodes junk as an empty classification', function (?string $json): void {
    expect(TireParametersBuilder::fromJson($json))->toBe([]);
})->with(['null' => null, 'empty' => '', 'not json' => 'XL;FR', 'scalar' => '42']);
