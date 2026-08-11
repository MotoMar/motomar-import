<?php

declare(strict_types=1);

namespace App;

use App\Controller\ExecuteController;
use App\Controller\HistoryController;
use App\Controller\LoginController;
use App\Controller\MappingController;
use App\Controller\ProducersController;
use App\Controller\SeasonsController;
use App\Controller\UploadController;

final class Router
{
    private const ROUTES = [
        'GET' => [
            '/'        => [UploadController::class, 'show'],
            '/producers' => [ProducersController::class, 'show'],
            '/mapping' => [MappingController::class, 'show'],
            '/seasons' => [SeasonsController::class, 'show'],
            '/execute' => [ExecuteController::class, 'show'],
            '/result'  => [ExecuteController::class, 'showResult'],
            '/history' => [HistoryController::class, 'show'],
            '/history-detail' => [HistoryController::class, 'detail'],
            '/reset'   => [UploadController::class, 'reset'],
            '/login'   => [LoginController::class, 'show'],
            '/logout'  => [LoginController::class, 'logout'],
        ],
        'POST' => [
            '/upload'  => [UploadController::class, 'handle'],
            '/producers' => [ProducersController::class, 'save'],
            '/mapping' => [MappingController::class, 'handle'],
            '/seasons' => [SeasonsController::class, 'handle'],
            '/execute' => [ExecuteController::class, 'execute'],
            '/login'   => [LoginController::class, 'handle'],
        ],
    ];

    /** Routes accessible without authentication */
    private const PUBLIC_ROUTES = [
        'GET /login',
        'POST /login',
    ];

    public function dispatch(): void
    {
        $method = self::server('REQUEST_METHOD');
        $uri    = parse_url(self::server('REQUEST_URI'), PHP_URL_PATH);
        $uri    = is_string($uri) ? $uri : '/';
        $base   = rtrim(dirname(self::server('SCRIPT_NAME')), '/');
        $path   = $base !== '' ? substr($uri, strlen($base)) : $uri;
        $path   = $path === '' ? '/' : $path;

        $route = self::ROUTES[$method][$path] ?? null;

        if ($route === null) {
            http_response_code(404);
            echo 'Not found';
            return;
        }

        if (!in_array("{$method} {$path}", self::PUBLIC_ROUTES, true) && !Auth::check()) {
            header('Location: ' . $base . '/login');
            exit;
        }

        [$class, $action] = $route;
        $handler = [new $class(), $action];

        // The routing table names the method as a string, so nothing but this
        // check ties it to a method that exists. A typo here used to be a fatal
        // error on a live request; now it is a 500 with a name in it.
        if (!is_callable($handler)) {
            http_response_code(500);
            echo "Handler {$class}::{$action} does not exist";

            return;
        }

        $handler();
    }

    /**
     * Reads a server variable that PHP types as mixed.
     */
    private static function server(string $key): string
    {
        $value = $_SERVER[$key] ?? '';

        return is_string($value) ? $value : '';
    }
}
