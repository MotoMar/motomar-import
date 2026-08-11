<?php

declare(strict_types=1);

use App\Domain\Tire\DictionaryMatcher;

/**
 * A slice of the real `tires_dictionary`, kind by kind.
 *
 * Trimmed to the codes these tests exercise, but the shape is the production
 * one — including the codes that live under two kinds at once (`RF` is both a
 * reinforcement and a rim protector), because that overlap is exactly what the
 * classification order exists to resolve.
 *
 * @return array<string, string[]>
 */
function dictionaryCodes(): array
{
    return [
        'reinforcement'   => ['C', 'CP', 'EL', 'Extra Load', 'HL', 'RF', 'XL'],
        'runflat'         => ['DSST', 'EMT', 'ROF', 'RSC', 'Run Flat', 'SSR', 'ZP'],
        'rim_protector'   => ['FP', 'FR', 'FSL', 'MFS', 'RF'],
        'homologation'    => ['*', 'AO', 'AO2', 'MO', 'MOE', 'N0'],
        'seal'            => ['ContiSeal', 'SealInside'],
        'silent'          => ['ContiSilent', 'PNCS', 'Silent Core'],
        'season'          => ['3PMSF', 'M+S'],
        'ev'              => ['Elect', 'EV'],
        'tube_type'       => ['TL', 'TL/TT', 'TT', 'TT/TL'],
        'tire_technology' => ['Enliten', 'High Load', 'VF'],
        'studded'         => ['kolcowana'],
    ];
}

function dictionary(): DictionaryMatcher
{
    return DictionaryMatcher::fromCodes(dictionaryCodes());
}
