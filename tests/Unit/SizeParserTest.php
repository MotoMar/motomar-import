<?php

declare(strict_types=1);

use App\Domain\Tire\SizeParser;

/**
 * The dictionary half of a parsed size — the part the product name is built from.
 *
 * @param array<string, string>|null $size
 *
 * @return array<string, string>|null
 */
function dictionaryPart(?array $size): ?array
{
    return $size === null ? null : array_diff_key($size, ['column_width' => true, 'column_profile' => true]);
}

/**
 * The ten branches are tried in order and the first match wins, so a size that
 * two patterns could accept is decided by their order in the method. The cases
 * below name which branch they are pinning.
 */
it('parses the three shapes that actually arrive in price lists', function (
    string $size,
    string $width,
    string $profile,
    string $construction,
    string $diameter,
): void {
    expect(dictionaryPart(SizeParser::parseSize($size)))->toBe([
        'width'        => $width,
        'profile'      => $profile,
        'construction' => $construction,
        'diameter'     => $diameter,
    ]);
})->with([
    // 13 446 of 13 549 rows in the downloaded price lists
    'metric'          => ['205/50R17', '205', '50', 'R 17', '17'],
    'metric half rim' => ['205/65R17.5', '205', '65', 'R 17.5', '17.5'],
    // 61 rows
    'x-format'        => ['31x10.50R15', '31X10.50', '', 'R 15', '15'],
    // 42 rows
    'no profile'      => ['205R16', '205', '', 'R 16', '16'],
]);

it('parses the formats kept for lists we have not seen yet', function (
    string $size,
    string $width,
    string $profile,
    string $construction,
    string $diameter,
): void {
    expect(dictionaryPart(SizeParser::parseSize($size)))->toBe([
        'width'        => $width,
        'profile'      => $profile,
        'construction' => $construction,
        'diameter'     => $diameter,
    ]);
})->with([
    'metric dash'      => ['100/90-19', '100', '90', '- 19', '19'],
    'agricultural'     => ['13.6/12-38', '13.6', '12', '- 38', '38'],
    'x with dash'      => ['16x6.50-8', '16X6.50', '', '- 8', '8'],
    'x without rim'    => ['26x2.00', '26X2.00', '', '-', ''],
    'moto alpha'       => ['MT90B16', 'MT90', '', 'B 16', '16'],
    'moto alpha dash'  => ['MH90-21', 'MH90', '', '- 21', '21'],
    'moto no separator' => ['MT9016', 'MT90', '', '- 16', '16'],
    'load range'       => ['11L-14', '11', '', 'L 14', '14'],
    'load range LR'    => ['11LR16', '11', '', 'LR 16', '16'],
    'load range space' => ['14.9 LR20', '14.9', '', 'LR 20', '20'],
    'diagonal'         => ['11.2-24', '11.2', '', '- 24', '24'],
]);

it('normalises case and surrounding whitespace', function (): void {
    expect(SizeParser::parseSize('  205/55r16  '))->toBe(SizeParser::parseSize('205/55R16'));
});

it('keeps the C of a van size inside the construction, not the width', function (): void {
    // 195/70R15C — the C belongs to the letters block, so construction is "RC 15"
    expect(dictionaryPart(SizeParser::parseSize('195/70RC15')))->toBe([
        'width'        => '195',
        'profile'      => '70',
        'construction' => 'RC 15',
        'diameter'     => '15',
    ]);
});

it('builds the size block of the name without a zero profile', function (string $input, string $block): void {
    // The concatenation TireDataFetcher::BASE_SELECT does on the dictionary
    // values. A profile of "0" used to come out as "750/0 R 16" (AKN-754).
    $size = SizeParser::parseSize($input) ?? throw new RuntimeException('rozmiar nie sparsował się');

    expect($size['width'] . ($size['profile'] !== '' ? '/' . $size['profile'] : '') . ' ' . $size['construction'])
        ->toBe($block);
})->with([
    'metric'          => ['205/55R16', '205/55 R 16'],
    'no profile'      => ['195R14', '195 R 14'],
    'decimal width'   => ['7.50R16', '7.50 R 16'],
    'truck'           => ['12.00R20', '12.00 R 20'],
]);

it('keeps the column values the Erli export reads for a size without a profile', function (
    string $input,
    string $width,
    string $profile,
): void {
    $size = SizeParser::parseSize($input) ?? throw new RuntimeException('rozmiar nie sparsował się');

    expect([$size['column_width'], $size['column_profile']])->toBe([$width, $profile]);
})->with([
    // 772 of 780 such tires with a millimetre width hold 80 on motomar_dev
    'millimetre width' => ['205R16', '205', '80'],
    'inch width'       => ['18R22.5', '18', ''],
    // the columns never had the dot: 12.00R20 is 1200
    'decimal width'    => ['7.50R16', '750', ''],
]);

it('copies the dictionary values into the columns for every other shape', function (string $input): void {
    $size = SizeParser::parseSize($input) ?? throw new RuntimeException('rozmiar nie sparsował się');

    expect([$size['column_width'], $size['column_profile']])->toBe([$size['width'], $size['profile']]);
})->with(['205/55R16', '31x10.50R15', '100/90-19', 'MT90B16', '11.2-24']);

it('returns null for anything it cannot recognise', function (string $size): void {
    expect(SizeParser::parseSize($size))->toBeNull();
})->with([
    'empty'       => '',
    'whitespace'  => '   ',
    'words'       => 'brak rozmiaru',
    'digits only' => '205',
    'letters'     => 'ABC',
]);

it('parses load and speed indices', function (string $input, ?array $expected): void {
    expect(SizeParser::parseIndices($input))->toBe($expected);
})->with([
    'single'     => ['93V', ['li' => '93', 'li2' => '', 'si' => 'V']],
    'dual load'  => ['110/108S', ['li' => '110', 'li2' => '108', 'si' => 'S']],
    'lowercase'  => ['93v', ['li' => '93', 'li2' => '', 'si' => 'V']],
    'padded'     => [' 93V ', ['li' => '93', 'li2' => '', 'si' => 'V']],
    'multiletter' => ['91ZR', ['li' => '91', 'li2' => '', 'si' => 'ZR']],
    'empty'      => ['', null],
    'dual speed' => ['91H/V', null],   // not supported, unlike LiSiFormatter
    'no digits'  => ['V', null],
    'no letters' => ['93', null],
]);
