<?php

require_once __DIR__ . '/vendor/autoload.php';

if (session_status() === PHP_SESSION_NONE) {
    session_name('planning_benevoles_session');
    session_start();
}

use App\Core\Router;
use App\Controllers\HomeController;
use App\Controllers\AuthController;
use App\Controllers\AdminController;
use App\Controllers\AdminDashboardController;
use App\Controllers\CalendarController;
use App\Core\ContainerFactory;
use GuzzleHttp\Psr7\ServerRequest;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Relay\Relay;

$container = ContainerFactory::build();

// Reconnexion automatique "Se souvenir de moi"
if (empty($_SESSION['user']) && !empty($_COOKIE['remember_me'])) {
    $container->get(AuthController::class)->autoLoginWithCookie();
}

$router = $container->get(Router::class);
$router->registerController($container->get(HomeController::class));
$router->registerController($container->get(AuthController::class));
$router->registerController($container->get(AdminController::class));
$router->registerController($container->get(AdminDashboardController::class));
$router->registerController($container->get(CalendarController::class));

$match = $router->match();

//var_dump($_SERVER['REQUEST_URI']);

if ($match) {
    try {
        $request = ServerRequest::fromGlobals();
        $queue = [];
        $queue[] = $container->get(\App\Middleware\MaintenanceMiddleware::class);

        $reflexClass = new ReflectionClass($match['target'][0]);
        $middlewares = $reflexClass->getAttributes(MiddlewareInterface::class,ReflectionAttribute::IS_INSTANCEOF);
        foreach ($middlewares as $middleware) {
            $queue[] = $middleware->newInstance();
        }
        $reflexMethod = new ReflectionMethod($match['target'][0], $match['target'][1]);
        $middlewares = $reflexMethod->getAttributes(MiddlewareInterface::class,ReflectionAttribute::IS_INSTANCEOF);
        foreach ($middlewares as $middleware) {
            $queue[] = $middleware->newInstance();
        }

        $relay = new Relay(array_merge($queue, [
            new class($match) implements RequestHandlerInterface {
                public function __construct(private array $match) {}

                public function handle(ServerRequestInterface $request): ResponseInterface {
                    return call_user_func_array($this->match['target'], array_merge([$request], $this->match['params']));
                }
            }
        ]));
        $response = $relay->handle($request);

        if ($response instanceof ResponseInterface) {
            // Send the response to the client
            if (!headers_sent()) {
                header(sprintf('HTTP/%s %s %s', $response->getProtocolVersion(), $response->getStatusCode(), $response->getReasonPhrase()));
                foreach ($response->getHeaders() as $name => $values) {
                    foreach ($values as $value) {
                        header(sprintf('%s: %s', $name, $value), false);
                    }
                }
            }
            echo $response->getBody();
        } else {
            throw new Exception('Controller action did not return a valid response');
        }
    } catch (Exception $e) {
        echo 'Error: ' . $e->getMessage();
    }
} else {
    echo '404 Not Found';
}
