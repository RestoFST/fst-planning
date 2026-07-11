<?php

namespace Tests\Middleware;

use App\Middleware\AuthMiddleware;
use App\Core\DB;
use App\Core\Router;
use App\Core\ContainerFactory;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UriInterface;
use Psr\Http\Server\RequestHandlerInterface;
use PHPUnit\Framework\TestCase;
use GuzzleHttp\Psr7\Response;

class AuthMiddlewareForceChangeTest extends TestCase
{
    private $router;
    private $db;
    private $pdo;
    private $request;
    private $handler;

    protected function setUp(): void
    {
        $this->router = $this->createMock(Router::class);
        
        // Mock Singleton pour Router
        $refRouter = new \ReflectionClass(Router::class);
        $instanceProperty = $refRouter->getProperty('instance');
        $instanceProperty->setAccessible(true);
        $instanceProperty->setValue(null, $this->router);

        $this->router->method('generate')->willReturnCallback(function($name) {
            if ($name === 'auth.login_form') return '/login';
            if ($name === 'auth.force_change_password') return '/force-change-password';
            return '';
        });

        $this->db = $this->createMock(DB::class);
        $this->pdo = $this->createMock(\PDO::class);
        $this->db->method('getConnection')->willReturn($this->pdo);

        $this->request = $this->createMock(ServerRequestInterface::class);
        $this->handler = $this->createMock(RequestHandlerInterface::class);

        // Mock Container global
        $container = $this->createMock(\DI\Container::class);
        $container->method('get')->with(DB::class)->willReturn($this->db);

        $refFactory = new \ReflectionClass(ContainerFactory::class);
        $containerProperty = $refFactory->getProperty('container');
        $containerProperty->setAccessible(true);
        $containerProperty->setValue(null, $container);

        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $_SESSION = [];
    }

    public function testRedirectsToLoginWhenNotConnected(): void
    {
        $middleware = new AuthMiddleware();
        $response = $middleware->process($this->request, $this->handler);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/login', $response->getHeaderLine('Location'));
    }

    public function testRedirectsToForceChangePasswordWhenLastModifiedIsNull(): void
    {
        $_SESSION['user'] = [
            'id' => 42,
            'roles' => ['user'],
            'last_password_modified' => null
        ];

        // Mock DB query showing lastModifiedPassword is NULL in DB
        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->method('fetchColumn')->willReturn(null);
        $this->pdo->method('prepare')->willReturn($stmt);

        // Router match returns different route
        $this->router->method('match')->willReturn([
            'name' => 'index'
        ]);

        $middleware = new AuthMiddleware();
        $response = $middleware->process($this->request, $this->handler);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/force-change-password', $response->getHeaderLine('Location'));
    }

    public function testAllowsRequestWhenAlreadyOnForceChangePassword(): void
    {
        $_SESSION['user'] = [
            'id' => 42,
            'roles' => ['user'],
            'last_password_modified' => null
        ];

        // Mock DB query showing lastModifiedPassword is NULL in DB
        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->method('fetchColumn')->willReturn(null);
        $this->pdo->method('prepare')->willReturn($stmt);

        // Router match returns force_change route
        $this->router->method('match')->willReturn([
            'name' => 'auth.force_change_password'
        ]);

        $expectedResponse = new Response(200, [], 'Formulaire');
        $this->handler->method('handle')->willReturn($expectedResponse);

        $middleware = new AuthMiddleware();
        $response = $middleware->process($this->request, $this->handler);

        $this->assertSame($expectedResponse, $response);
    }
}
