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
        $this->session = new ImportSession(dirname(__DIR__, 2) . '/storage');
    }

    public function show(): void
    {
        $error = $_SESSION['_flash_error'] ?? null;
        unset($_SESSION['_flash_error']);

        require dirname(__DIR__, 2) . '/templates/step1.php';
    }

    public function handle(): void
    {
        Bootstrap::logger()->info('Upload started', [
            'post_keys' => array_keys($_POST),
            'files_keys' => array_keys($_FILES),
        ]);

        if (!Csrf::validate($_POST['_csrf'] ?? '')) {
            $this->flashError('Nieprawidłowy token CSRF. Odśwież stronę i spróbuj ponownie.');
            $this->redirect('');
            return;
        }

        $file = $_FILES['csv_file'] ?? null;

        if (!is_array($file) || $file['error'] !== UPLOAD_ERR_OK) {
            $error = is_array($file) ? ($file['error'] ?? 'unknown') : 'no file';
            Bootstrap::logger()->error('File upload failed', ['error' => $error, 'file' => $file]);
            $this->flashError('Nie wybrano pliku lub wystąpił błąd przesyłania.');
            $this->redirect('');
            return;
        }

        Bootstrap::logger()->info('File uploaded', [
            'name' => $file['name'],
            'size' => $file['size'],
            'tmp_name' => $file['tmp_name'],
        ]);

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

        Bootstrap::logger()->info('Session created', [
            'uuid' => $uuid,
            'csvPath' => $csvPath,
            'storage_dir' => dirname(__DIR__, 2) . '/storage',
        ]);

        if (!move_uploaded_file($file['tmp_name'], $csvPath)) {
            Bootstrap::logger()->error('Failed to move uploaded file', [
                'from' => $file['tmp_name'],
                'to' => $csvPath,
                'tmp_exists' => file_exists($file['tmp_name']),
                'target_dir_exists' => is_dir(dirname($csvPath)),
                'target_dir_writable' => is_writable(dirname($csvPath)),
            ]);
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
            Bootstrap::logger()->error('CSV parsing failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            $this->flashError('Błąd parsowania pliku: ' . $e->getMessage());
            $this->session->reset();
            $this->redirect('');
            return;
        } catch (\Throwable $e) {
            Bootstrap::logger()->error('Unexpected error during upload', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            $this->flashError('Nieoczekiwany błąd: ' . $e->getMessage());
            $this->session->reset();
            $this->redirect('');
            return;
        }

        // Step 1 → Step 2a (check for new producers first)
        $this->redirect('producers');
    }

    public function reset(): void
    {
        try {
            Bootstrap::logger()->info('Reset requested', [
                'uuid' => $this->session->uuid(),
                'step' => $this->session->step(),
            ]);

            $this->session->reset();

            Bootstrap::logger()->info('Reset completed successfully');
        } catch (\Throwable $e) {
            Bootstrap::logger()->error('Reset failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            $this->flashError('Błąd podczas resetowania sesji: ' . $e->getMessage());
        }

        $this->redirect('');
    }

    private function flashError(string $message): void
    {
        $_SESSION['_flash_error'] = $message;
    }

    private function redirect(string $path): void
    {
        static $allowed = ['', 'producers', 'mapping', 'seasons', 'execute', 'result', 'reset'];

        if (!in_array($path, $allowed, true)) {
            $path = '';
        }

        $base = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
        header('Location: ' . $base . '/' . $path);
        exit;
    }
}
