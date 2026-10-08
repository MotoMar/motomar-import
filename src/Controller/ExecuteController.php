<?php

declare(strict_types=1);

namespace App\Controller;

use App\Domain\Tire\RowField;
use App\Request;
use App\Bootstrap;
use App\Csrf;
use App\Domain\Import\ImportAborted;
use App\Domain\Import\ImportHistoryRepository;
use App\Domain\Import\ImportSession;
use App\Domain\Import\ImportTransaction;
use App\Domain\Tire\ImportProcessor;
use App\Domain\Tire\NameGenerator;
use App\Domain\Tire\ProductNameRegenerator;
use App\Domain\Tire\SuffixExtractor;
use App\Domain\Tire\TireCodesUpdater;
use App\Domain\Tire\TireDataFetcher;
use App\Domain\Tire\TireRepository;
use App\Domain\Tire\TireSelection;
use App\Domain\Tire\TreadRename;
use App\Domain\Tire\TreadRenamer;

final class ExecuteController
{
    private ImportSession  $session;
    private TireRepository $repo;
    private ImportHistoryRepository $history;

    public function __construct()
    {
        $this->session = new ImportSession(dirname(__DIR__, 2) . '/storage');
        $this->repo    = new TireRepository();
        $this->history = new ImportHistoryRepository();
    }

    public function show(): void
    {
        $uuid = $this->session->uuid();

        if ($uuid === null || $this->session->step() < 4) {
            $this->redirect('');
            return;
        }

        $mapping    = $this->session->readArray($uuid, 'mapping');
        $newModels  = array_filter(
            $mapping,
            static fn (mixed $m): bool => is_array($m) && RowField::flag(RowField::normalise($m), 'is_new'),
        );
        $seasonMap  = array_column($this->repo->allSeasons(), 'season', 'id');

        $fetcher      = new TireDataFetcher(Bootstrap::pdo());
        $treadRenames = array_map(
            static fn (TreadRename $r): array => $r->toArray() + [
                'tires' => $fetcher->countSelection(TireSelection::treads([$r->treadId])),
            ],
            $this->treadRenames($uuid),
        );

        $csvPath  = $this->session->csvPath($uuid);
        $preview  = null;

        if (is_file($csvPath)) {
            $processor = new ImportProcessor($this->repo, Bootstrap::logger());
            $preview   = $processor->preview($csvPath, $mapping);
        }

        require dirname(__DIR__, 2) . '/templates/step4-confirm.php';
    }

