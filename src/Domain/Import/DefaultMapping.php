<?php

declare(strict_types=1);

namespace App\Domain\Import;

use App\Domain\Tire\RowField;

/**
 * Mapowanie, które kreator podsuwa operatorowi, gdy ten tylko klika „dalej” (AKN-768).
 *
 * Krok 2 zaznacza istniejący bieżnik o tej samej nazwie (`strtolower`, jak
 * w `templates/step2.php`), a bez takiego — nowy model. Krok 3 zaznacza sezon
 * z `SeasonSuggestion`. Tam, gdzie kreator niczego nie zaznacza i czeka na
 * człowieka, tu powstaje problem i plik nie idzie.
 */
final readonly class DefaultMapping
{
    /**
     * @param array<string, array<string, mixed>> $mapping  w kształcie, który zapisuje kreator po kroku 3
     * @param list<string>                        $problems
     */
    private function __construct(
        public array $mapping,
        public array $problems,
    ) {
    }

    /**
     * @param array<array-key, mixed>                     $models           z `CsvParser::extractUniqueModels()`
     * @param list<string>                                $producerNames    producenci wszystkich wierszy pliku — także tych bez modelu, których `extractUniqueModels()` nie zwraca, a import i tak by założył
     * @param array<string, list<array<string, mixed>>>   $treadsByProducer nazwa producenta → `TireRepository::treadsByProducer()`; brak klucza = producenta nie ma w bazie
     * @param array<int, array<string, mixed>>            $seasons          z `TireRepository::allSeasons()`
     * @param array<string, int>                          $seasonOverrides  klucz mapowania (`Producent|Model`) → id sezonu
     */
    public static function build(array $models, array $producerNames, array $treadsByProducer, array $seasons, array $seasonOverrides = []): self
    {
        $mapping  = [];
        $problems = [];

        foreach (array_unique(array_merge($producerNames, array_map(
            static fn (mixed $m): string => is_array($m) ? RowField::text(RowField::normalise($m), 'producer_name') : '',
            array_values($models),
        ))) as $producerName) {
            // pusty producent: `ImportProcessor` pomija taki wiersz, niczego nie zakłada
            if ($producerName !== '' && !array_key_exists($producerName, $treadsByProducer)) {
                $problems[] = "producenta „{$producerName}” nie ma w bazie";
            }
        }

        foreach ($models as $key => $model) {
            if (!is_array($model)) {
                continue;
            }

            $key          = (string) $key;
            $model        = RowField::normalise($model);
            $producerName = RowField::text($model, 'producer_name');
            $modelName    = RowField::text($model, 'model_name');

            if (!array_key_exists($producerName, $treadsByProducer)) {
                continue;
            }

            $matches = array_values(array_filter(
                $treadsByProducer[$producerName],
                static fn (array $t): bool => strtolower(RowField::text($t, 'tread')) === strtolower($modelName),
            ));

            if (count($matches) > 1) {
                $ids        = implode(', ', array_map(static fn (array $t): int => RowField::integer($t, 'id'), $matches));
                $problems[] = "{$producerName} / {$modelName}: kilka bieżników o tej nazwie (id {$ids})";
                continue;
            }

            if (count($matches) === 1) {
                $mapping[$key] = [
                    'producer_name' => $producerName,
                    'model_name'    => $modelName,
                    'tread_id'      => RowField::integer($matches[0], 'id'),
                    'season_id'     => RowField::integer($matches[0], 'season_id'),
                    'is_new'        => false,
                ];
                continue;
            }

            $counts   = RowField::normalise(is_array($model['seasons'] ?? null) ? $model['seasons'] : []);
            $seasonId = $seasonOverrides[$key] ?? SeasonSuggestion::from(
                array_map(static fn (mixed $count): int => is_int($count) ? $count : 0, $counts),
                $seasons,
            )->seasonId;

            if ($seasonId === null) {
                $given      = $counts === [] ? 'plik nie podaje sezonu' : 'plik podaje ' . json_encode($counts, JSON_UNESCAPED_UNICODE);
                $problems[] = "{$producerName} / {$modelName}: nowy model bez rozstrzygniętego sezonu ({$given})";
                continue;
            }

            // krok 3 przyjmuje nazwę z pola formularza: przyciętą i najwyżej 120 znaków
            $mapping[$key] = [
                'producer_name' => $producerName,
                'model_name'    => mb_substr(trim($modelName), 0, 120),
                'tread_id'      => 0,
                'season_id'     => $seasonId,
                'is_new'        => true,
            ];
        }

        return new self($mapping, $problems);
    }

    /** @return array<string, array<string, mixed>> */
    public function newModels(): array
    {
        return array_filter($this->mapping, static fn (array $m): bool => $m['is_new'] === true);
    }
}
