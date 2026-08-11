<?php

declare(strict_types=1);

use App\Domain\Tire\SizeParser;

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
    expect(SizeParser::parseSize($size))->toBe([
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
    // 42 rows — no profile in the string, stored as "0" rather than empty
    'no profile'      => ['205R16', '205', '0', 'R 16', '16'],
]);

it('parses the formats kept for lists we have not seen yet', function (
    string $size,
    string $width,
    string $profile,
    string $construction,
    string $diameter,
): void {
    expect(SizeParser::parseSize($size))->toBe([
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
    expect(SizeParser::parseSize('195/70RC15'))->toBe([
        'width'        => '195',
        'profile'      => '70',
        'construction' => 'RC 15',
        'diameter'     => '15',
    ]);
});

it('drops a decimal point from the width of the branch that has no profile', function (): void {
    // Branch 9 strips the dot: 7.50R16 becomes width 750, not 7.50. Branch 8
    // never sees this input because it requires an integer width.
    $size = SizeParser::parseSize('7.50R16') ?? throw new RuntimeException('rozmiar nie sparsował się');

    expect($size['width'])->toBe('750');
});

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
