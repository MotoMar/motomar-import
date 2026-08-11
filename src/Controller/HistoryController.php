<?php

declare(strict_types=1);

namespace App\Controller;

use App\Request;
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
            $page = max(1, (int) Request::query('page', '1'));
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
            $id = (int) Request::query('id', '0');

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
            $import['options'] = $this->history->decodeOptions($import['options'] ?? null);

            require dirname(__DIR__, 2) . '/templates/history-detail.php';
        } catch (\Throwable $e) {
            error_log('HistoryController::detail error: ' . $e->getMessage());
            http_response_code(500);
            echo 'Error: ' . htmlspecialchars($e->getMessage());
        }
    }

    private function redirect(string $path): void
    {
        $base = rtrim(dirname(Request::server('SCRIPT_NAME')), '/');
        header('Location: ' . $base . '/' . ltrim($path, '/'));
        exit;
    }
}
