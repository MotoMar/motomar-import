<?php

declare(strict_types=1);

// Co import tych CSV zrobiłby z nazwami opon, które już są w bazie — dopasowanych tylko po EAN (wiersza,
// który import dopasuje po numkacie, nie symuluje). Bazę bierze z .env i nic do niej nie zapisuje: `other`
// i bieżnik z CSV przechodzą przez ten sam regenerator, którego używa import.
//
//   php tools/enis/sim.php OUT.tsv CSV...

require __DIR__ . '/../../vendor/autoload.php';

use App\Bootstrap;
use App\Domain\Tire\DictionaryMatcher;
use App\Domain\Tire\NameGenerator;
use App\Domain\Tire\ProductNameRegenerator;
use App\Domain\Tire\RowField;
use App\Domain\Tire\SuffixExtractor;
use App\Domain\Tire\TireDataFetcher;
use App\Domain\Tire\TireSelection;

$args = array_slice($argv ?? [], 1);
$outPath = array_shift($args) ?? throw new InvalidArgumentException('Użycie: php tools/enis/sim.php OUT.tsv CSV...');

Bootstrap::init();
$pdo = Bootstrap::pdo();
$fetcher = new TireDataFetcher($pdo);
$regenerator = new ProductNameRegenerator(new NameGenerator(new SuffixExtractor()), DictionaryMatcher::fromPdo($pdo));

$out = fopen($outPath, 'w') ?: throw new RuntimeException("Nie mogę pisać do: {$outPath}");
fwrite($out, "ean\ttire_id\told_name\tnew_name\told_tread\tnew_tread\n");

foreach ($args as $file) {
    $lines = file($file, FILE_IGNORE_NEW_LINES) ?: throw new RuntimeException("Nie mogę czytać: {$file}");
    $header = explode('@', (string) array_shift($lines));
    /** @var array<string, array<string, string>> $byEan */
    $byEan = [];

    foreach ($lines as $line) {
        $row = array_combine($header, explode('@', $line));
        if ('' !== $row['ean']) {
            $byEan[$row['ean']] = $row;
        }
    }

    /** @var array<int, string> $eanOf id opony => EAN */
    $eanOf = [];

    foreach (array_chunk(array_map('strval', array_keys($byEan)), 500) as $chunk) {
        $stmt = $pdo->prepare('SELECT id, ean FROM tires WHERE ean IN (' . implode(',', array_fill(0, count($chunk), '?')) . ')');
        $stmt->execute($chunk);
        /** @var array<string, mixed> $tire */
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $tire) {
            $eanOf[RowField::integer($tire, 'id')] = RowField::text($tire, 'ean');
        }
    }

    $afterId = 0;
    $selection = TireSelection::tires(array_keys($eanOf));

    while ([] !== ($rows = $fetcher->fetchSelection($selection, $afterId, 1000))) {
        foreach ($rows as $current) {
            $afterId = RowField::integer($current, 'tire_id');
            $csv = $byEan[$eanOf[$afterId]];
            $oldTread = RowField::text($current, 'tread');
            $current['other'] = $csv['inne'];
            $current['tread'] = $csv['bieznik'];
            $change = $regenerator->change($current);
            fwrite($out, implode("\t", [$eanOf[$afterId], $afterId, $change->oldName, $change->newName, $oldTread, $csv['bieznik']]) . "\n");
        }
    }
}
