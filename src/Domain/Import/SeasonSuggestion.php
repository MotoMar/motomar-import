<?php

declare(strict_types=1);

namespace App\Domain\Import;

use App\Domain\Tire\RowField;

/**
 * Sezon nowego modelu podpowiadany przez kolumnę `sezon` pliku (AKN-759).
 *
 * Zaznaczamy sezon, który podaje najwięcej wierszy modelu, i tylko wtedy, gdy
 * to nazwa z `tires_seasons`. Remis albo same nieznane nazwy nie zaznaczają
 * nic — wybór zostaje człowieka, a krok 3 pokazuje mu liczby.
 */
final readonly class SeasonSuggestion
{
    /**
     * @param array<string, int> $counts ile wierszy modelu podaje który sezon, małymi literami, jak w pliku
     */
    private function __construct(
        public ?int $seasonId,
        public array $counts,
        public bool $needsAttention,
    ) {
    }

    /**
     * @param array<string, int>                $counts  z `CsvParser::extractUniqueModels()`
     * @param array<int, array<string, mixed>> $seasons z `TireRepository::allSeasons()`
     */
    public static function from(array $counts, array $seasons): self
    {
        $ids = [];

        foreach ($seasons as $season) {
            $ids[mb_strtolower(RowField::text($season, 'season'))] = RowField::integer($season, 'id');
        }

        $known = array_intersect_key($counts, $ids);
        arsort($known);
        $ranked = array_values($known);
        $first  = array_key_first($known);
        $wins   = $first !== null && (count($ranked) === 1 || $ranked[0] > $ranked[1]);

        return new self(
            $wins ? $ids[$first] : null,
            $counts,
            // rozbieżne wiersze albo nazwa spoza słownika sezonów: człowiek ma to zobaczyć
            count($counts) > 1 || count($known) < count($counts),
        );
    }
}
