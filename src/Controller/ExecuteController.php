<?php

declare(strict_types=1);

namespace App\Controller;

use App\Bootstrap;
use App\Csrf;
use App\Domain\Import\ImportSession;
use App\Domain\Tire\ImportProcessor;
use App\Domain\Tire\TireRepository;

final class ExecuteController
{
    private ImportSession  $session;
    private TireRepository $repo;

    public function __construct()
    {
        $this->session = new ImportSession(dirname(__DIR__, 2) . '/storage');
        $this->repo    = new TireRepository();
    }

    public function show(): void
    {
        $uuid = $this->session->uuid();

        if ($uuid === null || $this->session->step() < 4) {
            $this->redirect('');
            return;
        }

        $mapping    = $this->session->read($uuid, 'mapping') ?? [];
        $newModels  = array_filter($mapping, fn($m) => $m['is_new']);
        $seasonMap  = array_column($this->repo->allSeasons(), 'season', 'id');

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
        if (!Csrf::validate($_POST['_csrf'] ?? '')) {
            $this->redirect('execute');
            return;
        }

        $uuid = $this->session->uuid();

        if ($uuid === null) {
            $this->redirect('');
            return;
        }

        $mapping = $this->session->read($uuid, 'mapping') ?? [];
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
            'update_pricing'   => !empty($_POST['update_pricing']),
        ];

        Bootstrap::logger()->info('Import started', ['uuid' => $uuid, 'options' => $options]);

        $pdo = Bootstrap::db()->pdo;

        try {
            $pdo->beginTransaction();

            // 1. Create new tread records (models marked as new with assigned seasons)
            $resolvedMapping = $this->createNewTreads($mapping);

            // 2. Run the actual import
            $processor = new ImportProcessor($this->repo, Bootstrap::logger());
            $stats     = $processor->run($csvPath, $resolvedMapping, $options);

            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            Bootstrap::logger()->error('Import failed', ['uuid' => $uuid, 'error' => $e->getMessage()]);
            $_SESSION['_flash_error'] = 'Import zakończony błędem: ' . $e->getMessage();
            $this->redirect('execute');
            return;
        }

        Bootstrap::logger()->info('Import finished', array_merge(['uuid' => $uuid], $stats));

        $this->session->write($uuid, 'result', $stats);
        $this->session->setStep(5);

        $this->redirect('result');
    }

    public function showResult(): void
    {
        $uuid = $this->session->uuid();

        if ($uuid === null || $this->session->step() < 5) {
            $this->redirect('');
            return;
        }

        $stats = $this->session->read($uuid, 'result') ?? [];

        require dirname(__DIR__, 2) . '/templates/result.php';
    }

    private function createNewTreads(array $mapping): array
    {
        foreach ($mapping as $key => &$entry) {
            if (!$entry['is_new']) {
                continue;
            }

            if ($entry['season_id'] <= 0) {
                throw new \RuntimeException(
                    "Brak sezonu dla modelu: {$entry['model_name']} / {$entry['producer_name']}"
                );
            }

            $producer = $this->repo->producerByName($entry['producer_name']);

            if ($producer === null) {
                Bootstrap::logger()->warning('Producer not found, skipping new tread', [
                    'producer' => $entry['producer_name'],
                ]);
                unset($mapping[$key]);
                continue;
            }

            $treadId          = $this->repo->createTread($producer['id'], $entry['model_name'], $entry['season_id']);
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

    private function redirect(string $path): void
    {
        $base = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
        header('Location: ' . $base . '/' . ltrim($path, '/'));
        exit;
    }
}
