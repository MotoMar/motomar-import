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

        if ($uuid !== null && is_dir($this->importDir($uuid))) {
            $this->deleteDirectory($this->importDir($uuid));
        }

        unset($_SESSION[self::KEY_UUID], $_SESSION[self::KEY_STEP]);
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
        file_put_contents($path, $json, LOCK_EX);
    }

    public function read(string $uuid, string $key): mixed
    {
        if (!in_array($key, self::ALLOWED_KEYS, true)) {
            throw new \InvalidArgumentException("Disallowed import data key: {$key}");
        }

        $path = $this->importDir($uuid) . "/{$key}.json";

        if (!is_file($path)) {
            return null;
        }

        return json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
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
        foreach (array_diff(scandir($dir), ['.', '..']) as $entry) {
            $path = $dir . '/' . $entry;
            is_dir($path) ? $this->deleteDirectory($path) : unlink($path);
        }
        rmdir($dir);
    }
}
