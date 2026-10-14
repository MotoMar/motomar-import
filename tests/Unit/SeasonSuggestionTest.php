<?php

declare(strict_types=1);

use App\Domain\Import\SeasonSuggestion;

const SEASONS = [
    ['id' => 1, 'season' => 'Letnia'],
    ['id' => 2, 'season' => 'Zimowa'],
    ['id' => 3, 'season' => 'Całoroczna'],
];

it('suggests the season most rows name', function (): void {
    $suggestion = SeasonSuggestion::from(['zimowa' => 12, 'letnia' => 1], SEASONS);

    expect($suggestion->seasonId)->toBe(2)
        ->and($suggestion->needsAttention)->toBeTrue();
});

it('matches the season name without regard to case or Polish letters case', function (): void {
    expect(SeasonSuggestion::from(['całoroczna' => 3], SEASONS)->seasonId)->toBe(3);
});

it('suggests nothing on a tie', function (): void {
    $suggestion = SeasonSuggestion::from(['letnia' => 2, 'zimowa' => 2], SEASONS);

    expect($suggestion->seasonId)->toBeNull()
        ->and($suggestion->needsAttention)->toBeTrue();
});

it('ignores a name outside the seasons table but asks for a look', function (): void {
    $suggestion = SeasonSuggestion::from(['lato' => 5, 'letnia' => 1], SEASONS);

    expect($suggestion->seasonId)->toBe(1)
        ->and($suggestion->needsAttention)->toBeTrue();
});

it('suggests nothing quietly when the file has no season', function (): void {
    $suggestion = SeasonSuggestion::from([], SEASONS);

    expect($suggestion->seasonId)->toBeNull()
        ->and($suggestion->needsAttention)->toBeFalse();
});

it('suggests without a warning when every row agrees', function (): void {
    $suggestion = SeasonSuggestion::from(['letnia' => 7], SEASONS);

    expect($suggestion->seasonId)->toBe(1)
        ->and($suggestion->needsAttention)->toBeFalse();
});
