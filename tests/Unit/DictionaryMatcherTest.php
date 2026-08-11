<?php

declare(strict_types=1);

use App\Domain\Tire\DictionaryMatcher;

it('returns the canonical spelling, not the one that was asked for', function (string $asked): void {
    expect(dictionary()->getMatchedCode($asked, 'seal'))->toBe('ContiSeal');
})->with(['ContiSeal', 'contiseal', 'CONTISEAL', '  ContiSeal  ']);

it('does not match a code from a different kind', function (): void {
    expect(dictionary()->getMatchedCode('XL', 'season'))->toBeNull()
        ->and(dictionary()->getMatchedCode('3PMSF', 'reinforcement'))->toBeNull();
});

it('returns null for an unknown kind', function (): void {
    expect(dictionary()->getMatchedCode('XL', 'nie_ma_takiego_rodzaju'))->toBeNull();
});

it('takes the first kind that claims the code', function (): void {
    // RF is both a reinforcement and a rim protector. Which one wins is decided
    // entirely by the order handed in, which is why the caller passes
    // VehicleTypeClassificationOrder rather than an arbitrary list.
    expect(dictionary()->matchParameterToFirstKind('RF', ['reinforcement', 'rim_protector']))
        ->toBe(['kind' => 'reinforcement', 'code' => 'RF'])
        ->and(dictionary()->matchParameterToFirstKind('RF', ['rim_protector', 'reinforcement']))
        ->toBe(['kind' => 'rim_protector', 'code' => 'RF']);
});

it('skips kinds that do not know the code', function (): void {
    expect(dictionary()->matchParameterToFirstKind('EV', ['reinforcement', 'season', 'ev']))
        ->toBe(['kind' => 'ev', 'code' => 'EV']);
});

it('returns null when no kind claims the code', function (): void {
    // AL turns up 55 times on Continentals and is in no dictionary at all.
    expect(dictionary()->matchParameterToFirstKind('AL', ['reinforcement', 'season', 'ev']))
        ->toBeNull();
});

it('returns null when the list of kinds is empty', function (): void {
    expect(dictionary()->matchParameterToFirstKind('XL', []))->toBeNull();
});

it('can be built from an empty dictionary without blowing up', function (): void {
    $empty = DictionaryMatcher::fromCodes([]);

    expect($empty->getMatchedCode('XL', 'reinforcement'))->toBeNull()
        ->and($empty->matchParameterToFirstKind('XL', ['reinforcement']))->toBeNull();
});
