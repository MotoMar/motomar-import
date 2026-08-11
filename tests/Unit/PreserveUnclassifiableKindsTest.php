<?php

declare(strict_types=1);

use App\Domain\Tire\TireParametersBuilder;
use App\Domain\Tire\VehicleTypeClassificationOrder;

/**
 * A rebuild must not delete what it has no way of producing.
 *
 * This nearly cost us `purpose` and `wheel_position`: both were in the
 * dictionary and in thousands of stored classifications while no vehicle type
 * listed them, so a rebuild produced neither. They are in the order now, but
 * the rule stands for whatever ends up in that position next.
 */

it('keeps a kind that the vehicle type does not classify', function (): void {
    // A passenger tire has no `purpose` in its order, so a value stored there by
    // something else survives a rebuild.
    $result = TireParametersBuilder::preserveUnclassifiableKinds(
        ['reinforcement' => ['XL']],
        ['reinforcement' => ['C'], 'purpose' => ['enduro']],
        VehicleTypeClassificationOrder::forVehicleType(1),
    );

    expect($result)->toBe([
        'reinforcement' => ['XL'],
        'purpose'       => ['enduro'],
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
        VehicleTypeClassificationOrder::forVehicleType(1),
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

    // Empty since purpose and wheel_position joined the two-wheel types. If it
    // grows again, someone added a kind to the dictionary that nothing writes.
    expect($orphans)->toBe([]);
});