    public function execute(): void
    {
        if (!Csrf::validate(Request::post('_csrf'))) {
            $this->redirect('execute');
            return;
        }

        $uuid = $this->session->uuid();

        if ($uuid === null) {
            $this->redirect('');
            return;
        }

        $mapping = $this->session->readArray($uuid, 'mapping');
        $csvPath = $this->session->csvPath($uuid);

        if (!is_file($csvPath)) {
            $_SESSION['_flash_error'] = 'Plik CSV zniknął z serwera. Zacznij import od nowa.';
            $this->redirect('execute');
            return;
        }

        $options = [
            'update_price'     => !empty($_POST['update_price']),
            'update_labels'    => !empty($_POST['update_labels']),
            'update_inne'      => !empty($_POST['update_inne']),
            'update_structure' => !empty($_POST['update_structure']),
            'update_ref'       => !empty($_POST['update_ref']),
            'update_tread'     => !empty($_POST['update_tread']),
        ];

        Bootstrap::logger()->info('Import started', ['uuid' => $uuid, 'options' => $options]);

        $pdo         = Bootstrap::pdo();
        $transaction = new ImportTransaction($pdo);
        $renames     = $this->treadRenames($uuid);

        try {
            $stats = $transaction->run(function () use ($transaction, $pdo, $mapping, $csvPath, $options, $renames): array {
                $renamer = new TreadRenamer(
                    $this->repo,
                    new TireDataFetcher($pdo),
                    new ProductNameRegenerator(new NameGenerator(new SuffixExtractor())),
                    Bootstrap::logger(),
                );

                // 0. Najpierw nazwy bieżników — wiersze z pliku dostaną już nową nazwę
                $renamer->rename($renames);

                // 1. Create new tread records (models marked as new with assigned seasons)
                $resolvedMapping = $this->createNewTreads($mapping);

                // 2. Run the actual import
                $processor = new ImportProcessor($this->repo, Bootstrap::logger());
                $stats     = $processor->run($csvPath, $resolvedMapping, $transaction, $options);

                // 2a. Opony przemianowanych bieżników spoza pliku
                $stats['tread_renames']     = array_map(static fn (TreadRename $r): array => $r->toArray(), $renames);
                $stats['names_regenerated'] = $renamer->regenerateNames($renames);

                // 3. Rebuild legacy code lookup table, like the old import task did.
                $stats['tires_codes'] = (new TireCodesUpdater($pdo))->rebuild();

                return $stats;
            });
        } catch (\Throwable $e) {
            Bootstrap::logger()->error('Import failed', ['uuid' => $uuid, 'error' => $e->getMessage()]);
            $_SESSION['_flash_error'] = $e instanceof ImportAborted
                ? 'Import przerwany i wycofany — ' . rtrim($e->getMessage(), '.') . '. Uruchom go jeszcze raz.'
                : 'Import zakończony błędem: ' . $e->getMessage();
            $this->redirect('execute');
            return;
        }

        Bootstrap::logger()->info('Import finished', array_merge(['uuid' => $uuid], $stats));

        $this->session->write($uuid, 'result', $stats);
        $this->session->setStep(5);

        // Record pricings_tires per producer (outside transaction, like pricingSave)
        try {
            foreach (RowField::rows($stats, 'pricings_tires') as $producerId => $entry) {
                $tireIds = array_map(intval(...), RowField::strings($entry, 'tires'));

                $this->repo->createPricingRecord($tireIds, (int) $producerId, RowField::text($entry, 'name'), count($tireIds));
            }
        } catch (\Throwable $pricingError) {
            Bootstrap::logger()->warning('Failed to record pricings_tires', [
                'error' => $pricingError->getMessage(),
            ]);
        }

        if ($renames !== []) {
            $options['tread_renames'] = array_map(static fn (TreadRename $r): array => $r->toArray(), $renames);
        }

        // Record import in history per producer (outside transaction)
        try {
            $perProducer = RowField::rows($stats, 'per_producer');
            if (!empty($perProducer)) {
                foreach ($perProducer as $producerName => $pStats) {
                    $this->history->recordImport(
                        $producerName,
                        RowField::integer($pStats, 'created'),
                        RowField::integer($pStats, 'updated'),
                        RowField::integer($pStats, 'skipped'),
                        RowField::integer($pStats, 'errors'),
                        [], // error messages are shared across producers
                        $options
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
                    $options
                );
            }
        } catch (\Throwable $historyError) {
            Bootstrap::logger()->warning('Failed to record import history', [
                'error' => $historyError->getMessage(),
            ]);
        }

        $this->redirect('result');
    }

    public function showResult(): void
    {
        $uuid = $this->session->uuid();

        if ($uuid === null || $this->session->step() < 5) {
            $this->redirect('');
            return;
        }

        $stats = $this->session->readArray($uuid, 'result');

        require dirname(__DIR__, 2) . '/templates/result.php';
    }

    /**
     * @param array<string, mixed> $mapping
     *
     * @return array<string, mixed>
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
                Bootstrap::logger()->warning('Producer not found, skipping new tread', [
                    'producer' => $entry['producer_name'],
                ]);
                unset($mapping[$key]);
                continue;
            }

            $treadId          = $this->repo->createTread(RowField::integer($producer, 'id'), $modelName, $seasonId);
            $entry['tread_id'] = $treadId;
            $entry['is_new']   = false; // mark as resolved

            Bootstrap::logger()->info('Created tread', [
                'tread_id' => $treadId,
                'name'     => $entry['model_name'],
                'producer' => $entry['producer_name'],
                'season'   => $entry['season_id'],
            ]);
        }
        unset($entry);

        return $mapping;
    }

    /** @return list<TreadRename> */
    private function treadRenames(string $uuid): array
    {
        $renames = [];

        foreach ($this->session->readArray($uuid, 'tread_renames') as $row) {
            if (is_array($row)) {
                $renames[] = TreadRename::fromArray(RowField::normalise($row));
            }
        }

        return $renames;
    }

    private function redirect(string $path): void
    {
        $base = rtrim(dirname(Request::server('SCRIPT_NAME')), '/');
        header('Location: ' . $base . '/' . ltrim($path, '/'));
        exit;
    }
}
