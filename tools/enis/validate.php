<?php

declare(strict_types=1);

// Każdy wiersz CSV importu przez parser kroku 1: rozmiar, indeksy, EAN, producent, bieżnik.
// Kończy się kodem 1 przy wierszu, który import by odrzucił, więc run.sh staje przed publikacją.
//
//   php tools/enis/validate.php CSV

require __DIR__ . '/../../vendor/autoload.php';

use App\Domain\Csv\CsvParser;
use App\Domain\Tire\SizeParser;

$columns = explode(' ', 'numkat1 numkat2 ean id producent rodzaj bieznik rozmiar rozmiar2 indeksy indeksy2 inne opor mokre halas fale eprel netto');
$rows = (new CsvParser($columns))->parseFile($argv[1] ?? '');
$bad = 0;
$labels = 0;

foreach ($rows as $row) {
    if (null === SizeParser::parseSize($row->size) || null === SizeParser::parseIndices($row->indices)
        || !$row->hasValidEan() || !$row->hasValidProducer() || !$row->hasValidModel()) {
        ++$bad;
        echo "BAD {$row->ean} {$row->size} {$row->indices}\n";
    }

    if ($row->hasCompleteLabel()) {
        ++$labels;
    }
}

printf("wierszy %d, złych %d, z pełną etykietą %d\n", count($rows), $bad, $labels);

exit(0 === $bad ? 0 : 1);
