<?php

declare(strict_types=1);

use App\Domain\Tire\TreadRename;
use App\Domain\Tire\TreadRenamePlan;

/** @return array{model: string, tread_id: int, producer_id: int, current: string, new: string} */
function renameRequest(int $treadId, string $current, string $new, string $model = 'All Season Fixclime SP-401', int $producerId = 7): array
{
    return ['model' => $model, 'tread_id' => $treadId, 'producer_id' => $producerId, 'current' => $current, 'new' => $new];
}

/** @return array<int, list<array{id: int, tread: string}>> */
function barumTreads(): array
{
    return [7 => [
        ['id' => 8847, 'tread' => 'SP-401'],
        ['id' => 5833, 'tread' => 'Bravuris 3 HM'],
        ['id' => 6236, 'tread' => 'Bravuris 3HM'],
        ['id' => 4100, 'tread' => 'R168'],
    ]];
}

describe('TreadRenamePlan', function (): void {
    it('renames the tread picked in the select to the edited name', function (): void {
        $plan = TreadRenamePlan::build([renameRequest(8847, 'SP-401', '  Fixclime   SP-401 ')], barumTreads());

        expect($plan->errors)->toBe([])
            ->and(array_map(fn (TreadRename $r) => $r->toArray(), $plan->renames))
            ->toBe([['tread_id' => 8847, 'producer_id' => 7, 'old' => 'SP-401', 'new' => 'Fixclime SP-401']]);
    });

    it('treats the current name as no rename at all', function (): void {
        $plan = TreadRenamePlan::build([renameRequest(8847, 'SP-401', 'SP-401')], barumTreads());

        expect($plan->errors)->toBe([])->and($plan->renames)->toBe([]);
    });

    it('refuses an empty name', function (): void {
        expect(TreadRenamePlan::build([renameRequest(8847, 'SP-401', "  \t ")], barumTreads())->errors)
            ->toHaveCount(1);
    });

    it('refuses a name another tread of the producer already has, spelled differently or not', function (string $new): void {
        $plan = TreadRenamePlan::build([renameRequest(8847, 'SP-401', $new)], barumTreads());

        expect($plan->renames)->toBe([])
            ->and($plan->errors[0] ?? '')->toContain('scalenie');
    })->with(['same spelling' => 'Bravuris 3HM', 'other spacing and case' => 'bravuris-3 hm']);

    it('allows fixing the spelling of the tread itself', function (): void {
        $plan = TreadRenamePlan::build([renameRequest(4100, 'R168', 'R 168')], barumTreads());

        expect($plan->errors)->toBe([]);
    });

    it('keeps a plus apart, because it is a different model', function (): void {
        $plan = TreadRenamePlan::build([renameRequest(8847, 'SP-401', 'R168+')], barumTreads());

        expect($plan->errors)->toBe([]);
    });

    it('ignores treads of other producers', function (): void {
        $plan = TreadRenamePlan::build([renameRequest(8847, 'SP-401', 'Bravuris 3HM', producerId: 9)], barumTreads());

        expect($plan->errors)->toBe([]);
    });

    it('refuses two different names for one tread, and merges two equal ones', function (): void {
        $conflict = TreadRenamePlan::build([
            renameRequest(8847, 'SP-401', 'Fixclime SP-401', 'All Season Fixclime SP-401'),
            renameRequest(8847, 'SP-401', 'Fixclime SP401', 'Fixclime SP401'),
        ], barumTreads());
        $same = TreadRenamePlan::build([
            renameRequest(8847, 'SP-401', 'Fixclime SP-401', 'All Season Fixclime SP-401'),
            renameRequest(8847, 'SP-401', 'Fixclime SP-401', 'Fixclime SP-401'),
        ], barumTreads());

        expect($conflict->errors)->toHaveCount(1)
            ->and($conflict->renames)->toBe([])
            ->and($same->errors)->toBe([])
            ->and($same->renames)->toHaveCount(1);
    });

    it('refuses giving two treads one name in the same import', function (): void {
        $plan = TreadRenamePlan::build([
            renameRequest(8847, 'SP-401', 'Fixclime'),
            renameRequest(4100, 'R168', 'FIXCLIME', 'R168 X'),
        ], barumTreads());

        expect($plan->errors)->toHaveCount(1)->and($plan->renames)->toBe([]);
    });

    it('refuses a name this import also creates as a new tread', function (): void {
        $plan = TreadRenamePlan::build([renameRequest(8847, 'SP-401', 'Quartaris 6')], barumTreads(), [7 => ['QUARTARIS 6']]);

        expect($plan->errors)->toHaveCount(1);
    });
});
