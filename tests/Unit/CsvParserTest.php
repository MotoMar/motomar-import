<?php

declare(strict_types=1);

use App\Domain\Csv\CsvParser;

const CSV_COLUMNS = [
    'numkat1', 'numkat2', 'ean', 'id', 'producent', 'rodzaj',
    'bieznik', 'rozmiar', 'rozmiar2', 'indeksy', 'indeksy2', 'inne',
    'opor', 'mokre', 'halas', 'fale', 'eprel', 'netto',
];

function parser(): CsvParser
{
    return new CsvParser(CSV_COLUMNS);
}

/**
 * Writes a price list to a temporary file and hands back its path.
 *
 * @param string[] $lines
 */
function pricelist(array $lines): string
{
    $path = tempnam(sys_get_temp_dir(), 'pricelist');
    expect($path)->toBeString();
    file_put_contents($path, implode("\n", $lines) . "\n");

    return $path;
}

it('reads the fixture taken from real price lists', function (): void {
    $rows = parser()->parseFile(__DIR__ . '/../Fixtures/pricelist.csv');

    expect($rows)->toHaveCount(7)
        ->and($rows[0]->producerName)->toBe('Hankook')
        ->and($rows[0]->size)->toBe('205/50R17')
        ->and($rows[0]->price)->toBe(734.5)
        ->and($rows[0]->extra)->toBe('XL');
});

it('skips the header row', function (): void {
    $rows = parser()->parseFile(__DIR__ . '/../Fixtures/pricelist.csv');

    foreach ($rows as $row) {
        expect($row->producerName)->not->toBe('producent');
    }
});

it('recognises a header even when only one column name survives', function (): void {
    $path = pricelist([
        'kod@kod2@ean@id@producent@rodzaj@model@rozm@@idx@@inne@o@m@h@f@e@cena',
        '1@@8808563600802@@Hankook@O@Model@205/50R17@@93V@@XL@B@D@70@B@1@10,00',
    ]);

    expect(parser()->parseFile($path))->toHaveCount(1);

    unlink($path);
});

it('treats a first data row as data, not as a header', function (): void {
    $path = pricelist([
        '1@@8808563600802@@Hankook@O@Model@205/50R17@@93V@@XL@B@D@70@B@1@10,00',
    ]);

    expect(parser()->parseFile($path))->toHaveCount(1);

    unlink($path);
});

it('checks the header only on the first line', function (): void {
    // A stray header further down is not skipped — it blows up on the column
    // count instead, which is the loud failure and the one we want.
    $path = pricelist([
        '1@@8808563600802@@Hankook@O@Model@205/50R17@@93V@@XL@B@D@70@B@1@10,00',
        'numkat1@numkat2@ean@id@producent@rodzaj@bieznik@rozmiar@rozmiar2@indeksy@indeksy2@inne@opor@mokre@halas@fale@eprel@netto',
    ]);

    $rows = parser()->parseFile($path);

    expect($rows)->toHaveCount(2)
        ->and($rows[1]->producerName)->toBe('producent');

    unlink($path);
});

it('refuses a row with the wrong number of columns', function (): void {
    $path = pricelist([
        '1@@8808563600802@@Hankook@O@Model@205/50R17',
    ]);

    expect(fn () => parser()->parseFile($path))
        ->toThrow(RuntimeException::class, 'expected 18 columns, got 8');

    unlink($path);
});

it('ignores blank lines', function (): void {
    $path = pricelist([
        '1@@8808563600802@@Hankook@O@Model@205/50R17@@93V@@XL@B@D@70@B@1@10,00',
        '',
        '   ',
        '2@@8808563600796@@Hankook@O@Model@205/55R16@@94V@@XL@B@D@70@B@2@20,00',
    ]);

    expect(parser()->parseFile($path))->toHaveCount(2);

    unlink($path);
});

it('says which file it could not open', function (): void {
    expect(fn () => parser()->parseFile('/nie/ma/takiego/pliku.csv'))
        ->toThrow(RuntimeException::class, 'Cannot open CSV file');
});

