<?php

declare(strict_types=1);

use App\Domain\Tire\NameGenerator;
use App\Domain\Tire\SuffixExtractor;
use App\Domain\Tire\TireParametersBuilder;

/**
 * @param array<string, mixed> $overrides
 * @return array<string, mixed>
 */
function passengerTire(array $overrides = []): array
{
    return $overrides + [
        'tire_id'          => 123,
        'producer'         => 'Continental',
        'tread'            => 'PremiumContact 6',
        'tire_size'        => '225/45 R 17',
        'tire_li'          => '94',
        'tire_si'          => 'Y',
        'id_vehicles_type' => 1,
    ];
}

function nameGenerator(): NameGenerator
{
    return new NameGenerator(new SuffixExtractor());
}

it('builds the name from the classified parameters', function (): void {
    $name = nameGenerator()->generate(passengerTire(), [
        'reinforcement' => ['XL'],
        'rim_protector' => ['FR'],
        'homologation'  => ['AO'],
    ]);

    expect($name)->toBe('Continental PremiumContact 6 225/45 R 17 94Y XL FR AO');
});

it('emits suffixes in the order defined for the vehicle type, not the order given', function (): void {
    $name = nameGenerator()->generate(passengerTire(), [
        'homologation'  => ['MO'],
        'season'        => ['3PMSF'],
        'reinforcement' => ['XL'],
    ]);

    expect($name)->toBe('Continental PremiumContact 6 225/45 R 17 94Y XL MO 3PMSF');
});

it('embeds C in the size block instead of trailing it', function (): void {
    $name = nameGenerator()->generate(
        passengerTire(['tire_size' => '215/65 R 16', 'tire_li' => '109/107', 'tire_si' => 'T']),
        ['reinforcement' => ['C']],
    );

    expect($name)->toBe('Continental PremiumContact 6 215/65 R 16C 109/107T');
});

it('uses the integer reinforcement column only for the size block', function (): void {
    // The `reinforcement` ENUM index is documented as a fallback for an empty
    // classification, but it only ever reaches the size block. C lands in the
    // size; XL is resolved and then dropped, because every suffix in the name
    // comes from tires_classified_parameters and from nowhere else.
    expect(nameGenerator()->generate(passengerTire(['reinforcement' => 1]), []))
        ->toBe('Continental PremiumContact 6 225/45 R 17C 94Y');

    expect(nameGenerator()->generate(passengerTire(['reinforcement' => 4]), []))
        ->toBe('Continental PremiumContact 6 225/45 R 17 94Y');
});

it('prefers the classification over the integer column', function (): void {
    $row = passengerTire(['reinforcement' => 4]);   // ENUM index 4 = XL

    expect(nameGenerator()->generate($row, ['reinforcement' => ['C']]))
        ->toBe('Continental PremiumContact 6 225/45 R 17C 94Y');
});

it('produces a bare name when nothing is classified', function (): void {
    expect(nameGenerator()->generate(passengerTire(), []))
        ->toBe('Continental PremiumContact 6 225/45 R 17 94Y');
});

it('loses a marker from the name when the classification is stale', function (): void {
    // The reason the update path now refreshes tires_classified_parameters:
    // the name is assembled from that table and from nothing else, so a price
    // list that adds EV changes nothing until the classification is rebuilt.
    $fresh = (new TireParametersBuilder())->buildParameters(
        ['other' => 'XL;FR;EV', 'id_vehicles_type' => 1],
        dictionary(),
    );
    $stale = (new TireParametersBuilder())->buildParameters(
        ['other' => 'XL;FR', 'id_vehicles_type' => 1],
        dictionary(),
    );

    expect(nameGenerator()->generate(passengerTire(), $fresh))
        ->toBe('Continental PremiumContact 6 225/45 R 17 94Y XL FR EV')
        ->and(nameGenerator()->generate(passengerTire(), $stale))
        ->toBe('Continental PremiumContact 6 225/45 R 17 94Y XL FR');
});

it('appends the tire id to the slug', function (): void {
    $result = nameGenerator()->generateWithSlug(passengerTire(), ['reinforcement' => ['XL']]);

    expect($result)->toBe([
        'name' => 'Continental PremiumContact 6 225/45 R 17 94Y XL',
        'slug' => 'continental-premiumcontact-6-225-45-r-17-94y-xl-123',
    ]);
});

it('skips a missing component instead of leaving a double space', function (): void {
    // Half a load/speed pair is not a load/speed pair — LiSiFormatter drops both.
    $name = nameGenerator()->generate(passengerTire(['tread' => '', 'tire_si' => '']), []);

    expect($name)->toBe('Continental 225/45 R 17');
});
