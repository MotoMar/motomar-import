<?php

declare(strict_types=1);

namespace App\Controller;

use App\Domain\Import\ImportHistoryRepository;

final class HistoryController
{
    private ImportHistoryRepository $history;

    public function __construct()
    {
        $this->history = new ImportHistoryRepository();
    }

    public function show(): void
    {
        try {
            $page = (int) ($_GET['page'] ?? 1);
            $perPage = 20;
            $offset = ($page - 1) * $perPage;

            $imports = $this->history->allImports($perPage, $offset);
            $stats = $this->history->getStatistics();
            $totalCount = $this->history->countImports();
            $totalPages = ceil($totalCount / $perPage);

            require dirname(__DIR__, 2) . '/templates/history.php';
        } catch (\Throwable $e) {
            error_log('HistoryController::show error: ' . $e->getMessage());
            http_response_code(500);
            echo 'Error: ' . htmlspecialchars($e->getMessage());
        }
    }

    public function detail(): void
    {
        try {
            $id = (int) ($_GET['id'] ?? 0);

            if ($id === 0) {
                $this->redirect('history');
            }

            $import = $this->history->getImport($id);

            if ($import === null) {
                $this->redirect('history');
            }

            // Decode JSON fields
            if ($import['error_messages']) {
                $import['error_messages'] = json_decode($import['error_messages'], true) ?? [];
            }
            if ($import['options']) {
                $import['options'] = json_decode($import['options'], true) ?? [];
            }

            require dirname(__DIR__, 2) . '/templates/history-detail.php';
        } catch (\Throwable $e) {
            error_log('HistoryController::detail error: ' . $e->getMessage());
            http_response_code(500);
            echo 'Error: ' . htmlspecialchars($e->getMessage());
        }
    }

    private function redirect(string $path): void
    {
        $base = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
        header('Location: ' . $base . '/' . ltrim($path, '/'));
        exit;
    }
}

