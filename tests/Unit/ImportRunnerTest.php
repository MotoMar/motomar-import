<?php

declare(strict_types=1);

use App\Bootstrap;
use App\Domain\Import\ImportAborted;
use App\Domain\Import\ImportHistoryRepository;
use App\Domain\Import\ImportRunner;
use App\Domain\Tire\TireRepository;
use App\Logger;
use Medoo\Medoo;

// Uprząż z review PR #13 (sol): SQLite w pamięci podstawiony pod Bootstrap,
// zakleszczenie MySQL udawane kodem błędu na `DELETE FROM tires_codes`.
final class DeadlockingPdo extends PDO
{
    public bool $failRebuild = false;

    public function __construct()
    {
        parent::__construct('sqlite::memory:');
        $this->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }

    public function exec(string $statement): int|false
    {
        if ($this->failRebuild && $statement === 'DELETE FROM tires_codes') {
            $error = new PDOException('Deadlock found when trying to get lock');
            $error->errorInfo = ['40001', 1213, 'Deadlock found when trying to get lock'];
            throw $error;
        }

        return parent::exec($statement);
    }
}

function runnerDb(): DeadlockingPdo
{
    $pdo = new DeadlockingPdo();
    $pdo->exec('CREATE TABLE products_producers (id INTEGER PRIMARY KEY, producer TEXT, slug TEXT, id_product_category INT, classification INT)');
    $pdo->exec('CREATE TABLE products (id INTEGER PRIMARY KEY, price_catalog_netto REAL)');
    $pdo->exec('CREATE TABLE tires (id INTEGER PRIMARY KEY, ref TEXT, ref2 TEXT, ean TEXT, id_product_producer INT)');
    $pdo->exec('CREATE TABLE tires_codes (code TEXT, producer_slug TEXT, tire_id INT, price_catalog_netto REAL)');
    $pdo->exec('CREATE TABLE import_history (id INTEGER PRIMARY KEY, producer TEXT, created_count INT, updated_count INT, skipped_count INT, error_count INT, error_messages TEXT, options TEXT, imported_at TEXT, imported_by TEXT)');
    (new ReflectionProperty(Bootstrap::class, 'db'))->setValue(null, new Medoo(['type' => 'sqlite', 'pdo' => $pdo]));
    (new ReflectionProperty(Bootstrap::class, 'config'))->setValue(null, require __DIR__ . '/../../config/app.php');

    return $pdo;
}

function emptyCsv(): string
{
    $path = tempnam(sys_get_temp_dir(), 'akn-768-');
    file_put_contents($path, '');

    return $path;
}

it('wraps a deadlock during code rebuild for CLI retry', function (): void {
    $pdo = runnerDb();
    $pdo->failRebuild = true;
    $file = emptyCsv();
    $runner = new ImportRunner($pdo, new TireRepository(), new ImportHistoryRepository(), new Logger(sys_get_temp_dir()));

    try {
        $runner->run('test', $file, [], ImportRunner::DEFAULT_OPTIONS, [], 'cli');
    } finally {
        unlink($file);
    }
})->throws(ImportAborted::class);

it('commits an empty import and records the explicit CLI user', function (): void {
    $pdo = runnerDb();
    $file = emptyCsv();
    $runner = new ImportRunner($pdo, new TireRepository(), new ImportHistoryRepository(), new Logger(sys_get_temp_dir()));

    try {
        $stats = $runner->run('test', $file, [], ImportRunner::DEFAULT_OPTIONS, [], 'cli');
    } finally {
        unlink($file);
    }

    $history = $pdo->query('SELECT imported_by FROM import_history');

    expect($stats['created'])->toBe(0)
        ->and($pdo->inTransaction())->toBeFalse()
        ->and($history === false ? null : $history->fetchColumn())->toBe('cli');
});

it('renders step four with exactly the default options checked', function (): void {
    $_SERVER['SCRIPT_NAME'] = '/index.php';
    $_SESSION = [];
    $mapping = [];
    $preview = null;
    $treadRenames = [];
    $seasonMap = [];

    ob_start();
    require __DIR__ . '/../../templates/step4-confirm.php';
    $html = (string) ob_get_clean();

    $checked = [];

    foreach (Dom\HTMLDocument::createFromString($html)->getElementsByTagName('input') as $input) {
        if ($input->getAttribute('type') === 'checkbox' && $input->hasAttribute('checked')) {
            $checked[] = $input->getAttribute('name');
        }
    }

    expect($checked)->toBe(array_keys(array_filter(ImportRunner::DEFAULT_OPTIONS)));
});
