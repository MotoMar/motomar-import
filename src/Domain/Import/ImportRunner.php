<?php

declare(strict_types=1);

namespace App\Domain\Import;

use App\Domain\Tire\ImportProcessor;
use App\Domain\Tire\NameGenerator;
use App\Domain\Tire\ProductNameRegenerator;
use App\Domain\Tire\RowField;
use App\Domain\Tire\SuffixExtractor;
use App\Domain\Tire\TireCodesUpdater;
use App\Domain\Tire\TireDataFetcher;
use App\Domain\Tire\TireRepository;
use App\Domain\Tire\TreadRename;
use App\Domain\Tire\TreadRenamer;
use App\Logger;
use PDO;

/**
 * Zapis importu: krok 4 kreatora i `bin/import.php` przechodzą tędy oboje (AKN-768),
 * żeby CLI nie było drugą, rozjeżdżającą się kopią tego, co robi przycisk.
 */
final readonly class ImportRunner
{
    /** Co krok 4 ma zaznaczone, zanim operator czegokolwiek dotknie — i z czym jedzie CLI. */
    public const array DEFAULT_OPTIONS = [
        'update_labels'    => true,
        'update_inne'      => true,
        'update_structure' => false,
        'update_price'     => false,
        'update_ref'       => false,
        'update_tread'     => false,
    ];

    public function __construct(
        private PDO $pdo,
        private TireRepository $repo,
        private ImportHistoryRepository $history,
        private Logger $logger,
    ) {
    }

    /**
     * @param array<array-key, mixed> $mapping po kroku 3: klucz mapowania → bieżnik albo nowy model z sezonem
     * @param array<string, bool>     $options
     * @param list<TreadRename>       $renames
     *
     * @return array<string, mixed> statystyki z `ImportProcessor::run()`
     *
     * @throws ImportAborted gdy transakcja przepadła (zakleszczenie, zerwane połączenie) — cofnięte w całości
     * @throws \Throwable    każdy inny błąd — też cofnięty w całości
     */
    public function run(string $uuid, string $csvPath, array $mapping, array $options, array $renames = [], ?string $user = null): array
    {
        $this->logger->info('Import started', ['uuid' => $uuid, 'options' => $options]);

        $transaction = new ImportTransaction($this->pdo);

        try {
            $stats = $transaction->run(function () use ($transaction, $mapping, $csvPath, $options, $renames): array {
                $renamer = new TreadRenamer(
                    $this->repo,
                    new TireDataFetcher($this->pdo),
                    new ProductNameRegenerator(new NameGenerator(new SuffixExtractor())),
                    $this->logger,
                );

                // 0. Najpierw nazwy bieżników — wiersze z pliku dostaną już nową nazwę
                $renamer->rename($renames);

                // 1. Create new tread records (models marked as new with assigned seasons)
                $resolvedMapping = $this->createNewTreads($mapping);

                // 2. Run the actual import
                $processor = new ImportProcessor($this->repo, $this->logger);
                $stats     = $processor->run($csvPath, $resolvedMapping, $transaction, $options);

                // 2a. Opony przemianowanych bieżników spoza pliku
                $stats['tread_renames']     = array_map(static fn (TreadRename $r): array => $r->toArray(), $renames);
                $stats['names_regenerated'] = $renamer->regenerateNames($renames);

                // 3. Rebuild legacy code lookup table, like the old import task did.
                $stats['tires_codes'] = (new TireCodesUpdater($this->pdo))->rebuild();

                return $stats;
            });
        } catch (\Throwable $e) {
            $this->logger->error('Import failed', ['uuid' => $uuid, 'error' => $e->getMessage()]);

            // Savepointy wierszy same zamieniają zakleszczenie na ImportAborted, ale
            // nowe bieżniki i przebudowa `tires_codes` idą poza nimi — bez tego CLI
            // nie wie, że plik wolno powtórzyć.
            ImportTransaction::rethrowIfLost($e);

            throw $e;
        }

        $this->logger->info('Import finished', array_merge(['uuid' => $uuid], $stats));

        $this->recordPricings($stats);

        if ($renames !== []) {
            $options['tread_renames'] = array_map(static fn (TreadRename $r): array => $r->toArray(), $renames);
        }

        $this->recordHistory($stats, $mapping, $options, $user);

        return $stats;
    }

    /**
     * Record pricings_tires per producer (outside transaction, like pricingSave)
     *
     * @param array<string, mixed> $stats
     */
    private function recordPricings(array $stats): void
    {
        try {
            foreach (RowField::rows($stats, 'pricings_tires') as $producerId => $entry) {
                $tireIds = array_map(intval(...), RowField::strings($entry, 'tires'));

                $this->repo->createPricingRecord($tireIds, (int) $producerId, RowField::text($entry, 'name'), count($tireIds));
            }
        } catch (\Throwable $pricingError) {
            $this->logger->warning('Failed to record pricings_tires', [
                'error' => $pricingError->getMessage(),
            ]);
        }
    }

    /**
     * Record import in history per producer (outside transaction)
     *
     * @param array<string, mixed>    $stats
     * @param array<array-key, mixed> $mapping
     * @param array<string, mixed>    $options
     */
    private function recordHistory(array $stats, array $mapping, array $options, ?string $user): void
    {
        try {
            $perProducer = RowField::rows($stats, 'per_producer');
            if (!empty($perProducer)) {
                foreach ($perProducer as $producerName => $pStats) {
                    $this->history->recordImport(
                        (string) $producerName,
                        RowField::integer($pStats, 'created'),
                        RowField::integer($pStats, 'updated'),
                        RowField::integer($pStats, 'skipped'),
                        RowField::integer($pStats, 'errors'),
                        [], // error messages are shared across producers
                        $options,
                        $user,
                    );
                }
            } else {
                // Fallback: no per-producer stats (e.g. all rows skipped)
                $producerNames = [];

                foreach ($mapping as $entry) {
                    if (is_array($entry)) {
                        $producerNames[] = RowField::text(RowField::normalise($entry), 'producer_name');
                    }
                }

                $producerNames = array_values(array_unique(array_filter($producerNames)));
                $producerName = $producerNames[0] ?? 'unknown';
                $errorMessages = RowField::strings($stats, 'errors');
                $this->history->recordImport(
                    $producerName,
                    RowField::integer($stats, 'created'),
                    RowField::integer($stats, 'updated'),
                    RowField::integer($stats, 'skipped'),
                    count($errorMessages),
                    $errorMessages,
                    $options,
                    $user,
                );
            }
        } catch (\Throwable $historyError) {
            $this->logger->warning('Failed to record import history', [
                'error' => $historyError->getMessage(),
            ]);
        }
    }

    /**
     * @param array<array-key, mixed> $mapping
     *
     * @return array<array-key, mixed>
     */
    private function createNewTreads(array $mapping): array
    {
        foreach ($mapping as $key => &$entry) {
            if (!is_array($entry)) {
                continue;
            }

            $entry = RowField::normalise($entry);

            if (!RowField::flag($entry, 'is_new')) {
                continue;
            }

            $seasonId = RowField::integer($entry, 'season_id');
            $modelName = RowField::text($entry, 'model_name');
            $producerName = RowField::text($entry, 'producer_name');

            if ($seasonId <= 0) {
                throw new \RuntimeException(
                    "Brak sezonu dla modelu: {$modelName} / {$producerName}"
                );
            }

            $producer = $this->repo->producerByName($producerName);

            if ($producer === null) {
                $this->logger->warning('Producer not found, skipping new tread', [
                    'producer' => $entry['producer_name'],
                ]);
                unset($mapping[$key]);
                continue;
            }

            $treadId          = $this->repo->createTread(RowField::integer($producer, 'id'), $modelName, $seasonId);
            $entry['tread_id'] = $treadId;
            $entry['is_new']   = false; // mark as resolved

            $this->logger->info('Created tread', [
                'tread_id' => $treadId,
                'name'     => $entry['model_name'],
                'producer' => $entry['producer_name'],
                'season'   => $entry['season_id'],
            ]);
        }
        unset($entry);

        return $mapping;
    }
}
