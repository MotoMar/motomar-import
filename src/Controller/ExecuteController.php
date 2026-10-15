<?php

declare(strict_types=1);

namespace App\Controller;

use App\Domain\Tire\RowField;
use App\Request;
use App\Bootstrap;
use App\Csrf;
use App\Domain\Import\ImportAborted;
use App\Domain\Import\ImportHistoryRepository;
use App\Domain\Import\ImportRunner;
use App\Domain\Import\ImportSession;
use App\Domain\Tire\ImportProcessor;
use App\Domain\Tire\TireDataFetcher;
use App\Domain\Tire\TireRepository;
use App\Domain\Tire\TireSelection;
use App\Domain\Tire\TreadRename;

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

        $runner = new ImportRunner(Bootstrap::pdo(), $this->repo, $this->history, Bootstrap::logger());

        try {
            $stats = $runner->run($uuid, $csvPath, $mapping, $options, $this->treadRenames($uuid));
        } catch (\Throwable $e) {
            $_SESSION['_flash_error'] = $e instanceof ImportAborted
                ? 'Import przerwany i wycofany — ' . rtrim($e->getMessage(), '.') . '. Uruchom go jeszcze raz.'
                : 'Import zakończony błędem: ' . $e->getMessage();
            $this->redirect('execute');
            return;
        }

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

        $stats = $this->session->readArray($uuid, 'result');

        require dirname(__DIR__, 2) . '/templates/result.php';
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
