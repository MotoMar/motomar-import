<?php

declare(strict_types=1);

namespace App;

use App\Controller\ExecuteController;
use App\Controller\HistoryController;
use App\Controller\LoginController;
use App\Controller\MappingController;
use App\Controller\SeasonsController;
use App\Controller\UploadController;

final class Router
{
    private const ROUTES = [
        'GET' => [
            '/'        => [UploadController::class, 'show'],
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
        $method = $_SERVER['REQUEST_METHOD'];
        $uri    = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
        $base   = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
        $path   = $base !== '' ? substr($uri, strlen($base)) : $uri;
        $path   = $path === '' ? '/' : $path;

        $route = self::ROUTES[$method][$path] ?? null;

        if ($route === null) {
            http_response_code(404);
            echo 'Not found';
            return;
        }

        if (!in_array("{$method} {$path}", self::PUBLIC_ROUTES, true) && !Auth::check()) {
            $base = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
            header('Location: ' . $base . '/login');
            exit;
        }

        [$class, $action] = $route;
        (new $class())->$action();
    }
}
