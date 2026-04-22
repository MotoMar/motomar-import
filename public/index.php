<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';

use App\Bootstrap;
use App\Router;

Bootstrap::init();

$router = new Router();
$router->dispatch();
