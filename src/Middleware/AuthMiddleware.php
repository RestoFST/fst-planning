<?php

namespace App\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use GuzzleHttp\Psr7\Response;
use Attribute;

#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_CLASS)]
class AuthMiddleware implements MiddlewareInterface
{
    public function __construct(private ?string $role = null)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (empty($_SESSION['user'])) {
            // Pas connecté, redirection vers /login via le routeur
            $router = \App\Core\Router::getInstance();
            $loginUrl = $router ? $router->generate('auth.login_form') : '/login';
            return new Response(302, ['Location' => $loginUrl]);
        }

        // Vérifier si le mot de passe a été modifié ailleurs
        $container = \App\Core\ContainerFactory::getContainer();
        if ($container) {
            $pdo = $container->get(\App\Core\DB::class)->getConnection();
            $stmt = $pdo->prepare("SELECT lastModifiedPassword FROM users WHERE id = :id");
            $stmt->execute(['id' => $_SESSION['user']['id']]);
            $dbLastMod = $stmt->fetchColumn();

            if ($dbLastMod !== ($_SESSION['user']['last_password_modified'] ?? null)) {
                // Le mot de passe a été modifié ! Invalider la session
                unset($_SESSION['user']);
                if (session_status() !== PHP_SESSION_NONE) {
                    session_destroy();
                }

                // Invalider le cookie remember_me
                setcookie('remember_me', '', [
                    'expires' => time() - 3600,
                    'path' => '/',
                    'httponly' => true,
                    'samesite' => 'Lax'
                ]);

                $router = \App\Core\Router::getInstance();
                $loginUrl = $router ? $router->generate('auth.login_form') : '/login';
                return new Response(302, ['Location' => $loginUrl]);
            }
        }

        // Si l'utilisateur est connecté et que son mot de passe n'a pas été changé (last_password_modified est NULL)
        if ($_SESSION['user']['last_password_modified'] === null) {
            $router = \App\Core\Router::getInstance();
            $route = $router ? $router->match() : null;
            $routeName = $route ? $route['name'] : '';

            // Autoriser uniquement les routes de changement forcé de mot de passe et de déconnexion
            if ($routeName !== 'auth.force_change_password' && 
                $routeName !== 'auth.force_change_password_submit' && 
                $routeName !== 'auth.logout') {
                
                $forceUrl = $router ? $router->generate('auth.force_change_password') : '/force-change-password';
                return new Response(302, ['Location' => $forceUrl]);
            }
        }

        $user = $_SESSION['user'];
        $role = $user['role'] ?? 'user';

        if ($this->role !== null) {
            $allowedRoles = array_map('trim', explode(',', $this->role));
            $authorized = false;
            foreach ($allowedRoles as $allowedRole) {
                if ($role === $allowedRole) {
                    $authorized = true;
                    break;
                }
            }
            if ($role === 'admin') {
                $authorized = true;
            }

            if (!$authorized) {
                // Non autorisé
                return new Response(403, [], '403 Forbidden - Accès refusé');
            }
        }

        return $handler->handle($request);
    }
}
