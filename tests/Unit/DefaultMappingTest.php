<?php

declare(strict_types=1);

use App\Domain\Import\DefaultMapping;

/** @return array<int, array<string, mixed>> */
function mappingSeasons(): array
{
    return [
        ['id' => 1, 'season' => 'Letnia'],
        ['id' => 2, 'season' => 'Zimowa'],
        ['id' => 3, 'season' => 'Całoroczna'],
    ];
}

/**
 * @param array<string, int> $seasons
 *
 * @return array<string, mixed>
 */
function csvModel(string $producer, string $model, array $seasons = []): array
{
    return ['producer_name' => $producer, 'model_name' => $model, 'count' => array_sum($seasons) ?: 1, 'seasons' => $seasons];
}

it('maps a model to the tread of the same name regardless of case, with the tread season', function (): void {
    $mapping = DefaultMapping::build(
        ['Leao|nova-force' => csvModel('Leao', 'nova-force', ['zimowa' => 3])],
        ['Leao'],
        ['Leao' => [['id' => 7, 'tread' => 'Nova-Force', 'season_id' => 1], ['id' => 8, 'tread' => 'Nova-Force Acro', 'season_id' => 1]]],
        mappingSeasons(),
    );

    expect($mapping->problems)->toBe([])
        ->and($mapping->mapping['Leao|nova-force'])->toBe([
            'producer_name' => 'Leao',
            'model_name'    => 'nova-force',
            'tread_id'      => 7,
            'season_id'     => 1,
            'is_new'        => false,
        ]);
});

it('makes a new model with the season most rows name when no tread matches', function (): void {
    $mapping = DefaultMapping::build(
        ['Leao|Winter Defender' => csvModel('Leao', 'Winter Defender', ['zimowa' => 4, 'całoroczna' => 1])],
        ['Leao'],
        ['Leao' => [['id' => 7, 'tread' => 'Winter Defender HP', 'season_id' => 2]]],
        mappingSeasons(),
    );

    expect($mapping->problems)->toBe([])
        ->and($mapping->newModels())->toBe(['Leao|Winter Defender' => [
            'producer_name' => 'Leao',
            'model_name'    => 'Winter Defender',
            'tread_id'      => 0,
            'season_id'     => 2,
            'is_new'        => true,
        ]]);
});

it('rejects a new model whose season the file leaves open, unless the operator names it', function (): void {
    $models = ['Lanvigator|Ice Power' => csvModel('Lanvigator', 'Ice Power', ['zimowa' => 1, 'całoroczna' => 1])];
    $treads = ['Lanvigator' => []];

    $open = DefaultMapping::build($models, ['Lanvigator'], $treads, mappingSeasons());
    $named = DefaultMapping::build($models, ['Lanvigator'], $treads, mappingSeasons(), ['Lanvigator|Ice Power' => 2]);

    expect($open->mapping)->toBe([])
        ->and($open->problems)->toHaveCount(1)
        ->and($open->problems[0])->toContain('Ice Power')
        ->and($named->problems)->toBe([])
        ->and($named->mapping['Lanvigator|Ice Power']['season_id'])->toBe(2);
});

it('rejects a model when two treads of the producer carry its name', function (): void {
    $mapping = DefaultMapping::build(
        ['Nexen|Winguard' => csvModel('Nexen', 'Winguard', ['zimowa' => 1])],
        ['Nexen'],
        ['Nexen' => [['id' => 3, 'tread' => 'Winguard', 'season_id' => 2], ['id' => 9, 'tread' => 'WinGuard', 'season_id' => 2]]],
        mappingSeasons(),
    );

    expect($mapping->mapping)->toBe([])
        ->and($mapping->problems)->toBe(['Nexen / Winguard: kilka bieżników o tej nazwie (id 3, 9)']);
});

it('rejects a producer that is not in the database instead of creating it', function (): void {
    $mapping = DefaultMapping::build(['Nowa|X' => csvModel('Nowa', 'X', ['letnia' => 1])], ['Nowa'], [], mappingSeasons());

    expect($mapping->mapping)->toBe([])
        ->and($mapping->problems)->toBe(['producenta „Nowa” nie ma w bazie']);
});

it('rejects an unknown producer even when its row has no model', function (): void {
    // wiersz bez bieżnika nie trafia do extractUniqueModels(), a import i tak założyłby producenta
    $mapping = DefaultMapping::build(
        ['Leao|Nova-Force' => csvModel('Leao', 'Nova-Force', ['letnia' => 1])],
        ['Leao', 'Nowa', ''],
        ['Leao' => [['id' => 7, 'tread' => 'Nova-Force', 'season_id' => 1]]],
        mappingSeasons(),
    );

    expect($mapping->problems)->toBe(['producenta „Nowa” nie ma w bazie']);
});
