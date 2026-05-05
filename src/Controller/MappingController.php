<?php

declare(strict_types=1);

namespace App\Controller;

use App\Bootstrap;
use App\Csrf;
use App\Domain\Import\ImportSession;
use App\Domain\Tire\TireRepository;

final class MappingController
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

        Bootstrap::logger()->info('Mapping page accessed', [
            'uuid' => $uuid,
            'step' => $this->session->step(),
            'session_id' => session_id(),
        ]);

        if ($uuid === null || $this->session->step() < 2) {
            Bootstrap::logger()->warning('Mapping: invalid session or step', [
                'uuid' => $uuid,
                'step' => $this->session->step(),
            ]);
            $this->redirect('');
            return;
        }

        $models  = $this->session->read($uuid, 'models') ?? [];
        $seasons = $this->repo->allSeasons();

        Bootstrap::logger()->info('Mapping data loaded', [
            'uuid' => $uuid,
            'models_count' => count($models),
            'seasons_count' => count($seasons),
            'models_keys' => array_keys($models),
        ]);

        // Load treads only for producers that appear in this CSV — not all 100+ producers
        $producerNamesInCsv = array_unique(array_column(array_values($models), 'producer_name'));

        $treadsByProducer = [];
        foreach ($producerNamesInCsv as $name) {
            $treadsByProducer[$name] = []; // Initialize with empty array for all producers
            $producer = $this->repo->producerByName($name);
            if ($producer !== null) {
                $treadsByProducer[$name] = $this->repo->treadsByProducer($producer['id']);
            }
        }

        require dirname(__DIR__, 2) . '/templates/step2.php';
    }

    public function handle(): void
    {
        if (!Csrf::validate($_POST['_csrf'] ?? '')) {
            Bootstrap::logger()->warning('CSRF validation failed', ['endpoint' => '/mapping']);
            $_SESSION['_flash_error'] = 'Nieprawidłowy token bezpieczeństwa. Odśwież stronę i spróbuj ponownie.';
            $this->redirect('mapping');
            return;
        }

        $uuid = $this->session->uuid();

        if ($uuid === null) {
            $this->redirect('');
            return;
        }

        $models  = $this->session->read($uuid, 'models') ?? [];
        $mapping = [];
        $hasNew  = false;

        Bootstrap::logger()->info('Mapping form submitted', [
            'uuid' => $uuid,
            'models_count' => count($models),
            'post_action_keys' => array_keys($_POST['action'] ?? []),
            'post_existing_tread_keys' => array_keys($_POST['existing_tread'] ?? []),
            'post_keys' => array_keys($_POST),
        ]);

        foreach ($models as $key => $model) {
            $producerName = $model['producer_name'];
            $modelName    = $model['model_name'];

            $action = $_POST['action'][$key] ?? 'existing';

            Bootstrap::logger()->debug('Processing model', [
                'key' => $key,
                'producer' => $producerName,
                'model' => $modelName,
                'action' => $action,
                'has_existing_tread' => isset($_POST['existing_tread'][$key]),
                'existing_tread_value' => $_POST['existing_tread'][$key] ?? null,
            ]);

            if ($action === 'existing') {
                $treadId = (int) ($_POST['existing_tread'][$key] ?? 0);

                if ($treadId === 0) {
                    $_SESSION['_flash_error'] = "Nie wybrano modelu z bazy dla: {$producerName} / {$modelName}";
                    $this->redirect('mapping');
                    return;
                }

                $tread = $this->repo->treadById($treadId);

                if ($tread === null) {
                    $_SESSION['_flash_error'] = "Wybrany model nie istnieje w bazie: ID {$treadId}";
                    $this->redirect('mapping');
                    return;
                }

                $mapping[$key] = [
                    'producer_name' => $producerName,
                    'model_name'    => $modelName,
                    'tread_id'      => $treadId,
                    'season_id'     => (int) ($tread['season_id'] ?? 0),
                    'is_new'        => false,
                ];
            }

            if ($action === 'new') {
                $hasNew = true;

                $producerInDb = $this->repo->producerByName($producerName);
                if ($producerInDb === null) {
                    $submittedName = trim($_POST['new_producer_name'][$key] ?? $producerName);
                    if ($submittedName === '') {
                        $submittedName = $producerName;
                    }
                    if ($this->repo->producerByName($submittedName) === null) {
                        $this->repo->createProducer($submittedName);
                        Bootstrap::logger()->info('Created producer', ['name' => $submittedName, 'uuid' => $uuid]);
                    }
                    $producerName = $submittedName;
                }

                $mapping[$key] = [
                    'producer_name' => $producerName,
                    'model_name'    => $modelName,
                    'tread_id'      => 0,
                    'season_id'     => 0,
                    'is_new'        => true,
                ];
            }
        }

        $this->session->write($uuid, 'mapping', $mapping);
        $this->session->setStep($hasNew ? 3 : 4);

        Bootstrap::logger()->info('Mapping saved', [
            'uuid'    => $uuid,
            'total'   => count($mapping),
            'new'     => count(array_filter($mapping, fn($m) => $m['is_new'])),
            'hasNew'  => $hasNew,
        ]);

        $this->redirect($hasNew ? 'seasons' : 'execute');
    }

    private function redirect(string $path): void
    {
        $base = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
        header('Location: ' . $base . '/' . ltrim($path, '/'));
        exit;
    }
}
