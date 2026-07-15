<?php

namespace App\Controllers;

use App\Attribute\RouteAttribute;
use App\Attribute\RenderAttribute;
use App\Middleware\AuthMiddleware;
use App\Core\Logger;
use App\Core\TwigRenderer;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\ServerRequestInterface;

#[RenderAttribute(TwigRenderer::class)]
class AdminDashboardController extends BaseController
{
    #[AuthMiddleware('admin')]
    #[RouteAttribute(method: "GET", path: "/admin/dashboard", name: "admin.dashboard")]
    public function index(): Response
    {
        $pdo = $this->database->getConnection();

        $stmt = $pdo->prepare("SELECT value FROM settings WHERE name = 'rss_token'");
        $stmt->execute();
        $rssToken = $stmt->fetch(\PDO::FETCH_COLUMN) ?: 'init_token_abc123';

        $stmtDays = $pdo->prepare("SELECT value FROM settings WHERE name = 'home_days_count'");
        $stmtDays->execute();
        $homeDaysCount = $stmtDays->fetchColumn() ?: '7';

        $stmtBanner = $pdo->prepare("SELECT name, value FROM settings WHERE name IN ('banner_message', 'banner_type', 'banner_active')");
        $stmtBanner->execute();
        $bannerSettings = $stmtBanner->fetchAll(\PDO::FETCH_KEY_PAIR) ?: [];
        $bannerMessage = $bannerSettings['banner_message'] ?? '';
        $bannerType = $bannerSettings['banner_type'] ?? 'info';
        $bannerActive = ($bannerSettings['banner_active'] ?? '0') === '1';

        // Assurer la rétrocompatibilité des sessions actives
        if (isset($_SESSION['user']) && !isset($_SESSION['user']['rss_token'])) {
            $_SESSION['user']['rss_token'] = $rssToken;
        }

        // 2. Récupérer tous les utilisateurs pour l'export RGPD
        $stmtUsers = $pdo->query("SELECT id, firstname, name, username, role FROM users ORDER BY name ASC, firstname ASC");
        $users = $stmtUsers->fetchAll(\PDO::FETCH_ASSOC);

        // 3. Lire les logs de l'application
        $logFile = dirname(__DIR__, 2) . '/logs/app.log';
        $logsContent = '';
        if (file_exists($logFile)) {
            // Récupérer les 300 dernières lignes de logs pour optimiser
            $lines = file($logFile);
            if ($lines !== false) {
                $lastLines = array_slice($lines, -300);
                $logsContent = implode('', $lastLines);
            }
        } else {
            $logsContent = "Aucune entrée de log disponible.";
        }

        $maintenanceActiveVal = getenv('APP_MAINTENANCE');
        if ($maintenanceActiveVal === false) {
            $maintenanceActiveVal = $_ENV['APP_MAINTENANCE'] ?? '';
        }
        $maintenanceActive = in_array(strtolower((string)$maintenanceActiveVal), ['true', '1', 'yes', 'on']);

        $maintenanceSecret = getenv('APP_MAINTENANCE_SECRET');
        if ($maintenanceSecret === false) {
            $maintenanceSecret = $_ENV['APP_MAINTENANCE_SECRET'] ?? '';
        }

        $success = $_SESSION['admin_success'] ?? null;
        $error = $_SESSION['admin_error'] ?? null;
        unset($_SESSION['admin_success'], $_SESSION['admin_error']);

        return new Response(body: $this->render('admin/dashboard', [
            'rssToken' => $rssToken,
            'homeDaysCount' => $homeDaysCount,
            'bannerMessage' => $bannerMessage,
            'bannerType' => $bannerType,
            'bannerActive' => $bannerActive,
            'maintenanceActive' => $maintenanceActive,
            'maintenanceSecret' => $maintenanceSecret,
            'users' => $users,
            'logsContent' => $logsContent,
            'success' => $success,
            'error' => $error
        ]));
    }

