<?php

declare(strict_types=1);

/**
 * Regenerates product names the way the import does, for a chosen set of tires.
 *
 * Dry run unless --apply. With --apply it makes the same pass twice: first to
 * count what would change, then to write — and for --all it asks before the
 * second pass, refusing outright when there is no terminal to ask on.
 *
 * Every write archives the old name to `products.old_name` and changes
 * `better_slug`, which is the URL the shop resolves. The legacy `products.slug`
 * is never touched. Old values of every written product go first to
 * storage/logs/regenerate-names-<time>.jsonl, so the run can be undone.
 *
 * Usage:
 *
 *   php bin/regenerateNames.php --tread=5833
 *   php bin/regenerateNames.php --tire=94480,94486 --apply
 *   php bin/regenerateNames.php --producer=4 --reclassify --show=50
 *   php bin/regenerateNames.php --all --apply
 */

require __DIR__ . '/../vendor/autoload.php';

use App\Bootstrap;
use App\Domain\Tire\DictionaryMatcher;
use App\Domain\Tire\NameChange;
use App\Domain\Tire\NameGenerator;
use App\Domain\Tire\ProductNameRegenerator;
use App\Domain\Tire\SuffixExtractor;
use App\Domain\Tire\TireDataFetcher;
use App\Domain\Tire\TireRepository;
use App\Domain\Tire\TireSelection;

const BATCH_SIZE = 1000;

$options = getopt('', ['tire:', 'tread:', 'producer:', 'all', 'reclassify', 'apply', 'show:', 'help']);

$usage = <<<CLI
    php bin/regenerateNames.php (--tire=ID,… | --tread=ID,… | --producer=ID,… | --all) [opcje]

    --reclassify   najpierw przelicz klasyfikację z `tires.other` (słownik mógł się zmienić)
    --apply        zapisz; bez tego tylko pokazuje, co by się zmieniło
    --show=N       ile zmian wypisać (domyślnie 20)

    Zapis zmienia `better_slug`, czyli adres produktu w sklepie — stary przestaje działać.
    CLI;

if (false === $options || isset($options['help'])) {
    fwrite(STDERR, $usage . PHP_EOL);
    exit(false === $options ? 1 : 0);
}

try {
    $selection = TireSelection::fromOptions($options);
} catch (InvalidArgumentException $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL . PHP_EOL . $usage . PHP_EOL);
    exit(1);
}

$apply = isset($options['apply']);
$show = is_string($options['show'] ?? null) ? max(0, (int) $options['show']) : 20;

Bootstrap::init();
$pdo = Bootstrap::pdo();
$fetcher = new TireDataFetcher($pdo);
$repo = new TireRepository();
$regenerator = new ProductNameRegenerator(
    new NameGenerator(new SuffixExtractor()),
    isset($options['reclassify']) ? DictionaryMatcher::fromPdo($pdo) : null,
);

printf(
    "Baza: %s\nZakres: %s (%d opon)%s\n\n",
    is_string($_ENV['DB_NAME'] ?? null) ? $_ENV['DB_NAME'] : '?',
    $selection->label(),
    $fetcher->countSelection($selection),
    isset($options['reclassify']) ? ', z przeliczeniem klasyfikacji' : '',
);

/**
 * One pass over the selection. Hands every change that is not a no-op to $onChange.
 *
 * @param callable(NameChange, array<string, mixed>): void $onChange
 * @param callable(): void                                 $afterBatch
 */
function walk(TireDataFetcher $fetcher, TireSelection $selection, ProductNameRegenerator $regenerator, callable $onChange, callable $afterBatch): void
{
    $afterId = 0;

    while ([] !== ($rows = $fetcher->fetchSelection($selection, $afterId, BATCH_SIZE))) {
        foreach ($rows as $row) {
            $change = $regenerator->change($row);
            $afterId = $change->tireId;

            if (!$change->isNoop()) {
                $onChange($change, $row);
            }
        }

        $afterBatch();
    }
}

$counts = ['changes' => 0, 'names' => 0, 'models' => 0, 'classification' => 0];
$shown = 0;

walk($fetcher, $selection, $regenerator, static function (NameChange $c) use (&$counts, &$shown, $show): void {
    ++$counts['changes'];
    $counts['names'] += (int) $c->nameChanged();
    $counts['models'] += (int) $c->modelChanged();
    $counts['classification'] += (int) (null !== $c->reclassified);

    if ($shown++ >= $show) {
        return;
    }

    printf("id %d\n", $c->tireId);

    if ($c->nameChanged()) {
        printf("  nazwa: %s\n      → %s\n", $c->oldName, $c->newName);
        printf("  slug : %s\n      → %s\n", $c->oldSlug, $c->newSlug);
    }

    if ($c->modelChanged()) {
        printf("  tire_model: %s → %s\n", $c->oldModel, $c->newModel);
    }

    if (null !== $c->reclassified) {
        printf("  klasyfikacja → %s\n", json_encode($c->reclassified, JSON_UNESCAPED_UNICODE));
    }
}, static function (): void {
});

printf(
    "\nDo zmiany: %d produktów — nazwa %d, tire_model %d, klasyfikacja %d\n",
    $counts['changes'],
    $counts['names'],
    $counts['models'],
    $counts['classification'],
);

if (!$apply || 0 === $counts['changes']) {
    echo $apply ? "Nic do zapisania.\n" : "Dry-run — nic nie zapisane. Zapis: --apply\n";
    exit(0);
}

if ($selection->isAll()) {
    if (!stream_isatty(STDIN)) {
        fwrite(STDERR, "--all --apply wymaga potwierdzenia w terminalu, a go nie ma. Przerwane.\n");
        exit(1);
    }

    echo "Zapisać {$counts['changes']} produktów w całej bazie? [y/N] ";
    $answer = trim((string) fgets(STDIN));

    if ('y' !== strtolower($answer)) {
        echo "Przerwane, nic nie zapisane.\n";
        exit(1);
    }
}

$logDir = dirname(__DIR__) . '/storage/logs';
$backupFile = $logDir . '/regenerate-names-' . date('Ymd-His') . '.jsonl';
$backup = fopen($backupFile, 'a');

if (false === $backup) {
    fwrite(STDERR, "Nie mogę pisać kopii do {$backupFile}. Przerwane, nic nie zapisane.\n");
    exit(1);
}

$written = 0;
$committed = 0;
$pdo->beginTransaction();

try {
    walk($fetcher, $selection, $regenerator, static function (NameChange $c, array $row) use ($repo, $backup, &$written): void {
        fwrite($backup, json_encode([
            'tire_id'     => $c->tireId,
            'name'        => $c->oldName,
            'better_slug' => $c->oldSlug,
            'tire_model'  => $c->oldModel,
            'parameters'  => $row['classified_parameters_json'] ?? null,
        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . PHP_EOL);

        $repo->applyNameChange($c);
        ++$written;
    }, static function () use ($pdo, $backup, &$written, &$committed): void {
        // One transaction per page: a failure loses at most a page, and the
        // copy on disk always covers everything committed.
        fflush($backup);
        $pdo->commit();
        $committed = $written;
        $pdo->beginTransaction();
    });

    $pdo->commit();
    $committed = $written;
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    fwrite(STDERR, "Błąd: {$e->getMessage()}\nZapisane i zatwierdzone: {$committed}, bieżąca paczka wycofana. Kopia: {$backupFile}\n");
    exit(1);
} finally {
    fclose($backup);
}

echo "Zapisane: {$written} produktów. Kopia starych wartości: {$backupFile}\n";
