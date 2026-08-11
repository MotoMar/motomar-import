<?php

declare(strict_types=1);

use App\Domain\Tire\TireParametersBuilder;
use App\Domain\Tire\VehicleTypeClassificationOrder;

/**
 * A rebuild must not delete what it has no way of producing.
 *
 * `purpose` and `wheel_position` exist in tires_dictionary and in thousands of
 * stored classifications, but no vehicle type lists them, so a fresh result
 * never contains them. Before the update path wrote classifications at all,
 * that was harmless. It is not harmless now.
 */

it('keeps a kind that the vehicle type does not classify', function (): void {
    $result = TireParametersBuilder::preserveUnclassifiableKinds(
        ['tube_type' => ['TL/TT']],
        ['tube_type' => ['TL'], 'purpose' => ['opona szosowa'], 'wheel_position' => ['przód/tył']],
        VehicleTypeClassificationOrder::forVehicleType(7),
    );

    expect($result)->toBe([
        'tube_type'      => ['TL/TT'],
        'purpose'        => ['opona szosowa'],
        'wheel_position' => ['przód/tył'],
    ]);
});

it('replaces a kind that the vehicle type does classify', function (): void {
    // The fresh answer wins wherever the classifier has an opinion, including
    // when the fresh answer is "this kind is now empty".
    $result = TireParametersBuilder::preserveUnclassifiableKinds(
        ['reinforcement' => ['XL']],
        ['reinforcement' => ['C'], 'season' => ['M+S']],
        VehicleTypeClassificationOrder::forVehicleType(1),
    );

    expect($result)->toBe(['reinforcement' => ['XL']]);
});

it('leaves a fresh result untouched when there is nothing to preserve', function (): void {
    $fresh = ['reinforcement' => ['XL'], 'season' => ['3PMSF']];

    expect(TireParametersBuilder::preserveUnclassifiableKinds(
        $fresh,
        [],
        VehicleTypeClassificationOrder::forVehicleType(1),
    ))->toBe($fresh);
});

it('does not resurrect an empty kind', function (): void {
    expect(TireParametersBuilder::preserveUnclassifiableKinds(
        ['tube_type' => ['TL']],
        ['purpose' => []],
        VehicleTypeClassificationOrder::forVehicleType(7),
    ))->toBe(['tube_type' => ['TL']]);
});

it('protects every kind the dictionary knows and no vehicle type claims', function (): void {
    $json = file_get_contents(__DIR__ . '/../Fixtures/dictionary.json');
    expect($json)->toBeString();

    /** @var array<string, string[]> $dictionary */
    $dictionary = json_decode((string) $json, true);

    $classified = [];

    for ($type = 1; $type <= 10; ++$type) {
        foreach (VehicleTypeClassificationOrder::forVehicleType($type) as $kind) {
            $classified[$kind] = true;
        }
    }

    $orphans = array_values(array_diff(array_keys($dictionary), array_keys($classified)));
    sort($orphans);

    // If this list ever changes, someone either added a kind to the order —
    // good — or added one to the dictionary that nothing will ever write.
    expect($orphans)->toBe(['purpose', 'wheel_position']);
});
