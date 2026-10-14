<?php

declare(strict_types=1);

/**
 * Sprawdza cennik w formacie importu (18 kolumn, opcjonalnie `sezon`) z EPREL-em:
 * każdy wiersz z numerem porównuje z rejestracją spod tego numeru (AKN-760).
 *
 * Nie dotyka bazy. Pisze jeden CSV; kolumny i kody wyników czyta
 * `tools/enis/apply_eprel.py`. Odpowiedzi API trzyma w cache'u na dysku.
 *
 *   php bin/eprelCheck.php [--out=RAPORT.csv] [--concurrency=6] [--cache-days=7] [--problems] CENNIK.csv
 *
 * Opcje przed plikiem — getopt() kończy na pierwszym gołym argumencie.
 */

require __DIR__ . '/../vendor/autoload.php';

use App\Domain\Csv\CsvParser;
use App\Domain\Csv\TireRow;
use App\Eprel\Answer;
use App\Eprel\Mismatch;
use App\Eprel\Names;
use App\Eprel\PricelistCheck;
use App\Eprel\Registration;
use App\Eprel\RegistrationFetcher;
use App\Eprel\ResponseCache;

$root = dirname(__DIR__);
$options = getopt('', ['out:', 'concurrency:', 'cache-days:', 'problems', 'help'], $rest);
$input = is_string($argv[$rest] ?? null) ? $argv[$rest] : '';

if (false === $options || isset($options['help']) || '' === $input) {
    fwrite(STDERR, "php bin/eprelCheck.php [--out=RAPORT.csv] [--concurrency=6] [--cache-days=7] [--problems] CENNIK.csv\n");
    exit(isset($options['help']) ? 0 : 1);
}

$option = static fn (string $key): string => is_string($options[$key] ?? null) ? $options[$key] : '';
$out = $option('out') ?: $root . '/claude/reports/eprel-' . pathinfo($input, PATHINFO_FILENAME) . '.csv';
$cacheDays = '' !== $option('cache-days') ? max(0, (int) $option('cache-days')) : 7;

/** @var array<string, mixed> $config */
$config = require $root . '/config/app.php';
$strings = static fn (mixed $list): array => array_values(array_filter(is_array($list) ? $list : [], 'is_string'));
$rows = (new CsvParser($strings($config['csv_columns'] ?? null), $strings($config['csv_optional_columns'] ?? null)))->parseFile($input);

$numbers = array_values(array_unique(array_filter(array_map(static fn (TireRow $r): int => (int) $r->eprelId, $rows))));
fwrite(STDERR, sprintf("Cennik: %d wierszy, %d różnych numerów EPREL.\n", count($rows), count($numbers)));

$fetcher = new RegistrationFetcher(
    0 === $cacheDays ? null : new ResponseCache($root . '/tmp/eprel-cache', $cacheDays * 86400),
    '' !== $option('concurrency') ? max(1, (int) $option('concurrency')) : 6,
);
$answers = $fetcher->fetch($numbers, static function (string $message): void {
    fwrite(STDERR, "  {$message}\n");
});

$directory = dirname($out);

if (!is_dir($directory) && !mkdir($directory, 0o775, true) && !is_dir($directory)) {
    throw new RuntimeException("Nie mogę utworzyć katalogu: {$directory}");
}

$report = fopen($out, 'w') ?: throw new RuntimeException("Nie mogę pisać do: {$out}");
fputcsv($report, [
    'linia', 'numkat1', 'ean', 'bieznik', 'rozmiar', 'indeksy', 'eprel', 'wynik',
    'rozjazdy', 'eprel_nazwa', 'eprel_oznaczenie', 'eprel_etykieta', 'nazwa_modelu',
], ',', '"', '');

$byResult = array_fill_keys(['OK', 'ROZJAZD', 'BEZ_EPREL', 'BRAK_W_EPREL', 'BLAD_POBRANIA'], 0);
$byField = [];

foreach ($rows as $index => $row) {
    $number = (int) $row->eprelId;
    $registration = null;
    $mismatches = [];
    $note = '';

    if (0 === $number) {
        $result = 'BEZ_EPREL';
    } else {
        $answer = $answers[$number] ?? new Answer(0, '', 'brak odpowiedzi');
        $json = 200 === $answer->status ? $answer->json() : null;
        $registration = null === $json ? null : Registration::fromApi($json);

        if (null !== $registration) {
            $mismatches = PricelistCheck::mismatches($row, $registration);
            $result = [] === $mismatches ? 'OK' : 'ROZJAZD';
        } elseif (404 === $answer->status) {
            $result = 'BRAK_W_EPREL';
        } else {
            $result = 'BLAD_POBRANIA';
            $note = trim(sprintf('HTTP %d %s %s', $answer->status, $answer->apiCode(), $answer->error));
        }
    }

    ++$byResult[$result];

    foreach ($mismatches as $mismatch) {
        $byField[$mismatch->field] = ($byField[$mismatch->field] ?? 0) + 1;
    }

    if (isset($options['problems']) && 'OK' === $result) {
        continue;
    }

    fputcsv($report, [
        (string) ($index + 1),  // kolejny wiersz danych, bez nagłówka i pustych linii
        $row->ref1,
        $row->ean,
        $row->modelName,
        $row->size,
        $row->indices,
        0 === $number ? '' : (string) $number,
        $result,
        '' !== $note ? $note : implode('; ', array_map(static fn (Mismatch $m): string => $m->describe(), $mismatches)),
        $registration->name ?? '',
        $registration->designation ?? '',
        null === $registration ? '' : $registration->label(),
        null === $registration ? '' : (Names::sameTread($row->modelName, $registration->name) ? 'zgodna' : 'do sprawdzenia'),
    ], ',', '"', '');
}

fclose($report);
arsort($byField);

echo "Wynik:\n";

foreach ($byResult as $result => $count) {
    echo '  ', mb_str_pad($result, 15), $count, "\n";
}

if ([] !== $byField) {
    echo "Rozjazdy wg pola:\n";

    foreach ($byField as $field => $count) {
        echo '  ', mb_str_pad((string) $field, 19), $count, "\n";
    }
}

echo "Raport: {$out}\n";
