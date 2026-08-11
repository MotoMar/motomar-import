<?php

declare(strict_types=1);

/**
 * Compares the name and slug this code generates against what is stored in
 * `products`, for every tire in the database.
 *
 * The product name is assembled from `tires_classified_parameters` and from
 * nothing else, so this answers two different questions depending on when it
 * is run. On an untouched copy it asks "does the builder agree with the data
 * we already have" — the answer on 2026-08-11 was 0 differences out of 118 983.
 * After the classification is rebuilt it asks "what would change", which is the
 * dry run for `regenerateProductsNames --execute` in motomar-php.
 *
 * Reads only. Point DB_NAME at a local copy — a production copy is fine here
 * precisely because nothing is written.
 *
 * Usage:
 *
 *   php bin/verifyNames.php
 *   php bin/verifyNames.php --database=motomar_prod_06082026
 *   php bin/verifyNames.php --vehicle-type=7 --show=30
 */

require __DIR__ . '/../vendor/autoload.php';

use App\Domain\Tire\NameGenerator;
use App\Domain\Tire\SuffixExtractor;
use App\Domain\Tire\TireDataFetcher;

const BATCH_SIZE = 2000;

$options = getopt('', ['database:', 'vehicle-type:', 'show:', 'help']);

if (false === $options || isset($options['help'])) {
    echo <<<CLI
    php bin/verifyNames.php [opcje]

    --database=NAZWA     baza do sprawdzenia (domyślnie DB_NAME z .env)
    --vehicle-type=N     tylko jeden typ pojazdu (1-10)
    --show=N             ile rozjazdów wypisać (domyślnie 15)

    CLI;

    exit(1);
}

$env = loadEnv(__DIR__ . '/../.env');
$database = stringOption($options, 'database') ?? $env['DB_NAME'] ?? null;

if (null === $database) {
    fwrite(STDERR, 'Brak nazwy bazy — podaj --database albo DB_NAME w .env.' . PHP_EOL);

    exit(1);
}

$vehicleType = null !== stringOption($options, 'vehicle-type')
    ? (int) stringOption($options, 'vehicle-type')
    : null;
$show = null !== stringOption($options, 'show') ? (int) stringOption($options, 'show') : 15;

$pdo = new PDO(
    sprintf('mysql:dbname=%s;host=%s;charset=utf8mb4', $database, $env['DB_HOST'] ?? 'localhost'),
    $env['DB_USER'] ?? '',
    $env['DB_PASS'] ?? '',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
);

$fetcher = new TireDataFetcher($pdo);
$generator = new NameGenerator(new SuffixExtractor());

printf(
    'Baza: %s%s%s%s',
    $database,
    null !== $vehicleType ? ", typ pojazdu {$vehicleType}" : '',
    PHP_EOL,
    PHP_EOL,
);

$offset = 0;
$checked = 0;
$nameDiff = 0;
$slugDiff = 0;
$shown = 0;

while (true) {
    $rows = $fetcher->fetchTires($vehicleType, BATCH_SIZE, $offset);

    if ([] === $rows) {
        break;
    }

    foreach ($rows as $row) {
        ++$checked;

        $classified = TireDataFetcher::decodeClassifiedParameters($row);
        $generated = $generator->generateWithSlug($row, $classified);

        $storedName = is_string($row['current_name'] ?? null) ? $row['current_name'] : '';
        $storedSlug = is_string($row['current_slug'] ?? null) ? $row['current_slug'] : '';

        $nameChanged = $storedName !== $generated['name'];
        $slugChanged = $storedSlug !== $generated['slug'];

        if ($nameChanged) {
            ++$nameDiff;
        }

        if ($slugChanged) {
            ++$slugDiff;
        }

        if (($nameChanged || $slugChanged) && $shown < $show) {
            ++$shown;

            $tireId = $row['tire_id'] ?? null;

            printf("id %s\n", is_scalar($tireId) ? (string) $tireId : '?');
            printf("  other : %s\n", is_string($row['other'] ?? null) ? $row['other'] : '');
            printf("  jest  : %s\n", $storedName);
            printf("  wyszło: %s\n", $generated['name']);

            if ($slugChanged) {
                printf("  slug jest   : %s\n", $storedSlug);
                printf("  slug wyszedł: %s\n", $generated['slug']);
            }

            echo PHP_EOL;
        }
    }

    $offset += count($rows);
}

printf("sprawdzone: %d\nnazwa inna: %d\nslug inny:  %d\n", $checked, $nameDiff, $slugDiff);

/**
 * @param array<string, mixed> $options
 */
function stringOption(array $options, string $name): ?string
{
    $value = $options[$name] ?? null;

    return is_string($value) ? $value : null;
}

/**
 * Reads the connection details out of `.env` without booting the application.
 *
 * `Bootstrap::init()` opens a session, which a read-only CLI script has no
 * business doing.
 *
 * @return array<string, string>
 */
function loadEnv(string $path): array
{
    if (!is_file($path)) {
        return [];
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

    if (false === $lines) {
        return [];
    }

    $env = [];

    foreach ($lines as $line) {
        $line = trim($line);

        if ('' === $line || '#' === $line[0] || !str_contains($line, '=')) {
            continue;
        }

        [$key, $value] = explode('=', $line, 2);

        $env[trim($key)] = trim(trim($value), '"\'');
    }

    return $env;
}
