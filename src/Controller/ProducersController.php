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
        $csvPath = $this->session->getCsvPath();

        if ($csvPath === null) {
            $this->redirect('');
            return;
        }

        $repo = Bootstrap::tireRepository();
        $logger = Bootstrap::logger();
        $processor = new ImportProcessor($repo, $logger);

        // Detect new producers
        $newProducers = $processor->detectNewProducers($csvPath);

        // If no new producers, skip to mapping
        if (empty($newProducers)) {
            $this->redirect('mapping');
            return;
        }

        // Store in session for form submission
        $_SESSION['new_producers'] = $newProducers;

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
            $fieldName = 'producer_name_' . base64_encode($csvName);
            $correctedName = trim($_POST[$fieldName] ?? '');

            if ($correctedName === '') {
                $correctedName = $csvName;
            }

            $producerMapping[$csvName] = $correctedName;
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
