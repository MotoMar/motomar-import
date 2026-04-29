<?php

declare(strict_types=1);

namespace App\Domain\Import;

final class ImportSession
{
    private const KEY_UUID = 'ti_uuid';
    private const KEY_STEP = 'ti_step';

    public function __construct(private readonly string $storageDir) {}

    /** Allowed data keys to prevent path traversal via key parameter. */
    private const ALLOWED_KEYS = ['models', 'mapping', 'result'];

    public function start(): string
    {
        $uuid = self::uuid4();
        $dir  = $this->importDir($uuid);

        if (!mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new \RuntimeException("Cannot create import directory: {$dir}");
        }

        $_SESSION[self::KEY_UUID] = $uuid;
        $_SESSION[self::KEY_STEP] = 1;

        return $uuid;
    }

    public function uuid(): ?string
    {
        return $_SESSION[self::KEY_UUID] ?? null;
    }

    public function step(): int
    {
        return (int) ($_SESSION[self::KEY_STEP] ?? 1);
    }

    public function setStep(int $step): void
    {
        $_SESSION[self::KEY_STEP] = $step;
    }

    public function reset(): void
    {
        $uuid = $this->uuid();

        error_log(sprintf('ImportSession::reset - uuid: %s', $uuid ?? '(none)'));

        if ($uuid !== null && is_dir($this->importDir($uuid))) {
            try {
                $dir = $this->importDir($uuid);
                error_log(sprintf('ImportSession::reset - deleting directory: %s', $dir));
                $this->deleteDirectory($dir);
                error_log('ImportSession::reset - directory deleted successfully');
            } catch (\Throwable $e) {
                error_log(sprintf(
                    'ImportSession::reset - failed to delete directory: %s (%s)',
                    $e->getMessage(),
                    $e->getFile() . ':' . $e->getLine()
                ));
                // Don't throw - continue with session cleanup
            }
        }

        unset($_SESSION[self::KEY_UUID], $_SESSION[self::KEY_STEP]);
        error_log('ImportSession::reset - session vars unset');
    }

    public function csvPath(string $uuid): string
    {
        return $this->importDir($uuid) . '/original.csv';
    }

    public function write(string $uuid, string $key, mixed $data): void
    {
        if (!in_array($key, self::ALLOWED_KEYS, true)) {
            throw new \InvalidArgumentException("Disallowed import data key: {$key}");
        }

        $path = $this->importDir($uuid) . "/{$key}.json";
        $json = json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        $result = file_put_contents($path, $json, LOCK_EX);

        error_log(sprintf(
            'ImportSession::write - uuid: %s, key: %s, path: %s, bytes: %d, success: %s',
            $uuid,
            $key,
            $path,
            $result !== false ? $result : 0,
            $result !== false ? 'yes' : 'no'
        ));
    }

    public function read(string $uuid, string $key): mixed
    {
        if (!in_array($key, self::ALLOWED_KEYS, true)) {
            throw new \InvalidArgumentException("Disallowed import data key: {$key}");
        }

        $path = $this->importDir($uuid) . "/{$key}.json";

        error_log(sprintf(
            'ImportSession::read - uuid: %s, key: %s, path: %s, exists: %s',
            $uuid,
            $key,
            $path,
            is_file($path) ? 'yes' : 'no'
        ));

        if (!is_file($path)) {
            return null;
        }

        $content = file_get_contents($path);
        $data = json_decode($content, true, 512, JSON_THROW_ON_ERROR);

        error_log(sprintf(
            'ImportSession::read - decoded items: %d',
            is_array($data) ? count($data) : 0
        ));

        return $data;
    }

    private static function uuid4(): string
    {
        $b = random_bytes(16);
        $b[6] = chr(ord($b[6]) & 0x0f | 0x40);
        $b[8] = chr(ord($b[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
    }

    private function importDir(string $uuid): string
    {
        return $this->storageDir . '/imports/' . $uuid;
    }

    private function deleteDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            error_log("ImportSession::deleteDirectory - directory does not exist: $dir");
            return;
        }

        $entries = @scandir($dir);
        if ($entries === false) {
            error_log("ImportSession::deleteDirectory - scandir failed for: $dir");
            throw new \RuntimeException("Cannot scan directory: $dir");
        }

        foreach (array_diff($entries, ['.', '..']) as $entry) {
            $path = $dir . '/' . $entry;

            if (is_dir($path)) {
                $this->deleteDirectory($path);
            } else {
                if (!@unlink($path)) {
                    error_log("ImportSession::deleteDirectory - failed to delete file: $path");
                    throw new \RuntimeException("Cannot delete file: $path");
                }
            }
        }

        if (!@rmdir($dir)) {
            error_log("ImportSession::deleteDirectory - failed to remove directory: $dir");
            throw new \RuntimeException("Cannot remove directory: $dir");
        }

        error_log("ImportSession::deleteDirectory - deleted: $dir");
    }
}
