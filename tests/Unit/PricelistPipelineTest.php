<?php

declare(strict_types=1);

use App\Domain\Csv\CsvParser;
use App\Domain\Csv\TireRow;
use App\Domain\Tire\DictionaryMatcher;
use App\Domain\Tire\NameGenerator;
use App\Domain\Tire\SizeParser;
use App\Domain\Tire\SuffixExtractor;
use App\Domain\Tire\TireParametersBuilder;

/**
 * Runs a price list row through the whole chain that decides what a product is
 * called and what we know about it:
 *
 *     CSV → TireRow → tires.other → TireParametersBuilder
 *         → tires_classified_parameters → SuffixExtractor → NameGenerator
 *
 * The rows in `pricelist-hard.csv` are the awkward ones, taken from or modelled
 * on what production actually holds: codes that belong to two dictionary kinds
 * at once, three run-flat markers on one tire, a tube type spelled three
 * different ways in the same field, whole Polish sentences where a marker is
 * expected, and a Michelin homologation written with a star character.
 *
 * The dictionary is the real one, exported from the production copy, so a
 * result here is a result we would get on live data.
 */

/**
 * @return array<string, TireRow>
 */
function hardRows(): array
{
    static $rows = null;

    if ($rows === null) {
        $rows = [];

        foreach ((new CsvParser(CSV_COLUMNS))->parseFile(__DIR__ . '/../Fixtures/pricelist-hard.csv') as $row) {
            $rows[$row->ref1] = $row;
        }
    }

    return $rows;
}

function realDictionary(): DictionaryMatcher
{
    static $matcher = null;

    if ($matcher === null) {
        $json = file_get_contents(__DIR__ . '/../Fixtures/dictionary.json');
        expect($json)->toBeString();

        /** @var array<string, string[]> $codes */
        $codes = json_decode((string) $json, true);
        $matcher = DictionaryMatcher::fromCodes($codes);
    }

    return $matcher;
}

/**
 * @return array<string, string[]>
 */
function classifyHard(string $ref): array
{
    $row = hardRows()[$ref];

    return (new TireParametersBuilder())->buildParameters(
        ['other' => $row->extra, 'id_vehicles_type' => vehicleTypeOf($row)],
        realDictionary(),
    );
}

function nameHard(string $ref): string
{
    $row = hardRows()[$ref];
    $size = SizeParser::parseSize($row->size) ?? throw new RuntimeException("Nie parsuje: {$row->size}");
    $indices = SizeParser::parseIndices($row->indices);

    $li = null === $indices
        ? ''
        : ('' !== $indices['li2'] ? $indices['li'] . '/' . $indices['li2'] : $indices['li']);

    $tireRow = [
        'tire_id'          => 1,
        'producer'         => $row->producerName,
        'tread'            => $row->modelName,
        'tire_size'        => $size['width']
            . ('' !== $size['profile'] && '0' !== $size['profile'] ? '/' . $size['profile'] : '')
            . ' ' . $size['construction'],
        'tire_li'          => $li,
        'tire_si'          => null === $indices ? '' : $indices['si'],
        'id_vehicles_type' => vehicleTypeOf($row),
    ];

    return (new NameGenerator(new SuffixExtractor()))->generate($tireRow, classifyHard($ref));
}

function vehicleTypeOf(TireRow $row): int
{
    $shortcuts = ['O' => 1, 'D' => 2, 'T' => 3, 'C' => 4, 'R' => 5, 'P' => 6, 'M' => 7, 'S' => 10, 'G' => 9, 'Q' => 8];

    return $shortcuts[$row->vehicleTypeShortcut] ?? 0;
}

it('reads every row of the hard price list', function (): void {
    expect(hardRows())->toHaveCount(18);
});

// ---------------------------------------------------------------- collisions

it('gives a code that two kinds claim to the first kind in the order', function (): void {
    // RF is a reinforcement and a rim protector. For a passenger tire
    // reinforcement comes first, so rim_protector stays empty — the tire loses
    // its rim protector, and there is nothing in the data to say it had one.
    expect(classifyHard('H01'))->toBe([
        'reinforcement' => ['XL'],
        'tube_type'     => ['TL'],
    ]);
});

it('drops the C of a van tire when a stronger reinforcement is present', function (): void {
    // C;RE;RF puts C and RF in the same bucket and pickStrongest keeps RF.
    // C is what puts the C in "215/65 R 16C", so the size block loses it.
    expect(classifyHard('H02'))->toBe([
        'reinforcement' => ['RF'],
        'homologation'  => ['RE'],
        'tube_type'     => ['TL'],
    ]);

    expect(nameHard('H02'))->toBe('Michelin Agilis 3 215/65 R 16 109T RF RE');
});

it('keeps every run-flat spelling the supplier sent', function (): void {
    // RSC, DSROF and ROF are three names for the same thing on this tire, and
    // all three end up in the product name.
    expect(classifyHard('H03')['runflat'])->toBe(['RSC', 'DSROF', 'ROF']);

    expect(nameHard('H03'))
        ->toBe('Bridgestone Blizzak LM005 245/40 R 19 98W XL RSC DSROF ROF * 3PMSF M+S');
});

it('keeps two rim protectors when the supplier sends two', function (): void {
    $classified = classifyHard('H18');

    expect($classified['reinforcement'])->toBe(['XL'])
        ->and($classified['rim_protector'])->toBe(['FR', 'ML']);
});

// ------------------------------------------------------------- normalisation

