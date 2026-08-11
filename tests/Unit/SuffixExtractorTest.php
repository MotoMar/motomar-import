<?php

declare(strict_types=1);

use App\Domain\Tire\ReinforcementHelper;
use App\Domain\Tire\SuffixExtractor;
use App\Domain\Tire\VehicleTypeClassificationOrder;
use App\Domain\Tire\VehicleTypeSuffixOrder;

it('emits suffixes in the order defined for the vehicle type', function (): void {
    $suffixes = (new SuffixExtractor())->extractSuffixes(1, [
        'season'        => ['3PMSF'],
        'homologation'  => ['MO'],
        'reinforcement' => ['XL'],
        'rim_protector' => ['FR'],
    ]);

    expect($suffixes)->toBe(['XL', 'FR', 'MO', '3PMSF']);
});

it('keeps several codes of one kind in the order they were classified', function (): void {
    $suffixes = (new SuffixExtractor())->extractSuffixes(1, [
        'homologation' => ['MO', '*', 'AO'],
    ]);

    expect($suffixes)->toBe(['MO', '*', 'AO']);
});

it('leaves C and CP out because they belong to the size block', function (string $code): void {
    expect((new SuffixExtractor())->extractSuffixes(1, ['reinforcement' => [$code]]))->toBe([]);
})->with(['C', 'CP']);

it('still emits the reinforcements that are not part of the size', function (string $code): void {
    expect((new SuffixExtractor())->extractSuffixes(1, ['reinforcement' => [$code]]))->toBe([$code]);
})->with(['XL', 'RF']);

it('ignores kinds the vehicle type does not use', function (): void {
    // Type 4 (truck) has no `ev` and no `rim_protector` in its suffix order.
    $suffixes = (new SuffixExtractor())->extractSuffixes(4, [
        'ev'            => ['EV'],
        'rim_protector' => ['FR'],
        'ply_rating'    => ['16PR'],
    ]);

    expect($suffixes)->toBe(['16PR']);
});

it('returns nothing when there is nothing to say', function (int $vehicleType, array $classified): void {
    expect((new SuffixExtractor())->extractSuffixes($vehicleType, $classified))->toBe([]);
})->with([
    'unknown vehicle type' => [0, ['reinforcement' => ['XL']]],
    'type out of range'    => [99, ['reinforcement' => ['XL']]],
    'no parameters'        => [1, []],
    'empty kind'           => [1, ['reinforcement' => []]],
    'blank code'           => [1, ['reinforcement' => ['  ']]],
]);

it('covers every vehicle type from 1 to 10 and nothing else', function (): void {
    expect(VehicleTypeSuffixOrder::supportedTypes())->toBe(range(1, 10))
        ->and(VehicleTypeClassificationOrder::forVehicleType(0))->toBe([])
        ->and(VehicleTypeClassificationOrder::isSupported(11))->toBeFalse();
});

it('classifies at least everything it can name', function (int $vehicleType): void {
    // The classification order has to be a superset of the suffix order.
    // A kind that appears in a name but is never stored would be a suffix the
    // generator can never produce.
    $classification = VehicleTypeClassificationOrder::forVehicleType($vehicleType);
    $suffix = VehicleTypeSuffixOrder::forVehicleType($vehicleType);

    expect(array_diff($suffix, $classification))->toBe([]);
})->with(range(1, 10));

it('classifies the suffix kinds first and in the same order', function (int $vehicleType): void {
    // The classification list starts with the suffix list verbatim; the extra
    // kinds are appended. Reordering one file and not the other would silently
    // reshuffle product names.
    $classification = VehicleTypeClassificationOrder::forVehicleType($vehicleType);
    $suffix = VehicleTypeSuffixOrder::forVehicleType($vehicleType);

    expect(array_slice($classification, 0, count($suffix)))->toBe($suffix);
})->with(range(1, 10));

it('picks the strongest reinforcement', function (array $codes, string $expected): void {
    expect(ReinforcementHelper::pickStrongest($codes))->toBe($expected);
})->with([
    'single'            => [['XL'], 'XL'],
    'C loses to XL'     => [['C', 'XL'], 'XL'],
    'XL beats C'        => [['XL', 'C'], 'XL'],
    'RF loses to XL'    => [['RF', 'XL'], 'XL'],
    'C loses to CP'     => [['C', 'CP'], 'CP'],
    'padded'            => [[' XL '], 'XL'],
    'unknown code kept' => [['HL'], 'HL'],
    'unknown loses'     => [['HL', 'XL'], 'XL'],
    'two unknowns'      => [['HL', 'EL'], 'HL'],
    'blanks skipped'    => [['', 'XL'], 'XL'],
    'nothing'           => [[], ''],
]);

it('knows which reinforcements live inside the size', function (): void {
    expect(ReinforcementHelper::isEmbeddedInSize('C'))->toBeTrue()
        ->and(ReinforcementHelper::isEmbeddedInSize('CP'))->toBeTrue()
        ->and(ReinforcementHelper::isEmbeddedInSize('XL'))->toBeFalse()
        ->and(ReinforcementHelper::isEmbeddedInSize('c'))->toBeFalse();
});
