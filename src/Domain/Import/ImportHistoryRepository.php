<?php

declare(strict_types=1);

namespace App\Domain\Import;

use App\Auth;
use App\Bootstrap;
use Medoo\Medoo;
use PDO;

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
        string $producer,
        int $created,
        int $updated,
        int $skipped,
        int $errors,
        array $errorMessages,
        array $options
    ): int {
        // Extract username without domain
        $userEmail = $_SESSION['_user'] ?? Auth::email() ?? 'unknown';
        $username = $this->extractUsername($userEmail);

        $this->db->insert('import_history', [
            'producer'        => $producer,
            'created_count'   => $created,
            'updated_count'   => $updated,
            'skipped_count'   => $skipped,
            'error_count'     => $errors,
            'error_messages'  => json_encode($errorMessages, JSON_UNESCAPED_UNICODE),
            'options'         => json_encode($options, JSON_UNESCAPED_UNICODE),
            'imported_at'     => date('Y-m-d H:i:s'),
            'imported_by'     => $username,
        ]);

        return (int) $this->db->id();
    }

    /**
     * Get all imports sorted by date (newest first).
     * @return array<int, array>
     */
    public function allImports(int $limit = 100, int $offset = 0): array
    {
        try {
            return $this->db->select('import_history', '*', [
                'ORDER'  => ['id' => 'DESC'],
                'LIMIT'  => [$offset, $limit],
            ]) ?: [];
        } catch (\Throwable $e) {
            error_log('ImportHistoryRepository::allImports error: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Get total count of imports.
     */
    public function countImports(): int
    {
        try {
            $result = $this->db->count('import_history');
            return (int) $result;
        } catch (\Throwable $e) {
            error_log('ImportHistoryRepository::countImports error: ' . $e->getMessage());
            return 0;
        }
    }

    /**
     * Get a single import by ID.
     */
    public function getImport(int $id): ?array
    {
        try {
            return $this->db->get('import_history', '*', ['id' => $id]) ?: null;
        } catch (\Throwable $e) {
            error_log('ImportHistoryRepository::getImport error: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Get import statistics (summary).
     */
    public function getStatistics(): array
    {
        try {
            $pdo = Bootstrap::pdo();
            $stmt = $pdo->query('SELECT COUNT(*) as total_imports, SUM(created_count) as total_created, SUM(updated_count) as total_updated, SUM(skipped_count) as total_skipped, SUM(error_count) as total_errors FROM import_history');
            $result = $stmt->fetch(PDO::FETCH_ASSOC);

            return [
                'total_imports' => (int) ($result['total_imports'] ?? 0),
                'total_created' => (int) ($result['total_created'] ?? 0),
                'total_updated' => (int) ($result['total_updated'] ?? 0),
                'total_skipped' => (int) ($result['total_skipped'] ?? 0),
                'total_errors'  => (int) ($result['total_errors'] ?? 0),
            ];
        } catch (\Throwable $e) {
            // If table doesn't exist, return empty stats
            error_log('ImportHistoryRepository::getStatistics error: ' . $e->getMessage());
            return [
                'total_imports' => 0,
                'total_created' => 0,
                'total_updated' => 0,
                'total_skipped' => 0,
                'total_errors'  => 0,
            ];
        }
    }

    /**
     * Ensure the import_history table exists, create it if not.
     */
    private function ensureTableExists(): void
    {
        try {
            $pdo = Bootstrap::pdo();
            $stmt = $pdo->query("SELECT 1 FROM import_history LIMIT 1");
        } catch (\Throwable $e) {
            error_log('ImportHistoryRepository: Table does not exist, creating... ' . $e->getMessage());
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
                producer VARCHAR(255) NOT NULL,
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

        try {
            Bootstrap::pdo()->exec($sql);
            error_log('ImportHistoryRepository: Table created successfully');
        } catch (\Throwable $e) {
            error_log('ImportHistoryRepository: Failed to create table: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Extract username from email (remove domain part).
     */
    private function extractUsername(string $email): string
    {
        $atPos = strpos($email, '@');
        return $atPos !== false ? substr($email, 0, $atPos) : $email;
    }
}

