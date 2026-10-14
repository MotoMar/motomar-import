<?php

declare(strict_types=1);

use App\Domain\Csv\TireRow;
use App\Eprel\Answer;
use App\Eprel\Names;
use App\Eprel\PricelistCheck;
use App\Eprel\Registration;
use App\Eprel\RegistrationFetcher;
use App\Eprel\ResponseCache;
use App\Eprel\TyreSize;

/** @param array<string, string> $fields */
function pricelistRow(array $fields = []): TireRow
{
    return new TireRow($fields + [
        'numkat1' => 'K1', 'numkat2' => '', 'ean' => '4000000000006', 'id' => '', 'producent' => 'Hankook',
        'rodzaj' => 'O', 'bieznik' => 'Ventus Prime 4', 'rozmiar' => '205/55R16', 'rozmiar2' => '',
        'indeksy' => '91V', 'indeksy2' => '', 'inne' => '', 'opor' => 'C', 'mokre' => 'B', 'halas' => '71',
        'fale' => 'B', 'eprel' => '123', 'netto' => '',
    ]);
}

/** @param array<string, mixed> $fields */
function registration(array $fields = []): Registration
{
    $json = $fields + [
        'eprelRegistrationNumber' => 123, 'supplierOrTrademark' => 'HANKOOK', 'sizeDesignation' => '205/55 R16',
        'tyreDesignation' => '205/55 R16 91 V', 'loadCapacityIndex' => 91, 'speedCategorySymbol' => 'V',
        'energyClass' => 'C', 'wetGripClass' => 'B', 'externalRollingNoiseValue' => 71, 'externalRollingNoiseClass' => 'B',
        'severeSnowTyre' => false, 'loadCapacityIndicator' => 'SL', 'tyreClass' => 'C1', 'status' => 'PUBLISHED',
    ];
    $registration = Registration::fromApi($json);
    expect($registration)->not->toBeNull();
    assert(null !== $registration);

    return $registration;
}

/** @return list<string> */
function described(TireRow $row, Registration $registration): array
{
    return array_map(static fn ($m): string => $m->describe(), PricelistCheck::mismatches($row, $registration));
}

it('reads the four ways a size is written, alike on both sides', function (string $ours, string $theirs): void {
    $parsed = TyreSize::parse($ours);

    expect($parsed)->not->toBeNull();
    expect(TyreSize::parse($theirs))->toEqual($parsed);
})->with([
    'metric' => ['205/55R16', '205/55 R16'],
    'run-flat ZR' => ['275/35R20', '275/35ZRF20'],
    'supplier\'s RR typo' => ['325/30R21', '325/30 ZRR21'],
    'inch' => ['7.50R16', '7.50 R16'],
    'no profile' => ['185R14', '185 R14 C'],
    'flotation' => ['31x10.50R15', '31X10.50R15LT'],
    'flotation with ×' => ['31x10.50R15', '31×10.50R15LT 6PR'],
    'flotation with *' => ['30x9.50R15', '30*9.50R15LT'],
    'flotation with a dash' => ['31x10.50R15', '31×10.50-15LT'],
    'inch written without the dot' => ['5.00R12', '500R12'],
    'inch, van' => ['7.50R16', '750R16C'],
    'inch, four digits' => ['10.00R20', '1000R20'],
]);

it('keeps a metric width without profile metric', function (): void {
    expect(TyreSize::parse('205R16C')?->width)->toBe('205');
});

it('does not read an inch size as its fraction', function (): void {
    expect(TyreSize::parse('7.50R16')?->width)->toBe('7.5');
});

it('agrees with a registration that says the same', function (): void {
    expect(described(pricelistRow(), registration()))->toBe([]);
});

it('names every field that differs, in the form apply_eprel.py reads', function (): void {
    $theirs = registration([
        'sizeDesignation' => '205/60 R16', 'loadCapacityIndex' => 92, 'speedCategorySymbol' => 'H',
        'energyClass' => 'D', 'externalRollingNoiseValue' => 72, 'severeSnowTyre' => true,
        'loadCapacityIndicator' => 'XL', 'tyreClass' => 'C2',
    ]);

    expect(described(pricelistRow(), $theirs))->toBe([
        'profil: 55 → 60', 'indeks nośności: 91 → 92', 'indeks prędkości: V → H', 'opór toczenia: C → D',
        'hałas dB: 71 → 72', '3PMSF: nie → tak', 'XL: nie → tak', 'C: nie → tak',
    ]);
});

it('compares the EAN with the GTIN the registration gives', function (): void {
    expect(described(pricelistRow(), registration(['additionalDetails' => ['gtinIdentifier' => '04000000000013']])))
        ->toBe(['EAN: 4000000000006 → 04000000000013']);
});

it('reads EPREL\'s parenthesised speed index', function (): void {
    expect(described(pricelistRow(['indeksy' => '91Y']), registration(['speedCategorySymbol' => 'PAR_Y_PAR'])))->toBe([]);
});

it('takes M+S only from a registration that declares it', function (): void {
    expect(described(pricelistRow(['inne' => 'M+S']), registration()))->toBe([])
        ->and(described(pricelistRow(), registration(['additionalDetails' => ['mudSnow' => true]])))->toBe(['M+S: nie → tak']);
});

