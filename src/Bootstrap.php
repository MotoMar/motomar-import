<?php

declare(strict_types=1);

namespace App;

use Medoo\Medoo;
use PDO;

final class Bootstrap
{
    private static Medoo  $db;
    private static Logger $logger;

    /** @var array<string, mixed> */
    private static array  $config;

    public static function init(): void
    {
        $root = dirname(__DIR__);

        self::loadEnv($root . '/.env');

        foreach (['DB_HOST', 'DB_NAME', 'DB_USER', 'DB_PASS'] as $key) {
            if (empty($_ENV[$key])) {
                throw new \RuntimeException("Missing required environment variable: {$key}");
            }
        }

        /** @var array<string, mixed> $config */
        $config = require $root . '/config/app.php';
        self::$config = $config;

        $logDir = $root . '/storage/logs';
        if (!is_dir($logDir) && !mkdir($logDir, 0755, true)) {
            throw new \RuntimeException("Cannot create log directory: {$logDir}");
        }

        self::$logger = new Logger($logDir);

        self::$db = new Medoo([
            'type'      => 'mysql',
            'host'      => $_ENV['DB_HOST'],
            'database'  => $_ENV['DB_NAME'],
            'username'  => $_ENV['DB_USER'],
            'password'  => $_ENV['DB_PASS'],
            'charset'   => $_ENV['DB_CHARSET'] ?? 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'option'    => [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                // PDO::MYSQL_ATTR_INIT_COMMAND is deprecated in 8.5 and its
                // notice lands in the response body, above the page.
                \Pdo\Mysql::ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci",
            ],
        ]);

        if (session_status() === PHP_SESSION_NONE) {
            ini_set('session.use_strict_mode', '1');
            ini_set('session.cookie_httponly', '1');
            ini_set('session.cookie_samesite', 'Lax');
            session_start();
        }
    }

    public static function db(): Medoo
    {
        return self::$db;
    }

    public static function pdo(): PDO
    {
        // Medoo stores PDO in private $pdo property
        $reflection = new \ReflectionClass(self::$db);
        $property = $reflection->getProperty('pdo');
        $property->setAccessible(true);

        $pdo = $property->getValue(self::$db);

        if (!$pdo instanceof PDO) {
            throw new \RuntimeException('Medoo nie trzyma połączenia PDO tam, gdzie się spodziewamy.');
        }

        return $pdo;
    }

    public static function logger(): Logger
    {
        return self::$logger;
    }

    /** @return array<string, mixed> */
    public static function config(): array
    {
        return self::$config;
    }

    /**
     * Column layout of the supplier CSV, in file order.
     *
     * @return string[]
     */
    public static function csvColumns(): array
    {
        return self::stringList('csv_columns');
    }

    /**
     * Maps the one-letter vehicle type from the price list to our type id.
     *
     * A shortcut that is not in here resolves to 0 at the call site, and type 0
     * has no classification order — see the note in ImportProcessor.
     *
     * @return array<string, int>
     */
    public static function vehicleTypeShortcuts(): array
    {
        $shortcuts = self::$config['vehicle_type_shortcuts'] ?? null;

        if (!is_array($shortcuts)) {
            throw new \RuntimeException('config/app.php nie zawiera `vehicle_type_shortcuts`.');
        }

        $typed = [];

        foreach ($shortcuts as $shortcut => $vehicleTypeId) {
            if (is_string($shortcut) && is_numeric($vehicleTypeId)) {
                $typed[$shortcut] = (int) $vehicleTypeId;
            }
        }

        return $typed;
    }

    public static function tireCategoryId(): int
    {
        $id = self::$config['tire_category_id'] ?? null;

        if (!is_numeric($id)) {
            throw new \RuntimeException('config/app.php nie zawiera `tire_category_id`.');
        }

        return (int) $id;
    }

    public static function uploadMaxSizeMb(): int
    {
        $size = self::$config['upload_max_size_mb'] ?? null;

        return is_numeric($size) ? (int) $size : 10;
    }

    /**
     * @return string[]
     */
    private static function stringList(string $key): array
    {
        $values = self::$config[$key] ?? null;

        if (!is_array($values)) {
            throw new \RuntimeException("config/app.php nie zawiera `{$key}`.");
        }

        $strings = [];

        foreach ($values as $value) {
            if (is_string($value)) {
                $strings[] = $value;
            }
        }

        return $strings;
    }

    public static function tireRepository(): \App\Domain\Tire\TireRepository
    {
        return new \App\Domain\Tire\TireRepository();
    }

    private static function loadEnv(string $path): void
    {
        if (!is_file($path)) {
            return;
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        if ($lines === false) {
            return;
        }

        foreach ($lines as $line) {
            $line = trim($line);

            if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
                continue;
            }

            [$key, $val] = explode('=', $line, 2);
            $key = trim($key);
            $val = trim($val);

            // Strip inline comment only when value is not quoted
            if ($val !== '' && $val[0] !== '"' && $val[0] !== "'") {
                $val = (string) preg_replace('/\s+#.*$/', '', $val);
            }

            // Strip surrounding quotes
            if (
                strlen($val) >= 2
                && (($val[0] === '"' && $val[-1] === '"') || ($val[0] === "'" && $val[-1] === "'"))
            ) {
                $val = substr($val, 1, -1);
            }

            $_ENV[$key]    = $val;
            $_SERVER[$key] = $val;
        }
    }
}
