<?php

declare(strict_types=1);

namespace App\Controller;

use App\Bootstrap;
use App\Csrf;
use App\Domain\Csv\CsvParser;
use App\Domain\Import\ImportSession;

final class UploadController
{
    private ImportSession $session;

    public function __construct()
    {
        $this->session = new ImportSession(dirname(__DIR__, 3) . '/storage');
    }

    public function show(): void
    {
        $error = $_SESSION['_flash_error'] ?? null;
        unset($_SESSION['_flash_error']);

        require dirname(__DIR__, 2) . '/templates/step1.php';
    }

    public function handle(): void
    {
        if (!Csrf::validate($_POST['_csrf'] ?? '')) {
            $this->flashError('Nieprawidłowy token CSRF. Odśwież stronę i spróbuj ponownie.');
            $this->redirect('');
            return;
        }

        $file = $_FILES['csv_file'] ?? null;

        if (!is_array($file) || $file['error'] !== UPLOAD_ERR_OK) {
            $this->flashError('Nie wybrano pliku lub wystąpił błąd przesyłania.');
            $this->redirect('');
            return;
        }

        $maxBytes = (int) Bootstrap::config()['upload_max_size_mb'] * 1024 * 1024;

        if ($file['size'] > $maxBytes) {
            $this->flashError(sprintf('Plik jest za duży. Maksymalny rozmiar: %d MB.', Bootstrap::config()['upload_max_size_mb']));
            $this->redirect('');
            return;
        }

        // Validate by extension — do not rely on MIME type which can be spoofed
        $originalName = $file['name'] ?? '';
        if (!preg_match('/\.csv$/i', $originalName)) {
            $this->flashError('Wymagany plik z rozszerzeniem .csv');
            $this->redirect('');
            return;
        }

        $this->session->reset();
        $uuid    = $this->session->start();
        session_regenerate_id(true);
        $csvPath = $this->session->csvPath($uuid);

        if (!move_uploaded_file($file['tmp_name'], $csvPath)) {
            $this->flashError('Błąd zapisu pliku na serwerze.');
            $this->redirect('');
            return;
        }

        try {
            $parser = new CsvParser();
            $rows   = $parser->parseFile($csvPath);

            if (empty($rows)) {
                $this->flashError('Plik CSV jest pusty lub nie zawiera poprawnych wierszy.');
                $this->session->reset();
                $this->redirect('');
                return;
            }

            $models = $parser->extractUniqueModels($rows);
            $this->session->write($uuid, 'models', $models);
            $this->session->setStep(2);

            Bootstrap::logger()->info('CSV uploaded', [
                'uuid'   => $uuid,
                'rows'   => count($rows),
                'models' => count($models),
            ]);

        } catch (\RuntimeException $e) {
            $this->flashError('Błąd parsowania pliku: ' . $e->getMessage());
            $this->session->reset();
            $this->redirect('');
            return;
        }

        $this->redirect('mapping');
    }

    public function reset(): void
    {
        $this->session->reset();
        $this->redirect('');
    }

    private function flashError(string $message): void
    {
        $_SESSION['_flash_error'] = $message;
    }

    private function redirect(string $path): void
    {
        static $allowed = ['', 'mapping', 'seasons', 'execute', 'result', 'reset'];

        if (!in_array($path, $allowed, true)) {
            $path = '';
        }

        $base = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
        header('Location: ' . $base . '/' . $path);
        exit;
    }
}