it('does not hold a missing aspect ratio against a full-profile tyre', function (): void {
    expect(described(pricelistRow(['rozmiar' => '205/82R16', 'indeksy' => '110/108R', 'inne' => 'C']),
        registration(['sizeDesignation' => '205 R16 C', 'loadCapacityIndex' => 110, 'loadCapacityIndex2' => 108, 'speedCategorySymbol' => 'R', 'tyreClass' => 'C2'])))
        ->toBe([]);
});

it('treats a brand with a word glued on as the same brand', function (string $theirs, bool $same): void {
    expect(Names::sameBrand($theirs, 'Nexen'))->toBe($same);
})->with([
    ['NEXENTIRE', true],
    ['NEXEN TIRE', true],
    ['nexen', true],
    ['ROADSTONE', false],
]);

it('folds Polish letters before comparing brands', function (): void {
    expect(Names::sameBrand('DEBICA', 'Dębica'))->toBeTrue()
        ->and(Names::sameBrand('GT', 'GT Radial'))->toBeFalse();
});

it('counts as decisive only what EPREL decided', function (int $status, string $body, bool $decisive): void {
    expect((new Answer($status, $body))->isDecisive())->toBe($decisive);
})->with([
    'registration' => [200, '{"eprelRegistrationNumber":1}', true],
    'HTML shell' => [200, '<html>', false],
    'missing' => [404, '', true],
    'refused with a reason' => [403, '{"code":"UNVERIFIED_SUPPLIER"}', true],
    'bare 403, our missing Referer' => [403, '', false],
    'rate limit' => [429, '{"code":"TOO_MANY"}', false],
    'server error' => [503, '', false],
    'never arrived' => [0, '', false],
]);

it('keeps decisive answers on disk and forgets old ones', function (): void {
    $directory = sys_get_temp_dir() . '/eprel-cache-test-' . bin2hex(random_bytes(4));
    $cache = new ResponseCache($directory, 60);
    $cache->put(1001, new Answer(404, ''));
    $cache->put(1002, new Answer(503, ''));

    expect($cache->get(1001)?->status)->toBe(404)
        ->and($cache->get(1002))->toBeNull();

    touch($directory . '/001/1001.json', time() - 120);
    expect($cache->get(1001))->toBeNull();

    array_map(unlink(...), glob($directory . '/*/*.json') ?: []);
    array_map(rmdir(...), glob($directory . '/*', GLOB_ONLYDIR) ?: []);
    rmdir($directory);
});

it('retries what was not decided and gives up after the last round', function (): void {
    $calls = [];
    $waits = [];
    $fetcher = new RegistrationFetcher(
        null,
        retryWaits: [5, 20],
        sleep: static function (int $seconds) use (&$waits): void {
            $waits[] = $seconds;
        },
        transport: static function (array $numbers, Closure $receive) use (&$calls): void {
            $calls[] = $numbers;

            foreach ($numbers as $number) {
                // 1 odpowiada od razu, 2 za drugim razem, 3 nigdy
                $receive($number, 1 === $number || (2 === $number && count($calls) > 1) ? new Answer(404, '') : new Answer(429, ''));
            }
        },
    );

    $answers = $fetcher->fetch([1, 2, 3, 3]);

    expect($calls)->toBe([[1, 2, 3], [2, 3], [3]])
        ->and($waits)->toBe([5, 20])
        ->and($answers[2]->status)->toBe(404)
        ->and($answers[3]->status)->toBe(429);
});

it('does not retry a bare refusal, which only deepens a block', function (): void {
    $calls = 0;
    $fetcher = new RegistrationFetcher(null, retryWaits: [5], sleep: static function (int $s): void {
    }, transport: static function (array $numbers, Closure $receive) use (&$calls): void {
        ++$calls;
        array_map(static fn (int $n) => $receive($n, new Answer(403, '<html>')), $numbers);
    });

    expect($fetcher->fetch([1, 2])[1]->status)->toBe(403)
        ->and($calls)->toBe(1);
});

it('stops before the rest when the first numbers decide nothing', function (): void {
    $asked = [];
    $fetcher = new RegistrationFetcher(null, transport: static function (array $numbers, Closure $receive) use (&$asked): void {
        $asked[] = count($numbers);
        array_map(static fn (int $n) => $receive($n, new Answer(403, '<html>')), $numbers);
    });

    expect(fn () => $fetcher->fetch(range(1, 25)))->toThrow(RuntimeException::class, 'blokada')
        ->and($asked)->toBe([20]);
});

it('goes on past the probe once something was decided', function (): void {
    $asked = [];
    $fetcher = new RegistrationFetcher(null, transport: static function (array $numbers, Closure $receive) use (&$asked): void {
        $asked[] = count($numbers);
        array_map(static fn (int $n) => $receive($n, new Answer(404, '')), $numbers);
    });

    expect($fetcher->fetch(range(1, 25)))->toHaveCount(25)
        ->and($asked)->toBe([20, 5]);
});
