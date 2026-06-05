<?php

use App\Core\LogManager;
use App\Core\Router;
use Twig\Environment;
use Psr\Log\LoggerInterface;

use function DI\autowire;
use function DI\env;

return [
    Router::class => autowire(),

    LogManager::class => autowire(),

    LoggerInterface::class => function (LogManager $logManager) {
        return $logManager->channel('app');
    },

    Environment::class => function (\Psr\Container\ContainerInterface $c) {
        $loader = new \Twig\Loader\FilesystemLoader(__DIR__ . '/views');
        $twig = new Environment($loader, [
            'cache' => env('APP_ENV') === 'production' ? __DIR__ . '/cache/twig' : false,
            'debug' => env('APP_ENV', 'production') === 'development'
        ]);
        if (session_status() !== PHP_SESSION_NONE && !empty($_SESSION['user'])) {
            $twig->addGlobal('app_user', $_SESSION['user']);
        }

        // Récupérer les paramètres de la bannière globale d'information / maintenance
        try {
            $pdo = $c->get(\App\Core\DB::class)->getConnection();
            $stmt = $pdo->query("SELECT name, value FROM settings WHERE name IN ('banner_message', 'banner_type', 'banner_active')");
            $settings = $stmt->fetchAll(\PDO::FETCH_KEY_PAIR) ?: [];
            $banner = [
                'active' => ($settings['banner_active'] ?? '0') === '1',
                'message' => $settings['banner_message'] ?? '',
                'type' => $settings['banner_type'] ?? 'info'
            ];
            $twig->addGlobal('app_banner', $banner);
        } catch (\Exception $e) {
            $twig->addGlobal('app_banner', ['active' => false, 'message' => '', 'type' => 'info']);
        }
        $twig->addFilter(new \Twig\TwigFilter('json_decode', function ($string) {
            return json_decode($string ?? '[]', true) ?: [];
        }));
        $twig->addFunction(new \Twig\TwigFunction('path', function (string $name, array $params = []) use ($c) {
            return $c->get(Router::class)->generate($name, $params);
        }));
        return $twig;
    },

    'app.env' => env('APP_ENV', 'production'),
    'app.debug' => env('APP_ENV', 'production') === 'development',
    'contact.mail' => env('CONTACT_MAIL', ''),
    'db.config' => [
        'host' => env('DB_HOST', 'localhost'),
        'name' => env('DB_NAME', 'myapp'),
        'user' => env('DB_USER', 'root'),
        'pass' => env('DB_PASS', ''),
        'prefix' => env('DB_PREFIX', '')
    ]
];