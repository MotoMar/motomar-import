<?php

declare(strict_types=1);

namespace App\Domain\Csv;

use App\Bootstrap;

final class CsvParser
{
    private const DELIMITER = '@';

    /**
     * @param string[]|null $columns  Column layout; defaults to the configured
     *                                one. Passing it explicitly is what lets the
     *                                parser be exercised without booting the
     *                                application, which opens a session.
     * @param string[]|null $optional Kolumny na końcu wiersza, których może
     *                                nie być; domyślnie te z konfiguracji, żadne,
     *                                gdy układ podano jawnie bez nich.
     */
    public function __construct(
        private readonly ?array $columns = null,
        private readonly ?array $optional = null,
    ) {}

    /** @return TireRow[] */
    public function parseFile(string $filePath): array
    {
        $columns  = $this->columns ?? Bootstrap::csvColumns();
        $optional = $this->optional ?? ($this->columns === null ? Bootstrap::csvOptionalColumns() : []);

        // Checked rather than suppressed with @: the suppression operator does
        // not stop a custom error handler, so the warning surfaced anyway.
        if (!is_readable($filePath)) {
            throw new \RuntimeException("Cannot open CSV file: {$filePath}");
        }

        $handle = fopen($filePath, 'r');

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
                if ($lineNumber === 1 && $this->isHeaderRow($fields, $columns, $optional)) {
                    continue;
                }

                $missing = count($columns) + count($optional) - count($fields);

                if ($missing < 0 || $missing > count($optional)) {
                    throw new \RuntimeException(sprintf(
                        'Line %d: expected %s columns, got %d. Content: %s',
                        $lineNumber,
                        $optional === [] ? count($columns) : count($columns) . '–' . (count($columns) + count($optional)),
                        count($fields),
                        mb_substr($line, 0, 80)
                    ));
                }

                $present = array_slice($optional, 0, count($optional) - $missing);
                $rows[]  = new TireRow(
                    array_combine([...$columns, ...$present], $fields) + array_fill_keys(array_slice($optional, count($present)), '')
                );
            }
        } finally {
            fclose($handle);
        }

        return $rows;
    }

    /**
     * Returns true when the line looks like a header row.
     * Checks only the first line of the file.
     *
     * @param string[] $fields
     * @param string[] $columns
     * @param string[] $optional
     */
    private function isHeaderRow(array $fields, array $columns, array $optional): bool
    {
        // Exact column-name match (all fields equal expected column names)
        $normalise = static fn(string $s): string => strtolower(trim($s));
        $given     = array_map($normalise, $fields);

        for ($present = 0; $present <= count($optional); ++$present) {
            if ($given === array_map($normalise, [...$columns, ...array_slice($optional, 0, $present)])) {
                return true;
            }
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
     * Unikalne pary (producent, model) z liczbą wierszy i z tym, ile wierszy
     * modelu podaje który sezon (małymi literami, puste pominięte).
     *
     * @param  TireRow[] $rows
     * @return array<string, array{producer_name: string, model_name: string, count: int, seasons: array<string, int>}>
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
                    'seasons'       => [],
                ];
            }

            ++$models[$key]['count'];

            if ($row->season !== '') {
                $season = mb_strtolower($row->season);
                $models[$key]['seasons'][$season] = ($models[$key]['seasons'][$season] ?? 0) + 1;
            }
        }

        return $models;
    }
}
