<?php

declare(strict_types=1);

/**
 * Import cenników bez kreatora: to samo, co operator klikający „dalej” w czterech
 * krokach, dla listy plików (AKN-768).
 *
 * Mapowanie i sezony jak domyślne w krokach 2 i 3 (`DefaultMapping`), opcje jak
 * domyślne w kroku 4, zapis przez ten sam `ImportRunner` co przycisk. Plik,
 * przy którym kreator czekałby na człowieka, jest odrzucany w całości.
 *
 * Bez --apply tylko pokazuje plan. Każdy plik to osobna transakcja; po
 * zakleszczeniu import cofa się w całości, więc plik jest powtarzany.
 *
 * Użycie:
 *
 *   php bin/import.php Cenniki/Enis/CSV/Leao.csv
 *   php bin/import.php --apply --season="Lanvigator|Ice Power=Zimowa" *.csv
 */

require __DIR__ . '/../vendor/autoload.php';

use App\Bootstrap;
use App\Domain\Csv\CsvParser;
use App\Domain\Csv\TireRow;
use App\Domain\Import\DefaultMapping;
use App\Domain\Import\ImportAborted;
use App\Domain\Import\ImportHistoryRepository;
use App\Domain\Import\ImportRunner;
use App\Domain\Import\ImportSession;
use App\Domain\Tire\ImportProcessor;
use App\Domain\Tire\RowField;
use App\Domain\Tire\TireRepository;

$usage = <<<CLI
    php bin/import.php [opcje] PLIK.csv [PLIK.csv…]

    --apply                      zapisz; bez tego tylko plan
    --season="Producent|Model=Sezon"
                                 sezon nowego modelu, którego plik nie rozstrzyga (można powtarzać)
    --retries=N                  ile razy powtórzyć plik po zakleszczeniu (domyślnie 2)

    Opcje kroku 4 jak domyślne w formularzu: etykieta EU i oznaczenia — tak.
    Plik z modelem bez sezonu, z niejednoznacznym bieżnikiem albo z producentem
    spoza bazy jest odrzucany w całości; reszta plików idzie dalej.
    CLI;

$restIndex = 0;
$options = getopt('', ['apply', 'season:', 'retries:', 'help'], $restIndex);
$files = array_values(array_filter(
    array_slice(is_array($_SERVER['argv'] ?? null) ? $_SERVER['argv'] : [], $restIndex),
    is_string(...),
));

if (false === $options || isset($options['help']) || $files === []) {
    fwrite(STDERR, $usage . PHP_EOL);
    exit(isset($options['help']) ? 0 : 1);
}

$apply = isset($options['apply']);
$retries = is_string($options['retries'] ?? null) ? max(0, (int) $options['retries']) : 2;

Bootstrap::init();
$repo = new TireRepository();
$seasons = $repo->allSeasons();
$seasonIds = [];
$seasonNames = [];

foreach ($seasons as $season) {
    $seasonIds[mb_strtolower(RowField::text($season, 'season'))] = RowField::integer($season, 'id');
    $seasonNames[RowField::integer($season, 'id')] = RowField::text($season, 'season');
}

$overrides = [];

$givenSeasons = $options['season'] ?? [];

foreach (array_filter(is_array($givenSeasons) ? $givenSeasons : [$givenSeasons], is_string(...)) as $given) {
    $at = strrpos($given, '=');
    $id = $at === false ? null : ($seasonIds[mb_strtolower(trim(substr($given, $at + 1)))] ?? null);

    if ($at === false || $id === null || !str_contains(substr($given, 0, $at), '|')) {
        fwrite(STDERR, "Zły --season „{$given}”: oczekuję „Producent|Model=Sezon”, sezon jeden z: " . implode(', ', array_keys($seasonIds)) . PHP_EOL);
        exit(1);
    }

    $overrides[substr($given, 0, $at)] = $id;
}

foreach ($files as $file) {
    if (!is_readable($file)) {
        fwrite(STDERR, "Nie da się czytać: {$file}" . PHP_EOL);
        exit(1);
    }
}

// bez --apply nie powstaje: konstruktor ImportHistoryRepository potrafi założyć tabelę
$runner = null;
$parser = new CsvParser();
$processor = new ImportProcessor($repo, Bootstrap::logger());
$used = [];
$rejected = [];
$done = 0;