    #[AuthMiddleware('admin')]
    #[RouteAttribute(method: "POST", path: "/admin/rss/reset", name: "admin.rss.reset")]
    public function resetRssToken(): Response
    {
        $pdo = $this->database->getConnection();
        try {
            $newToken = bin2hex(random_bytes(16));
            $stmt = $pdo->prepare("UPDATE settings SET value = :value WHERE name = 'rss_token'");
            $stmt->execute(['value' => $newToken]);

            // Mettre à jour en session également
            if (isset($_SESSION['user'])) {
                $_SESSION['user']['rss_token'] = $newToken;
            }
            
            Logger::info("Réinitialisation du token d'accès RSS", ['admin_uid' => $_SESSION['user']['id']]);
            $_SESSION['admin_success'] = "Le token d'accès RSS a bien été réinitialisé.";
        } catch (\Exception $e) {
            $_SESSION['admin_error'] = "Erreur lors de la réinitialisation : " . $e->getMessage();
        }

        return $this->redirect('admin.logs');
    }

    #[AuthMiddleware('admin')]
    #[RouteAttribute(method: "GET", path: "/admin/logs", name: "admin.logs")]
    public function logsList(): Response
    {
        $pdo = $this->database->getConnection();

        $stmt = $pdo->prepare("SELECT value FROM settings WHERE name = 'rss_token'");
        $stmt->execute();
        $rssToken = $stmt->fetch(\PDO::FETCH_COLUMN) ?: 'init_token_abc123';

        // Lire les logs de l'application
        $logFile = dirname(__DIR__, 2) . '/logs/app.log';
        $logsContent = '';
        if (file_exists($logFile)) {
            // Récupérer les 300 dernières lignes de logs pour optimiser
            $lines = file($logFile);
            if ($lines !== false) {
                $lastLines = array_slice($lines, -300);
                $logsContent = implode('', $lastLines);
            }
        } else {
            $logsContent = "Aucune entrée de log disponible.";
        }

        $success = $_SESSION['admin_success'] ?? null;
        $error = $_SESSION['admin_error'] ?? null;
        unset($_SESSION['admin_success'], $_SESSION['admin_error']);

        return new Response(body: $this->render('admin/logs', [
            'rssToken' => $rssToken,
            'logsContent' => $logsContent,
            'success' => $success,
            'error' => $error
        ]));
    }

