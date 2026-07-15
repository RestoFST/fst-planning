<?php

namespace Tests\Controllers;

use App\Controllers\AdminDashboardController;
use App\Core\DB;
use App\Core\Router;
use App\Core\TwigRenderer;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use PHPUnit\Framework\TestCase;

class AdminDashboardControllerTest extends TestCase
{
    private $router;
    private $logger;
    private $db;
    private $pdo;
    private $container;
    private $twigRenderer;
    private $controller;

    protected function setUp(): void
    {
        $this->router = $this->createMock(Router::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->db = $this->createMock(DB::class);
        $this->pdo = $this->createMock(\PDO::class);

        $this->db->method('getConnection')->willReturn($this->pdo);

        $this->controller = new AdminDashboardController(
            $this->router,
            $this->logger,
            $this->db
        );

        $this->router->method('generate')->willReturnCallback(function($name) {
            if ($name === 'admin.dashboard') return '/admin/dashboard';
            if ($name === 'admin.logs') return '/admin/logs';
            return '';
        });

        // Injecter le container mocké pour le rendu de vue
        $this->container = $this->createMock(\DI\Container::class);
        $this->twigRenderer = $this->createMock(TwigRenderer::class);
        
        $this->container->method('get')
            ->with(TwigRenderer::class)
            ->willReturn($this->twigRenderer);

        $refClass = new \ReflectionClass(AdminDashboardController::class);
        $property = $refClass->getProperty('container');
        $property->setAccessible(true);
        $property->setValue($this->controller, $this->container);

        if (session_status() === PHP_SESSION_NONE) {
            session_name('planning_session');
            session_start();
        }
        $_SESSION = [];
    }

    public function testIndexRendersDashboard(): void
    {
        $_SESSION['user'] = [
            'id' => 1,
            'role' => 'admin'
        ];

        $stmtToken = $this->createMock(\PDOStatement::class);
        $stmtToken->method('execute')->willReturn(true);
        $stmtToken->method('fetch')->willReturn('init_token_abc123');

        $stmtDays = $this->createMock(\PDOStatement::class);
        $stmtDays->method('execute')->willReturn(true);
        $stmtDays->method('fetchColumn')->willReturn('7');

        $stmtBanner = $this->createMock(\PDOStatement::class);
        $stmtBanner->method('execute')->willReturn(true);
        $stmtBanner->method('fetchAll')->willReturn([
            'banner_message' => 'Maintenance',
            'banner_type' => 'info',
            'banner_active' => '1'
        ]);

        $stmtUsers = $this->createMock(\PDOStatement::class);
        $stmtUsers->method('fetchAll')->willReturn([
            ['id' => 1, 'name' => 'FST', 'firstname' => 'Admin', 'username' => 'admin']
        ]);

        $this->pdo->method('prepare')->willReturnOnConsecutiveCalls($stmtToken, $stmtDays, $stmtBanner);
        $this->pdo->method('query')->willReturn($stmtUsers);

        $this->twigRenderer->method('render')
            ->with('admin/dashboard', $this->callback(fn($data) => is_array($data)))
            ->willReturn('dashboard_html_content');

        // Mocker match() du router car render() l'appelle
        $this->router->method('match')->willReturn([
            'target' => [AdminDashboardController::class, 'index']
        ]);

        $response = $this->controller->index();

        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('dashboard_html_content', (string)$response->getBody());
        $this->assertSame('init_token_abc123', $_SESSION['user']['rss_token']);
    }

     public function testResetRssTokenUpdatesDatabaseAndSession(): void
    {
        $_SESSION['user'] = [
            'id' => 1,
            'role' => 'admin',
            'rss_token' => 'old_token'
        ];

        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->method('execute')->willReturn(true);
        $this->pdo->method('prepare')->willReturn($stmt);

        $response = $this->controller->resetRssToken();

        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/admin/logs', $response->getHeaderLine('Location'));
        $this->assertNotSame('old_token', $_SESSION['user']['rss_token']);
        $this->assertNotEmpty($_SESSION['user']['rss_token']);
    }

    public function testLogsListRendersLogs(): void
    {
        $_SESSION['user'] = [
            'id' => 1,
            'role' => 'admin'
        ];

        $stmtToken = $this->createMock(\PDOStatement::class);
        $stmtToken->method('execute')->willReturn(true);
        $stmtToken->method('fetch')->willReturn('correct_token');

        $this->pdo->method('prepare')->willReturn($stmtToken);

        $this->twigRenderer->method('render')
            ->with('admin/logs', $this->callback(fn($data) => is_array($data)))
            ->willReturn('logs_html_content');

        // Mocker match() du router car render() l'appelle
        $this->router->method('match')->willReturn([
            'target' => [AdminDashboardController::class, 'logsList']
        ]);

        $response = $this->controller->logsList();

        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('logs_html_content', (string)$response->getBody());
    }

    public function testExportRgpdReturnsJsonForUser(): void
    {
        $_SESSION['user'] = [
            'id' => 1,
            'roles' => ['admin']
        ];

        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getQueryParams')->willReturn(['uid' => '2']);

        $stmtUser = $this->createMock(\PDOStatement::class);
        $stmtUser->method('execute')->willReturn(true);
        $stmtUser->method('fetch')->willReturn([
            'id' => 2,
            'name' => 'Bénévole',
            'firstname' => 'Test',
            'username' => 'test',
            'email' => 'test@test.com',
            'phone' => '0102030405',
            'role' => 'user',
            'lastModifiedPassword' => null
        ]);

        $stmtGroups = $this->createMock(\PDOStatement::class);
        $stmtGroups->method('execute')->willReturn(true);
        $stmtGroups->method('fetchAll')->willReturn([
            ['id' => 1, 'name' => 'Chauffeurs', 'description' => 'Chauffeurs livreurs']
        ]);

        $stmtApp = $this->createMock(\PDOStatement::class);
        $stmtApp->method('execute')->willReturn(true);
        $stmtApp->method('fetchAll')->willReturn([
            ['date' => '2026-07-03', 'service_name' => 'Distribution', 'presence' => 'present']
        ]);

        // PDO should return user statement first, groups statement second, then appointments statement third
        $this->pdo->method('prepare')->willReturnOnConsecutiveCalls($stmtUser, $stmtGroups, $stmtApp);

        $response = $this->controller->exportRgpd($request);

        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('application/json; charset=utf-8', $response->getHeaderLine('Content-Type'));
        
        $json = json_decode((string)$response->getBody(), true);
        $this->assertSame('RGPD Personal Data Portability Export', $json['metadata']['export_type']);
        $this->assertSame('Bénévole', $json['user_profile']['name']);
        $this->assertSame('Chauffeurs', $json['user_profile']['groups'][0]['name']);
        $this->assertSame('Distribution', $json['activity_registrations'][0]['service_name']);
    }

    public function testLogsRssRestrictsAccessWithInvalidToken(): void
    {
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getQueryParams')->willReturn(['token' => 'wrong_token']);

        $stmtToken = $this->createMock(\PDOStatement::class);
        $stmtToken->method('execute')->willReturn(true);
        $stmtToken->method('fetch')->willReturn('correct_token');

        $this->pdo->method('prepare')->willReturn($stmtToken);

        $response = $this->controller->logsRss($request);

        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame('403 Forbidden - Token invalide', (string)$response->getBody());
    }

    public function testLogsRssReturnsXmlWithValidToken(): void
    {
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getQueryParams')->willReturn(['token' => 'correct_token']);

        $stmtToken = $this->createMock(\PDOStatement::class);
        $stmtToken->method('execute')->willReturn(true);
        $stmtToken->method('fetch')->willReturn('correct_token');

        $this->pdo->method('prepare')->willReturn($stmtToken);

        $response = $this->controller->logsRss($request);

        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('application/rss+xml; charset=utf-8', $response->getHeaderLine('Content-Type'));
        $this->assertStringContainsString('<rss version="2.0"', (string)$response->getBody());
    }


    public function testSetMaintenanceBypassCookieSetsCookie(): void
    {
        $_SESSION['user'] = [
            'id' => 1,
            'role' => 'admin'
        ];

        putenv('APP_MAINTENANCE_SECRET=bypass_key_abc');

        $response = $this->controller->setMaintenanceBypassCookie();

        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/admin/dashboard', $response->getHeaderLine('Location'));
        $this->assertSame("Le mode maintenance a été activé avec succès et votre cookie de contournement a été configuré.", $_SESSION['admin_success']);
    }

    public function testDisableMaintenance(): void
    {
        $_SESSION['user'] = [
            'id' => 1,
            'role' => 'admin'
        ];

        $response = $this->controller->disableMaintenance();

        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/admin/dashboard', $response->getHeaderLine('Location'));
        $this->assertSame("Le mode maintenance a été désactivé avec succès.", $_SESSION['admin_success']);
    }
}