it('counts unique producer and model pairs', function (): void {
    $rows = parser()->parseFile(__DIR__ . '/../Fixtures/pricelist.csv');
    $models = parser()->extractUniqueModels($rows);

    expect($models)->toHaveCount(6)
        ->and($models['Bridgestone|Potenza Sport']['count'])->toBe(2)
        ->and($models['Hankook|iON ST AS IH61']['count'])->toBe(1)
        ->and($models['Bridgestone|Potenza Sport']['producer_name'])->toBe('Bridgestone');
});

it('leaves out rows with no producer or no model', function (): void {
    $path = pricelist([
        '1@@8808563600802@@@O@Model@205/50R17@@93V@@XL@B@D@70@B@1@10,00',
        '2@@8808563600796@@Hankook@O@@205/55R16@@94V@@XL@B@D@70@B@2@20,00',
        '3@@8808563600789@@Hankook@O@Model@205/55R16@@94V@@XL@B@D@70@B@3@30,00',
    ]);

    $models = parser()->extractUniqueModels(parser()->parseFile($path));

    expect($models)->toHaveCount(1)
        ->and(array_key_first($models))->toBe('Hankook|Model');

    unlink($path);
});

function parserWithSeason(): CsvParser
{
    return new CsvParser(CSV_COLUMNS, ['sezon']);
}

it('reads the optional season column', function (): void {
    $path = pricelist([
        '1@@8808563600802@@Hankook@O@Model@205/50R17@@93V@@XL@B@D@70@B@1@@Letnia',
    ]);

    expect(parserWithSeason()->parseFile($path)[0]->season)->toBe('Letnia');

    unlink($path);
});

it('reads an 18-column line when the season column is optional', function (): void {
    $path = pricelist([
        '1@@8808563600802@@Hankook@O@Model@205/50R17@@93V@@XL@B@D@70@B@1@10,00',
    ]);

    $rows = parserWithSeason()->parseFile($path);

    expect($rows[0]->season)->toBe('')
        ->and($rows[0]->price)->toBe(10.0);

    unlink($path);
});

it('skips a header that names the optional column', function (): void {
    $path = pricelist([
        'numkat1@numkat2@ean@id@producent@rodzaj@bieznik@rozmiar@rozmiar2@indeksy@indeksy2@inne@opor@mokre@halas@fale@eprel@netto@sezon',
        '1@@8808563600802@@Hankook@O@Model@205/50R17@@93V@@XL@B@D@70@B@1@@Zimowa',
    ]);

    expect(parserWithSeason()->parseFile($path))->toHaveCount(1);

    unlink($path);
});

it('refuses a line longer than the layout and its optional columns', function (): void {
    $path = pricelist([
        '1@@8808563600802@@Hankook@O@Model@205/50R17@@93V@@XL@B@D@70@B@1@@Letnia@nadmiar',
    ]);

    expect(fn () => parserWithSeason()->parseFile($path))
        ->toThrow(RuntimeException::class, 'expected 18–19 columns, got 20');

    unlink($path);
});

it('counts the seasons each model\'s rows name', function (): void {
    $path = pricelist([
        '1@@8808563600802@@Hankook@O@Model@205/50R17@@93V@@XL@B@D@70@B@1@@Letnia',
        '2@@8808563600796@@Hankook@O@Model@205/55R16@@94V@@XL@B@D@70@B@2@@letnia',
        '3@@8808563600789@@Hankook@O@Model@215/55R16@@97V@@XL@B@D@70@B@3@@Zimowa',
        '4@@8808563600772@@Hankook@O@Model@225/55R16@@99V@@XL@B@D@70@B@4@@',
    ]);

    $parser = parserWithSeason();
    $models = $parser->extractUniqueModels($parser->parseFile($path));

    expect($models['Hankook|Model']['seasons'])->toBe(['letnia' => 2, 'zimowa' => 1])
        ->and($models['Hankook|Model']['count'])->toBe(4);

    unlink($path);
});
