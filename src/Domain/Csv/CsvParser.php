<?php

declare(strict_types=1);

namespace App\Domain\Csv;

use App\Bootstrap;

final class CsvParser
{
    private const DELIMITER = '@';

    /** @return TireRow[] */
    public function parseFile(string $filePath): array
    {
        $columns = Bootstrap::config()['csv_columns'];
        $handle  = @fopen($filePath, 'r');

        if ($handle === false) {
            throw new \RuntimeException("Cannot open CSV file: {$filePath}");
        }

        $rows       = [];
        $lineNumber = 0;

        try {
            while (($line = fgets($handle)) !== false) {
                ++$lineNumber;
                $line = trim($line);

                if ($line === '') {
                    continue;
                }

                $fields = explode(self::DELIMITER, $line);

                // Detect and skip header row — matches when first field equals the
                // first expected column name (case-insensitive) or when any field
                // contains a known header token like 'producent', 'bieznik', 'netto'.
                if ($lineNumber === 1 && $this->isHeaderRow($fields, $columns)) {
                    continue;
                }

                if (count($fields) !== count($columns)) {
                    throw new \RuntimeException(sprintf(
                        'Line %d: expected %d columns, got %d. Content: %s',
                        $lineNumber,
                        count($columns),
                        count($fields),
                        mb_substr($line, 0, 80)
                    ));
                }

                $rows[] = new TireRow(array_combine($columns, $fields));
            }
        } finally {
            fclose($handle);
        }

        return $rows;
    }

    /**
     * Returns true when the line looks like a header row.
     * Checks only the first line of the file.
     */
    private function isHeaderRow(array $fields, array $columns): bool
    {
        // Exact column-name match (all fields equal expected column names)
        $normalise = static fn(string $s): string => strtolower(trim($s));

        if (array_map($normalise, $fields) === array_map($normalise, $columns)) {
            return true;
        }

        // Partial match — at least one field equals a known header token.
        // Covers files where only some headers are present or have different order.
        $headerTokens = ['numkat1', 'producent', 'bieznik', 'netto', 'rozmiar'];
        foreach ($fields as $field) {
            if (in_array($normalise($field), $headerTokens, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Extracts unique (producer_name, model_name) pairs with counts.
     *
     * @param  TireRow[] $rows
     * @return array<string, array{producer_name: string, model_name: string, count: int}>
     */
    public function extractUniqueModels(array $rows): array
    {
        $models = [];

        foreach ($rows as $row) {
            if (!$row->hasValidProducer() || !$row->hasValidModel()) {
                continue;
            }

            $key = $row->mappingKey();

            if (!isset($models[$key])) {
                $models[$key] = [
                    'producer_name' => $row->producerName,
                    'model_name'    => $row->modelName,
                    'count'         => 0,
                ];
            }

            ++$models[$key]['count'];
        }

        return $models;
    }
}