    #[AuthMiddleware('admin')]
    #[RouteAttribute(method: "GET", path: "/admin/rgpd/export", name: "admin.rgpd.export")]
    public function exportRgpd(ServerRequestInterface $request): Response
    {
        $queryParams = $request->getQueryParams();
        $uid = (int)($queryParams['uid'] ?? 0);

        if (!$uid) {
            $_SESSION['admin_error'] = "Utilisateur invalide pour l'export RGPD.";
            return $this->redirect('admin.dashboard');
        }

        $pdo = $this->database->getConnection();

        // 1. Charger le profil de l'utilisateur
        $stmtUser = $pdo->prepare("SELECT id, firstname, name, username, role, lastModifiedPassword FROM users WHERE id = :id");
        $stmtUser->execute(['id' => $uid]);
        $user = $stmtUser->fetch(\PDO::FETCH_ASSOC);

        if (!$user) {
            $_SESSION['admin_error'] = "Utilisateur introuvable.";
            return $this->redirect('admin.dashboard');
        }

        // Utiliser le rôle
        $user['role'] = $user['role'] ?? 'user';

        // Charger les groupes de l'utilisateur
        $stmtGroups = $pdo->prepare("
            SELECT g.id, g.name, g.description 
            FROM `groups` g
            JOIN users_groups ug ON ug.gid = g.id
            WHERE ug.uid = :uid
        ");
        $stmtGroups->execute(['uid' => $uid]);
        $user['groups'] = $stmtGroups->fetchAll(\PDO::FETCH_ASSOC);

        // 2. Charger l'historique complet des inscriptions/pointages
        $stmtAppointments = $pdo->prepare("
            SELECT a.date, s.name as service_name, sw.hours as service_hours, au.presence 
            FROM appointments_users au
            JOIN appointment a ON a.id = au.aid
            JOIN services s ON s.id = a.sid
            LEFT JOIN services_workdays sw ON sw.sid = s.id AND sw.workday = STR_TO_DATE(a.date, '%Y-%m-%d')
            WHERE au.uid = :uid
            ORDER BY a.date DESC
        ");
        $stmtAppointments->execute(['uid' => $uid]);
        $appointments = $stmtAppointments->fetchAll(\PDO::FETCH_ASSOC);

        $exportData = [
            'metadata' => [
                'export_type' => 'RGPD Personal Data Portability Export',
                'exported_at' => (new \DateTime())->format('Y-m-d H:i:s'),
                'organization' => 'Planning Bénévoles'
            ],
            'user_profile' => $user,
            'activity_registrations' => $appointments
        ];

        Logger::info("Export de données RGPD effectué", ['admin_uid' => $_SESSION['user']['id'], 'target_uid' => $uid]);

        $jsonContent = json_encode($exportData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        
        return new Response(200, [
            'Content-Type' => 'application/json; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="export_rgpd_user_' . $uid . '.json"',
            'Pragma' => 'no-cache',
            'Expires' => '0'
        ], $jsonContent);
    }

    #[RouteAttribute(method: "GET", path: "/admin/logs/rss", name: "admin.logs.rss")]
    public function logsRss(ServerRequestInterface $request): Response
    {
        $queryParams = $request->getQueryParams();
        $token = $queryParams['token'] ?? '';

        $pdo = $this->database->getConnection();
        
        // Récupérer le token RSS configuré
        $stmt = $pdo->prepare("SELECT value FROM settings WHERE name = 'rss_token'");
        $stmt->execute();
        $configuredToken = $stmt->fetch(\PDO::FETCH_COLUMN);

        if (!$configuredToken || $token !== $configuredToken) {
            Logger::warning("Accès non autorisé au flux RSS des logs", ['ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown', 'provided_token' => $token]);
            return new Response(403, ['Content-Type' => 'text/plain'], "403 Forbidden - Token invalide");
        }

        $logFile = dirname(__DIR__, 2) . '/logs/app.log';
        $rssItems = [];

        if (file_exists($logFile)) {
            $lines = file($logFile);
            if ($lines !== false) {
                // Parcourir à l'envers pour afficher les derniers logs en premier dans le flux RSS
                $reversedLines = array_reverse($lines);
                foreach ($reversedLines as $line) {
                    // Filtrer uniquement les logs WARNING, ERROR et CRITICAL
                    if (preg_match('/\[(WARNING|ERROR|CRITICAL)\]/', $line, $matches)) {
                        $level = $matches[1];
                        
                        // Parser la ligne: [DATE] [LEVEL] MESSAGE CONTEXT
                        if (preg_match('/^\[(.*?)\] \[(.*?)\] (.*)$/', trim($line), $parts)) {
                            $dateStr = $parts[1];
                            $message = $parts[3];
                            
                            $pubDate = (new \DateTime($dateStr))->format(\DateTime::RSS);
                            
                            $rssItems[] = [
                                'title' => "[$level] " . substr($message, 0, 50) . (strlen($message) > 50 ? '...' : ''),
                                'description' => htmlspecialchars($message),
                                'pubDate' => $pubDate,
                                'guid' => md5($line)
                            ];
                        }
                    }
                }
            }
        }

        // Générer le XML du flux RSS
        $xml = '<?xml version="1.0" encoding="UTF-8" ?>' . "\n";
        $xml .= '<rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom">' . "\n";
        $xml .= '<channel>' . "\n";
        $xml .= '  <title>Logs d\'Alerte du Planning Bénévoles</title>' . "\n";
        $xml .= '  <link>http://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . '/admin/dashboard</link>' . "\n";
        $xml .= '  <description>Flux RSS diffusant les logs Warning, Error et Critical du planning</description>' . "\n";
        $xml .= '  <language>fr</language>' . "\n";
        
        foreach ($rssItems as $item) {
            $xml .= '  <item>' . "\n";
            $xml .= '    <title>' . htmlspecialchars($item['title']) . '</title>' . "\n";
            $xml .= '    <description>' . $item['description'] . '</description>' . "\n";
            $xml .= '    <pubDate>' . $item['pubDate'] . '</pubDate>' . "\n";
            $xml .= '    <guid isPermaLink="false">' . $item['guid'] . '</guid>' . "\n";
            $xml .= '  </item>' . "\n";
        }
        
        $xml .= '</channel>' . "\n";
        $xml .= '</rss>' . "\n";

        return new Response(200, [
            'Content-Type' => 'application/rss+xml; charset=utf-8',
            'Cache-Control' => 'no-cache, no-store, must-revalidate'
        ], $xml);
    }


    #[AuthMiddleware('admin')]
    #[RouteAttribute(method: "POST", path: "/admin/settings/maintenance_bypass", name: "admin.settings.maintenance_bypass")]
    public function setMaintenanceBypassCookie(): Response
    {
        $secret = getenv('APP_MAINTENANCE_SECRET');
        if ($secret === false) {
            $secret = $_ENV['APP_MAINTENANCE_SECRET'] ?? '';
        }
        if (empty($secret)) {
            $_SESSION['admin_error'] = "Aucun secret de maintenance n'est configuré dans le .env.";
            return $this->redirect('admin.dashboard');
        }

        // Poser le cookie pour 30 jours
        header('Set-Cookie: maintenance_bypass=' . urlencode($secret) . '; Path=/; Max-Age=2592000; HttpOnly; SameSite=Lax');
        
        // Activer la maintenance dans le .env
        $this->updateEnvFile('APP_MAINTENANCE', 'true');

        $_SESSION['admin_success'] = "Le mode maintenance a été activé avec succès et votre cookie de contournement a été configuré.";
        return $this->redirect('admin.dashboard');
    }

    #[AuthMiddleware('admin')]
    #[RouteAttribute(method: "POST", path: "/admin/settings/maintenance_disable", name: "admin.settings.maintenance_disable")]
    public function disableMaintenance(): Response
    {
        $this->updateEnvFile('APP_MAINTENANCE', 'false');
        
        // Supprimer le cookie de bypass
        header('Set-Cookie: maintenance_bypass=; Path=/; Max-Age=0; HttpOnly; SameSite=Lax');
        
        $_SESSION['admin_success'] = "Le mode maintenance a été désactivé avec succès.";
        return $this->redirect('admin.dashboard');
    }

    private function updateEnvFile(string $key, string $value): void
    {
        // Ne pas modifier le .env réel lors des tests unitaires
        if (defined('PHPUNIT_COMPOSER_INSTALL') || defined('__PHPUNIT_PHAR__') || (getenv('APP_ENV') === 'testing')) {
            $_ENV[$key] = $value;
            putenv($key . '=' . $value);
            return;
        }

        $envPath = realpath(__DIR__ . '/../../') . '/.env';
        if (!file_exists($envPath)) {
            // fallback si on est en test ou structure différente
            $envPath = __DIR__ . '/../../.env';
            if (!file_exists($envPath)) {
                return;
            }
        }

        $content = file_get_contents($envPath);
        
        // Pattern pour remplacer la clé existante
        $pattern = "/^" . preg_quote($key, '/') . "=(.*)$/m";
        
        if (preg_match($pattern, $content)) {
            $content = preg_replace($pattern, $key . "=" . $value, $content);
        } else {
            $content .= "\n" . $key . "=" . $value . "\n";
        }
        
        file_put_contents($envPath, $content);
        
        // Mettre à jour également en mémoire
        $_ENV[$key] = $value;
        putenv($key . '=' . $value);
    }
}