printf("Baza: %s, %d plików, %s\n\n", is_string($_ENV['DB_NAME'] ?? null) ? $_ENV['DB_NAME'] : '?', count($files), $apply ? 'ZAPIS' : 'tylko plan (bez --apply)');

foreach ($files as $file) {
    $name = basename($file);

    try {
        $rows = $parser->parseFile($file);
    } catch (RuntimeException $e) {
        $rejected[$name] = ['nie da się sparsować: ' . $e->getMessage()];
        printf("== %s: ODRZUCONY — %s\n", $name, $rejected[$name][0]);
        continue;
    }

    $models = $parser->extractUniqueModels($rows);
    $producerNames = array_values(array_unique(array_map(static fn (TireRow $row): string => $row->producerName, $rows)));
    $treadsByProducer = [];

    foreach ($producerNames as $producerName) {
        $producer = $repo->producerByName($producerName);

        if ($producer !== null) {
            $treadsByProducer[$producerName] = array_values($repo->treadsByProducer(RowField::integer($producer, 'id')));
        }
    }

    $default = DefaultMapping::build($models, $producerNames, $treadsByProducer, $seasons, $overrides);
    $new = $default->newModels();

    // sezon z --season liczy się tylko dla nowego modelu; istniejący ma swój
    $used += array_intersect_key($overrides, $new);

    if ($rows === [] || $default->problems !== []) {
        $rejected[$name] = $rows === [] ? ['brak poprawnych wierszy'] : $default->problems;
        printf("== %s: ODRZUCONY\n", $name);
        foreach ($rejected[$name] as $problem) {
            echo "   ! {$problem}\n";
        }
        continue;
    }

    $preview = $processor->preview($file, $default->mapping);
    printf(
        "== %s: %d wierszy — nowe %d, istniejące %d, pominięte %d | modele: %d, w tym nowe %d\n",
        $name, count($rows), $preview['will_create'], $preview['will_update'], $preview['will_skip'], count($models), count($new),
    );

    foreach ($new as $model) {
        printf("   + %s [%s]\n", RowField::text($model, 'model_name'), $seasonNames[RowField::integer($model, 'season_id')] ?? '?');
    }

    if (!$apply) {
        continue;
    }

    $runner ??= new ImportRunner(Bootstrap::pdo(), $repo, new ImportHistoryRepository(), Bootstrap::logger());
    $uuid = ImportSession::uuid4();
    Bootstrap::logger()->info('CLI import', ['uuid' => $uuid, 'file' => $name, 'rows' => count($rows)]);

    for ($attempt = 1; ; ++$attempt) {
        try {
            $stats = $runner->run($uuid, $file, $default->mapping, ImportRunner::DEFAULT_OPTIONS, [], 'cli');
            break;
        } catch (ImportAborted $e) {
            if ($attempt > $retries) {
                fwrite(STDERR, "   ✗ {$name}: {$e->getMessage()} — wycofany, poddaję się po {$attempt} próbach" . PHP_EOL);
                exit(1);
            }

            echo "   ↻ wycofany ({$e->getMessage()}), próba " . ($attempt + 1) . "\n";
            sleep(2);
        } catch (Throwable $e) {
            fwrite(STDERR, "   ✗ {$name}: {$e->getMessage()} — wycofany, przerywam" . PHP_EOL);
            exit(1);
        }
    }

    $errors = RowField::strings($stats, 'errors');
    printf(
        "   ✓ utworzone %d, zaktualizowane %d, pominięte %d, błędy %d\n",
        RowField::integer($stats, 'created'), RowField::integer($stats, 'updated'), RowField::integer($stats, 'skipped'), count($errors),
    );
    foreach (array_slice($errors, 0, 5) as $error) {
        echo "   ! {$error}\n";
    }
    ++$done;
}

foreach (array_diff_key($overrides, $used) as $key => $_) {
    echo "\nUwaga: --season „{$key}” nie pasuje do żadnego nowego modelu w podanych plikach.\n";
}

printf("\n%s: %d, odrzucone: %d%s\n", $apply ? 'Zaimportowane' : 'Do importu', $apply ? $done : count($files) - count($rejected), count($rejected), $rejected === [] ? '' : ' (' . implode(', ', array_keys($rejected)) . ')');
exit($rejected === [] ? 0 : 1);
