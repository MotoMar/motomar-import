<?php

declare(strict_types=1);

use App\Domain\Tire\LiSiFormatter;
use App\Domain\Tire\SlugGenerator;

it('lowercases and hyphenates a product name', function (): void {
    expect(SlugGenerator::generate('Continental PremiumContact 6 225/45 R 17 94Y XL'))
        ->toBe('continental-premiumcontact-6-225-45-r-17-94y-xl');
});

it('transliterates Polish characters', function (): void {
    expect(SlugGenerator::generate('Opona Zimowa Świeża Łódź'))->toBe('opona-zimowa-swieza-lodz');
});

it('collapses runs of separators and trims the edges', function (): void {
    expect(SlugGenerator::generate('  *** Nokian // Hakka  '))->toBe('nokian-hakka');
});

it('keeps homologation markers out of the slug entirely', function (): void {
    // "(FR)" and "*" are legitimate codes in the name and nothing in a URL.
    expect(SlugGenerator::generate('Michelin Primacy 4 * (FR)'))->toBe('michelin-primacy-4-fr');
});

it('formats the load and speed pair', function (string $li, string $si, string $expected): void {
    expect(LiSiFormatter::format($li, $si))->toBe($expected);
})->with([
    'single'      => ['91', 'H', '91H'],
    'dual load'   => ['121/118', 'S', '121/118S'],
    'dual speed'  => ['91', 'H/V', '91H/V'],
    'dual both'   => ['116/114', 'R/S', '116R/114S'],
    'padded'      => [' 91 ', ' H ', '91H'],
    'no speed'    => ['91', '', ''],
    'no load'     => ['', 'H', ''],
]);
