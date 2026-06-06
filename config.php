<?php

use App\Core\Router;

use function DI\autowire;
use function DI\env;

return [
    Router::class => autowire(),

    'app.env' => env('APP_ENV', 'production'),
    'db.config' => [
        'host' => env('DB_HOST', 'localhost'),
        'name' => env('DB_NAME', 'myapp'),
        'user' => env('DB_USER', 'root'),
        'pass' => env('DB_PASS', '')
    ]
];