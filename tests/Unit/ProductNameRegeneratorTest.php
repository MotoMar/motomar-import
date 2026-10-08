<?php

declare(strict_types=1);

use App\Domain\Tire\DictionaryMatcher;
use App\Domain\Tire\NameGenerator;
use App\Domain\Tire\ProductNameRegenerator;
use App\Domain\Tire\SuffixExtractor;
use App\Domain\Tire\TireSelection;

/**
 * A row as TireDataFetcher returns it, already named consistently.
 *
 * @param array<string, mixed> $overrides
 *
 * @return array<string, mixed>
 */
function fetchedTire(array $overrides = []): array
{
    return $overrides + [
        'tire_id'                    => 94480,
        'producer'                   => 'Barum',
        'tread'                      => 'Bravuris 3HM',
        'tire_size'                  => '235/50 R 19',
        'tire_li'                    => '99',
        'tire_si'                    => 'V',
        'id_vehicles_type'           => 3,
        'other'                      => 'FR;TL',
        'current_name'               => 'Barum Bravuris 3HM 235/50 R 19 99V FR',
        'current_slug'               => 'barum-bravuris-3hm-235-50-r-19-99v-fr-94480',
        'current_model'              => 'Bravuris 3HM',
        'classified_parameters_json' => '{"rim_protector":["FR"],"tube_type":["TL"]}',
    ];
}

function regenerator(?DictionaryMatcher $matcher = null): ProductNameRegenerator
{
    return new ProductNameRegenerator(new NameGenerator(new SuffixExtractor()), $matcher);
}

describe('ProductNameRegenerator', function (): void {
    it('reports nothing for a product whose name is already what the import builds', function (): void {
        expect(regenerator()->change(fetchedTire())->isNoop())->toBeTrue();
    });

    it('picks up a renamed tread in the name, the slug and tires.tire_model', function (): void {
        $change = regenerator()->change(fetchedTire([
            'current_name'  => 'Barum Bravuris 3 HM 235/50 R 19 99V FR',
            'current_slug'  => 'barum-bravuris-3-hm-235-50-r-19-99v-fr-94480',
            'current_model' => 'Bravuris 3 HM',
        ]));

        expect($change->newName)->toBe('Barum Bravuris 3HM 235/50 R 19 99V FR')
            ->and($change->newSlug)->toBe('barum-bravuris-3hm-235-50-r-19-99v-fr-94480')
            ->and($change->nameChanged())->toBeTrue()
            ->and($change->newModel)->toBe('Bravuris 3HM')
            ->and($change->modelChanged())->toBeTrue();
    });

    it('builds the name from the stored classification unless asked to reclassify', function (): void {
        $row = fetchedTire(['other' => 'FR;TL;XL']);

        expect(regenerator()->change($row)->isNoop())->toBeTrue();

        $matcher = DictionaryMatcher::fromCodes([
            'reinforcement' => ['XL'],
            'rim_protector' => ['FR'],
            'tube_type'     => ['TL'],
        ]);
        $change = regenerator($matcher)->change($row);

        expect($change->reclassified)->toBe(['reinforcement' => ['XL'], 'rim_protector' => ['FR'], 'tube_type' => ['TL']])
            ->and($change->newName)->toBe('Barum Bravuris 3HM 235/50 R 19 99V XL FR');
    });

    it('does not count a reordered but equal classification as a change', function (): void {
        $matcher = DictionaryMatcher::fromCodes(['rim_protector' => ['FR'], 'tube_type' => ['TL']]);
        $row = fetchedTire(['classified_parameters_json' => '{"tube_type":["TL"],"rim_protector":["FR"]}']);

        expect(regenerator($matcher)->change($row)->reclassified)->toBeNull();
    });

    it('keeps kinds the classifier cannot produce for the vehicle type', function (): void {
        $matcher = DictionaryMatcher::fromCodes(['rim_protector' => ['FR'], 'tube_type' => ['TL']]);
        $row = fetchedTire(['classified_parameters_json' => '{"rim_protector":["FR"],"tube_type":["TL"],"purpose":["Touring"]}']);

        expect(regenerator($matcher)->change($row)->parameters)->toHaveKey('purpose');
    });
});

describe('TireSelection', function (): void {
    it('binds the ids of the chosen column', function (): void {
        [$where, $params] = TireSelection::fromOptions(['tread' => '5833,6236'])->where();

        expect($where)->toBe('t.id_tires_tread IN (:sel0, :sel1)')
            ->and($params)->toBe([':sel0' => 5833, ':sel1' => 6236]);
    });

    it('selects everything only when asked to in so many words', function (): void {
        expect(TireSelection::fromOptions(['all' => false])->isAll())->toBeTrue()
            ->and(TireSelection::fromOptions(['all' => false])->where())->toBe(['1 = 1', []]);
    });

    it('refuses an empty list rather than reading it as everything', function (): void {
        TireSelection::treads([]);
    })->throws(InvalidArgumentException::class);

    it('requires exactly one criterion', function (array $options): void {
        TireSelection::fromOptions($options);
    })->with([
        'none' => [[]],
        'two'  => [['tread' => '1', 'producer' => '2']],
    ])->throws(InvalidArgumentException::class);

    it('rejects anything but comma separated ids', function (): void {
        TireSelection::fromOptions(['tire' => '1; DROP TABLE tires']);
    })->throws(InvalidArgumentException::class);
});
