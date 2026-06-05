<?php

namespace Tests\Controllers;

use App\Controllers\AdminController;
use App\Core\DB;
use App\Core\Router;
use App\Core\TwigRenderer;
use Psr\Log\LoggerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use GuzzleHttp\Psr7\Response;

class AdminControllerGroupsTest extends TestCase
{
    private $router;
    private $logger;
    private $db;
    private $pdo;
    private $controller;
    private $container;
    private $twigRenderer;

    protected function setUp(): void
    {
        $this->router = $this->createMock(Router::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->db = $this->createMock(DB::class);
        $this->pdo = $this->createMock(\PDO::class);
        $this->db->method('getConnection')->willReturn($this->pdo);

        $webPushService = $this->createMock(\App\Core\WebPushService::class);

        $this->controller = new AdminController(
            $this->router,
            $this->logger,
            $this->db,
            $webPushService
        );

        $this->router->method('generate')->willReturnCallback(function($name) {
            if ($name === 'admin.groups') return '/admin/groups';
            return '';
        });

        $this->router->method('match')->willReturn([
            'target' => [AdminController::class, 'groupsList']
        ]);

        $this->container = $this->createMock(\DI\Container::class);
        $this->twigRenderer = $this->createMock(TwigRenderer::class);
        
        $this->container->method('get')
            ->with(TwigRenderer::class)
            ->willReturn($this->twigRenderer);

        $refClass = new \ReflectionClass(AdminController::class);
        $property = $refClass->getProperty('container');
        $property->setAccessible(true);
        $property->setValue($this->controller, $this->container);

        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $_SESSION = [];
    }

    public function testGroupsList(): void
    {
        $_SESSION['user'] = [
            'id' => 1,
            'role' => 'responsable'
        ];

        $stmtGroups = $this->createMock(\PDOStatement::class);
        $stmtGroups->method('fetchAll')->willReturn([
            ['id' => 1, 'name' => 'Chauffeurs', 'description' => 'Test drivers', 'users_count' => 2, 'services_count' => 1]
        ]);

        $stmtUsers = $this->createMock(\PDOStatement::class);
        $stmtUsers->method('fetchAll')->willReturn([
            ['id' => 10, 'firstname' => 'Jean', 'name' => 'Dupont']
        ]);

        $this->pdo->method('query')->willReturnOnConsecutiveCalls(
            $stmtGroups,
            $stmtUsers
        );

        $stmtMembres = $this->createMock(\PDOStatement::class);
        $stmtMembres->method('fetchAll')->willReturn([
            ['id' => 10, 'firstname' => 'Jean', 'name' => 'Dupont']
        ]);

        $this->pdo->method('prepare')->willReturn($stmtMembres);

        $response = $this->controller->groupsList();

        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame(200, $response->getStatusCode());
    }

    public function testGroupCreate(): void
    {
        $_SESSION['user'] = [
            'id' => 1,
            'role' => 'responsable'
        ];

        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getParsedBody')->willReturn([
            'name' => 'Nouveau Groupe',
            'description' => 'Description test'
        ]);

        $stmtCheck = $this->createMock(\PDOStatement::class);
        $stmtCheck->method('fetch')->willReturn(false); // Does not exist

        $stmtInsert = $this->createMock(\PDOStatement::class);
        $stmtInsert->method('execute')->willReturn(true);

        $this->pdo->method('prepare')->willReturnOnConsecutiveCalls(
            $stmtCheck,
            $stmtInsert
        );

        $response = $this->controller->groupCreate($request);

        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame("Le groupe 'Nouveau Groupe' a bien été créé.", $_SESSION['group_success']);
    }

    public function testGroupEdit(): void
    {
        $_SESSION['user'] = [
            'id' => 1,
            'role' => 'responsable'
        ];

        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getParsedBody')->willReturn([
            'id' => 5,
            'name' => 'Groupe Modifié',
            'description' => 'Nouvelle description'
        ]);

        $stmtCheck = $this->createMock(\PDOStatement::class);
        $stmtCheck->method('fetch')->willReturn(false);

        $stmtUpdate = $this->createMock(\PDOStatement::class);
        $stmtUpdate->method('execute')->willReturn(true);

        $this->pdo->method('prepare')->willReturnOnConsecutiveCalls(
            $stmtCheck,
            $stmtUpdate
        );

        $response = $this->controller->groupEdit($request);

        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame("Le groupe a bien été modifié.", $_SESSION['group_success']);
    }

    public function testGroupDelete(): void
    {
        $_SESSION['user'] = [
            'id' => 1,
            'role' => 'responsable'
        ];

        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getParsedBody')->willReturn([
            'id' => 5
        ]);

        $stmtDelete = $this->createMock(\PDOStatement::class);
        $stmtDelete->method('execute')->with(['id' => 5])->willReturn(true);

        $this->pdo->method('prepare')->willReturn($stmtDelete);

        $response = $this->controller->groupDelete($request);

        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame("Le groupe a bien été supprimé.", $_SESSION['group_success']);
    }
}