it('collapses three spellings of one tube type into the canonical pair', function (): void {
    // TL, TL/TT and TT arrive in the same field. TL and TT merge into TL/TT,
    // which the string already contained, and the duplicate is removed.
    expect(classifyHard('H15')['tube_type'])->toBe(['TL/TT']);
});

it('canonicalises TT/TL and deduplicates it against TL/TT', function (): void {
    expect(classifyHard('H16')['tube_type'])->toBe(['TL/TT']);
});

it('matches case-insensitively and stores the dictionary spelling', function (): void {
    expect(classifyHard('H08'))->toBe([
        'reinforcement' => ['XL'],
        'seal'          => ['ContiSeal'],
        'season'        => ['M+S'],
    ]);
});

it('accepts the separator with spaces, without spaces, doubled and trailing', function (): void {
    expect(classifyHard('H06'))->toBe([
        'reinforcement' => ['XL'],
        'rim_protector' => ['FR'],
        'season'        => ['3PMSF', 'M+S'],
    ]);

    expect(classifyHard('H07'))->toBe([
        'reinforcement' => ['XL'],
        'rim_protector' => ['FR'],
    ]);
});

// ------------------------------------------------------------ unknown tokens

it('silently drops everything the dictionary does not know', function (): void {
    // Aramid appears 612 times in the downloaded price lists, SLT 111 times,
    // "+" 247 times and AL 55 times in the database. None is in the dictionary,
    // so this tire ends up with no classification at all and a bare name.
    expect(classifyHard('H09'))->toBe([]);
    expect(nameHard('H09'))->toBe('Ovation VI-682 175/70 R 13 82T');
});

it('drops the star spelling of a BMW homologation', function (): void {
    // The dictionary holds "*"; the price list sends "☆" and "MO/☆".
    // N-0 and MOE-S are the same story: the dictionary has N0 and MOE.
    expect(classifyHard('H10'))->toBe([]);
});

it('keeps the known markers of a row that also carries unknown ones', function (): void {
    // AL and "+" fall out, the rest survives.
    expect(classifyHard('H17'))->toBe([
        'reinforcement' => ['XL'],
        'seal'          => ['ContiSeal'],
        'silent'        => ['ContiSilent'],
        'ev'            => ['EV'],
    ]);
});

// --------------------------------------------------------- vehicle type gate

it('classifies only the kinds the vehicle type allows', function (): void {
    // A truck (type 4) has no `ev` in its order, so EV falls out even though
    // the dictionary knows it. Season stays, because trucks do carry M+S.
    expect(classifyHard('H14'))->toBe([
        'tube_type'  => ['TL'],
        'ply_rating' => ['16PR'],
        'season'     => ['M+S', '3PMSF'],
    ]);
});

it('produces nothing for a vehicle type outside the table', function (): void {
    // Shortcut 'M' maps to 7 and 'S' to 10 here; a shortcut the config does not
    // know resolves to 0, and type 0 has no order at all.
    $row = hardRows()['H15'];

    expect((new TireParametersBuilder())->buildParameters(
        ['other' => $row->extra, 'id_vehicles_type' => 0],
        realDictionary(),
    ))->toBe([]);
});

// ------------------------------------------------------------- the empty row

it('handles a row with no markers at all', function (): void {
    expect(classifyHard('H11'))->toBe([])
        ->and(nameHard('H11'))->toBe('Continental ContiCrossContact 265/70 R 16 112H');
});

// ------------------------------------------------------------------ the name

it('embeds C in the size block and leaves it out of the suffixes', function (): void {
    expect(nameHard('H13'))->toBe('Goodyear Wrangler AT Adventure 205 R 16C 110/108S M+S');
});

it('builds a name from an x-format size', function (): void {
    // OWL is a white wall code and type 3 does render it, between the season
    // and the EV marker.
    expect(nameHard('H12'))->toBe('Goodyear Wrangler DuraTrac RT 31X10.50 R 15 109Q FP M+S OWL EV');
});

it('orders suffixes by the vehicle type, not by the price list', function (): void {
    // The field reads FR;VOL;XL;NCS;… — the name puts reinforcement first.
    expect(nameHard('H04'))->toStartWith('Pirelli Scorpion Winter 295/35 R 23 108W XL FR');
});

it('keeps a homologation written in brackets', function (): void {
    expect(classifyHard('H05'))->toBe([
        'runflat'       => ['RFT'],
        'rim_protector' => ['(FR)'],
        'homologation'  => ['Lexus'],
        'tube_type'     => ['TL'],
    ]);
});

// ---------------------------------------------- kinds no vehicle type claims

it('classifies the motorcycle prose that the shop filters on', function (): void {
    // `purpose` carries the "Typ motocykla" filter on oponylux.pl: the dictionary
    // maps each supplier phrase to one of seven groups through its `value` and
    // `slug` columns. Leaving the kind out of the order meant a rebuild dropped
    // the whole filter.
    expect(classifyHard('H15'))->toBe([
        'tube_type'      => ['TL/TT'],
        'purpose'        => ['opona szosowa', 'opona do motocykli turystycznych', 'turystyczna'],
        'wheel_position' => ['tył'],
    ]);
});

it('keeps the prose out of the product name', function (): void {
    // Both kinds are classification-only. "opona do motocykli turystycznych" in
    // a product name would be a sentence where a marker belongs.
    expect(nameHard('H15'))->toBe('Dunlop K555 170/80 - 15 77H TL/TT');
});
