<?php

declare(strict_types=1);

namespace App\Domain\Import;

use App\Bootstrap;
use Medoo\Medoo;

final class ImportHistoryRepository
{
    private Medoo $db;

    public function __construct()
    {
        $this->db = Bootstrap::db();
        $this->ensureTableExists();
    }

    /**
     * Record a new import in the history.
     */
    public function recordImport(
        string $filename,
        int $created,
        int $updated,
        int $skipped,
        int $errors,
        array $errorMessages,
        array $options
    ): int {
        $this->db->insert('import_history', [
            'filename'        => $filename,
            'created_count'   => $created,
            'updated_count'   => $updated,
            'skipped_count'   => $skipped,
            'error_count'     => $errors,
            'error_messages'  => json_encode($errorMessages, JSON_UNESCAPED_UNICODE),
            'options'         => json_encode($options, JSON_UNESCAPED_UNICODE),
            'imported_at'     => date('Y-m-d H:i:s'),
            'imported_by'     => $_SESSION['_user'] ?? 'unknown',
        ]);

        return (int) $this->db->id();
    }

    /**
     * Get all imports sorted by date (newest first).
     * @return array<int, array>
     */
    public function allImports(int $limit = 100, int $offset = 0): array
    {
        return $this->db->select('import_history', '*', [
            'ORDER'  => ['id' => 'DESC'],
            'LIMIT'  => [$offset, $limit],
        ]) ?: [];
    }

    /**
     * Get total count of imports.
     */
    public function countImports(): int
    {
        $result = $this->db->count('import_history');
        return (int) $result;
    }

    /**
     * Get a single import by ID.
     */
    public function getImport(int $id): ?array
    {
        return $this->db->get('import_history', '*', ['id' => $id]) ?: null;
    }

    /**
     * Get import statistics (summary).
     */
    public function getStatistics(): array
    {
        $result = $this->db->get('import_history', [
            'total_imports' => 'COUNT(*)',
            'total_created' => 'SUM(created_count)',
            'total_updated' => 'SUM(updated_count)',
            'total_skipped' => 'SUM(skipped_count)',
            'total_errors'  => 'SUM(error_count)',
        ]);

        return [
            'total_imports' => (int) ($result['total_imports'] ?? 0),
            'total_created' => (int) ($result['total_created'] ?? 0),
            'total_updated' => (int) ($result['total_updated'] ?? 0),
            'total_skipped' => (int) ($result['total_skipped'] ?? 0),
            'total_errors'  => (int) ($result['total_errors'] ?? 0),
        ];
    }

    /**
     * Ensure the import_history table exists, create it if not.
     */
    private function ensureTableExists(): void
    {
        try {
            $this->db->get('import_history', 'id', 'LIMIT 1');
        } catch (\Throwable) {
            $this->createTable();
        }
    }

    /**
     * Create the import_history table.
     */
    private function createTable(): void
    {
        $sql = <<<SQL
            CREATE TABLE IF NOT EXISTS import_history (
                id INT AUTO_INCREMENT PRIMARY KEY,
                filename VARCHAR(255) NOT NULL,
                created_count INT NOT NULL DEFAULT 0,
                updated_count INT NOT NULL DEFAULT 0,
                skipped_count INT NOT NULL DEFAULT 0,
                error_count INT NOT NULL DEFAULT 0,
                error_messages LONGTEXT,
                options LONGTEXT,
                imported_at DATETIME NOT NULL,
                imported_by VARCHAR(100) NOT NULL DEFAULT 'unknown',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX (imported_at),
                INDEX (imported_by)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        SQL;

        $this->db->pdo->exec($sql);
    }
}

