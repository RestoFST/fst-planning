<?php

use App\Core\LogManager;
use App\Core\Router;
use Twig\Environment;

use function DI\autowire;
use function DI\env;

return [
    Router::class => autowire(),

    LogManager::class => autowire(),

    LoggerInterface::class => function (LogManager $logManager) {
        return $logManager->channel('app');
    },

    Environment::class => function () {
        $loader = new \Twig\Loader\FilesystemLoader(__DIR__ . '/views');
        return new Environment($loader, [
            'cache' => env('APP_ENV') === 'production' ? __DIR__ . '/cache/twig' : false,
            'debug' => env('APP_ENV', 'production') === 'development'
        ]);
    },

    'app.env' => env('APP_ENV', 'production'),
    'app.debug' => env('APP_ENV', 'production') === 'development',
    'db.config' => [
        'host' => env('DB_HOST', 'localhost'),
        'name' => env('DB_NAME', 'myapp'),
        'user' => env('DB_USER', 'root'),
        'pass' => env('DB_PASS', '')
    ]
];