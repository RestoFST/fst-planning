<?php

namespace App\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use App\Core\Router;
use GuzzleHttp\Psr7\Response;

class MaintenanceMiddleware implements MiddlewareInterface
{
    public function __construct(
        private Router $router
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $maintenanceVal = getenv('APP_MAINTENANCE');
        if ($maintenanceVal === false) {
            $maintenanceVal = $_ENV['APP_MAINTENANCE'] ?? '';
        }
        $isMaintenance = in_array(strtolower((string)$maintenanceVal), ['true', '1', 'yes', 'on']);

        $secret = getenv('APP_MAINTENANCE_SECRET');
        if ($secret === false) {
            $secret = $_ENV['APP_MAINTENANCE_SECRET'] ?? '';
        }

        // Si la maintenance n'est pas active, supprimer le cookie de contournement s'il est présent
        if (!$isMaintenance) {
            $cookies = $request->getCookieParams();
            if (isset($cookies['maintenance_bypass'])) {
                header('Set-Cookie: maintenance_bypass=; Path=/; Max-Age=0; HttpOnly; SameSite=Lax');
            }
        }

        // Récupérer les paramètres de requête
        $queryParams = $request->getQueryParams();
        $bypassParam = $queryParams['bypass'] ?? '';

        // Si le secret est fourni dans l'URL (?bypass=...), on pose le cookie et on redirige
        if (!empty($secret) && $bypassParam === $secret) {
            header('Set-Cookie: maintenance_bypass=' . urlencode($secret) . '; Path=/; Max-Age=2592000; HttpOnly; SameSite=Lax');
            return new Response(302, ['Location' => $this->router->generate('index')]);
        }

        if ($isMaintenance) {
            // Vérifier si le cookie de bypass est présent et correct
            $cookies = $request->getCookieParams();
            $bypassCookie = $cookies['maintenance_bypass'] ?? '';

            if (empty($secret) || $bypassCookie !== $secret) {
                // Laisser passer la route d'action de bypass pour les administrateurs
                $path = $request->getUri()->getPath();
                $bypassPath = $this->router->generate('admin.settings.maintenance_bypass');
                if ($path === $bypassPath) {
                    return $handler->handle($request);
                }

                // Rendre une belle page de maintenance (503 Service Unavailable)
                $loader = new \Twig\Loader\FilesystemLoader(dirname(__DIR__, 2) . '/views');
                $twig = new \Twig\Environment($loader, ['cache' => false]);
                
                try {
                    $html = $twig->render('maintenance.twig', [
                        'message' => 'Le site est actuellement en cours de maintenance programmée. Nous serons de retour très bientôt.'
                    ]);
                } catch (\Exception $e) {
                    $html = '<h1>Maintenance en cours</h1><p>Le site est actuellement en maintenance programmée. Veuillez repasser plus tard.</p>';
                }

                return new Response(503, ['Content-Type' => 'text/html; charset=utf-8'], $html);
            }
        }

        return $handler->handle($request);
    }
}
