<?php

declare(strict_types=1);

namespace App;

use Medoo\Medoo;
use PDO;

final class Bootstrap
{
    private static Medoo  $db;
    private static Logger $logger;
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

        self::$config = require $root . '/config/app.php';

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
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci",
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

        return $property->getValue(self::$db);
    }

    public static function logger(): Logger
    {
        return self::$logger;
    }

    public static function config(): array
    {
        return self::$config;
    }

    private static function loadEnv(string $path): void
    {
        if (!is_file($path)) {
            return;
        }

        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
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
