<?php

declare(strict_types=1);

namespace App\Controller;

use App\Bootstrap;
use App\Csrf;
use App\Domain\Import\ImportSession;
use App\Domain\Tire\TireRepository;

final class SeasonsController
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

        if ($uuid === null || $this->session->step() < 3) {
            $this->redirect('');
            return;
        }

        $mapping = $this->session->read($uuid, 'mapping') ?? [];
        $newModels = array_filter($mapping, fn($m) => $m['is_new']);
        $seasons   = $this->repo->allSeasons();

        require dirname(__DIR__, 2) . '/templates/step3.php';
    }

    public function handle(): void
    {
        if (!Csrf::validate($_POST['_csrf'] ?? '')) {
            Bootstrap::logger()->warning('CSRF validation failed', ['endpoint' => '/seasons']);
            $_SESSION['_flash_error'] = 'Nieprawidłowy token bezpieczeństwa. Odśwież stronę i spróbuj ponownie.';
            $this->redirect('seasons');
            return;
        }

        $uuid = $this->session->uuid();

        if ($uuid === null) {
            $this->redirect('');
            return;
        }

        $mapping        = $this->session->read($uuid, 'mapping') ?? [];
        $seasonPost     = $_POST['season'] ?? [];
        $modelNamePost  = $_POST['model_name'] ?? [];

        foreach ($mapping as $key => &$entry) {
            if (!$entry['is_new']) {
                continue;
            }

            $seasonId = (int) ($seasonPost[$key] ?? 0);

            if ($seasonId <= 0) {
                $_SESSION['_flash_error'] = "Nie przypisano sezonu dla modelu: {$entry['model_name']}";
                $this->redirect('seasons');
                return;
            }

            $editedName = trim($modelNamePost[$key] ?? '');
            if ($editedName !== '') {
                $entry['model_name'] = mb_substr($editedName, 0, 120);
            }

            $entry['season_id'] = $seasonId;
        }
        unset($entry);

        $this->session->write($uuid, 'mapping', $mapping);
        $this->session->setStep(4);

        Bootstrap::logger()->info('Seasons assigned', ['uuid' => $uuid]);

        $this->redirect('execute');
    }

    private function redirect(string $path): void
    {
        $base = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
        header('Location: ' . $base . '/' . ltrim($path, '/'));
        exit;
    }
}
