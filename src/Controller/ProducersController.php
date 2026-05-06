<?php

declare(strict_types=1);

namespace App\Controller;

use App\Bootstrap;
use App\Domain\Import\ImportSession;
use App\Domain\Tire\ImportProcessor;
use App\Domain\Tire\TireRepository;

final class ProducersController
{
    private ImportSession $session;

    public function __construct()
    {
        $this->session = new ImportSession(dirname(__DIR__, 2) . '/storage');
    }

    /**
     * Step 2a: Display new producers for confirmation/editing
     */
    public function show(): void
    {
        $logger = Bootstrap::logger();
        $csvPath = $this->session->getCsvPath();

        $logger->info('ProducersController::show - Start', [
            'uuid' => $this->session->uuid(),
            'csvPath' => $csvPath,
        ]);

        if ($csvPath === null) {
            $logger->warning('ProducersController::show - No CSV path, redirecting to home');
            $this->redirect('');
            return;
        }

        $repo = Bootstrap::tireRepository();
        $processor = new ImportProcessor($repo, $logger);

        // Detect new producers
        $newProducers = $processor->detectNewProducers($csvPath);

        $logger->info('ProducersController::show - New producers detected', [
            'count' => count($newProducers),
            'producers' => array_column($newProducers, 'name'),
        ]);

        // If no new producers, skip to mapping
        if (empty($newProducers)) {
            $logger->info('ProducersController::show - No new producers, redirecting to mapping');
            $this->redirect('mapping');
            return;
        }

        // Get producer classifications (ekonomiczna/średnia/premium)
        $classifications = $repo->getProducerClassifications();

        // Store in session for form submission
        $_SESSION['new_producers'] = $newProducers;

        $logger->info('ProducersController::show - Rendering form', [
            'new_producers_count' => count($newProducers),
        ]);

        // Render form
        require dirname(__DIR__, 2) . '/templates/step2a.php';
    }

    /**
     * Step 2a: Save corrected producer names and create them
     */
    public function save(): void
    {
        if (!isset($_SESSION['new_producers'])) {
            $this->redirect('');
            return;
        }

        $newProducers = $_SESSION['new_producers'];
        $producerMapping = [];

        // Build mapping from form data
        foreach ($newProducers as $producer) {
            $csvName = $producer['name'];
            $nameField = 'producer_name_' . base64_encode($csvName);
            $classField = 'producer_class_' . base64_encode($csvName);

            $correctedName = trim($_POST[$nameField] ?? '');
            $classification = (int) ($_POST[$classField] ?? 2); // Default: Średnia

            if ($correctedName === '') {
                $correctedName = $csvName;
            }

            $producerMapping[$csvName] = [
                'name' => $correctedName,
                'classification' => $classification,
            ];
        }

        // Create producers with corrected names
        $repo = Bootstrap::tireRepository();
        $logger = Bootstrap::logger();
        $processor = new ImportProcessor($repo, $logger);

        try {
            $processor->createProducers($producerMapping);

            // Store mapping in session (for import phase)
            $_SESSION['producer_mapping'] = $producerMapping;

            // Clear temporary data
            unset($_SESSION['new_producers']);

            Bootstrap::logger()->info('New producers created', [
                'count' => count($producerMapping),
                'mapping' => $producerMapping,
            ]);

            // Redirect to mapping
            $this->redirect('mapping');

        } catch (\Exception $e) {
            $_SESSION['error'] = 'Błąd podczas tworzenia producentów: ' . $e->getMessage();
            $this->redirect('producers');
        }
    }

    private function redirect(string $path): void
    {
        $base = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
        header('Location: ' . $base . '/' . $path);
        exit;
    }
}
