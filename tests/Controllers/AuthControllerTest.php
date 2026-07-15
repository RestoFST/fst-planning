<?php

namespace Tests\Controllers;

use App\Controllers\AuthController;
use App\Core\DB;
use App\Core\Router;
use App\Core\TwigRenderer;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use PHPUnit\Framework\TestCase;

class AuthControllerTest extends TestCase
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

        $this->controller = new AuthController(
            $this->router,
            $this->logger,
            $this->db
        );

        $this->container = $this->createMock(\DI\Container::class);
        $this->twigRenderer = $this->createMock(TwigRenderer::class);

        $this->router->method('generate')->willReturnCallback(function($name) {
            if ($name === 'index') return '/';
            if ($name === 'auth.login_form') return '/login';
            if ($name === 'profile') return '/profile';
            return '';
        });

        $this->container->method('get')
            ->with(TwigRenderer::class)
            ->willReturn($this->twigRenderer);

        $refClass = new \ReflectionClass(AuthController::class);
        $property = $refClass->getProperty('container');
        $property->setAccessible(true);
        $property->setValue($this->controller, $this->container);

        if (session_status() === PHP_SESSION_NONE) {
            session_name('planning_session');
            session_start();
        }
        $_SESSION = [];
        $_COOKIE = [];
    }

    public function testLoginFormRendersTemplate(): void
    {
        $this->twigRenderer->method('render')
            ->with('auth/login', $this->callback(fn($data) => is_array($data)))
            ->willReturn('login_html');

        $this->router->method('match')->willReturn([
            'target' => [AuthController::class, 'loginForm']
        ]);

        $response = $this->controller->loginForm();
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('login_html', (string)$response->getBody());
    }

    public function testLoginSubmitSuccessWithRememberMe(): void
    {
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getParsedBody')->willReturn([
            'username' => 'testuser',
            'password' => 'secret',
            'remember_me' => '1'
        ]);

        $stmtUser = $this->createMock(\PDOStatement::class);
        $stmtUser->method('execute')->willReturn(true);
        $stmtUser->method('fetch')->willReturn([
            'id' => 42,
            'name' => 'Dupont',
            'firstname' => 'Jean',
            'username' => 'testuser',
            'password' => password_hash('secret', PASSWORD_DEFAULT),
            'role' => 'user',
            'email' => 'jean@test.com',
            'phone' => '0600000000',
            'lastModifiedPassword' => '2026-07-09 12:00:00'
        ]);

        $stmtRemember = $this->createMock(\PDOStatement::class);
        $stmtRemember->method('execute')->willReturn(true);

        $this->pdo->method('prepare')->willReturnOnConsecutiveCalls($stmtUser, $stmtRemember);

        $response = $this->controller->loginSubmit($request);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/', $response->getHeaderLine('Location'));
        $this->assertSame(42, $_SESSION['user']['id']);
        $this->assertSame('testuser', $_SESSION['user']['username']);
    }

    public function testAutoLoginWithCookieSuccess(): void
    {
        $_COOKIE['remember_me'] = 'public_part:private_part';

        $stmtToken = $this->createMock(\PDOStatement::class);
        $stmtToken->method('execute')->willReturn(true);
        $stmtToken->method('fetch')->willReturn([
            'id' => 99,
            'uid' => 42,
            'public_token' => 'public_part',
            'private_hash' => password_hash('private_part', PASSWORD_DEFAULT),
            'expiration_date' => '2026-08-01 12:00:00'
        ]);

        $stmtUser = $this->createMock(\PDOStatement::class);
        $stmtUser->method('execute')->willReturn(true);
        $stmtUser->method('fetch')->willReturn([
            'id' => 42,
            'name' => 'Dupont',
            'firstname' => 'Jean',
            'username' => 'testuser',
            'role' => 'user',
            'email' => 'jean@test.com',
            'phone' => '0600000000',
            'lastModifiedPassword' => '2026-07-09 12:00:00'
        ]);

        $stmtUpdate = $this->createMock(\PDOStatement::class);
        $stmtUpdate->method('execute')->willReturn(true);

        $this->pdo->method('prepare')->willReturnOnConsecutiveCalls($stmtToken, $stmtUser, $stmtUpdate);

        $this->controller->autoLoginWithCookie();

        $this->assertSame(42, $_SESSION['user']['id']);
        $this->assertSame('testuser', $_SESSION['user']['username']);
        $this->assertSame('2026-07-09 12:00:00', $_SESSION['user']['last_password_modified']);
    }

    public function testLogoutCleansCookieAndSession(): void
    {
        $_SESSION['user'] = [
            'id' => 42,
            'username' => 'testuser'
        ];
        $_COOKIE['remember_me'] = 'public_part:private_part';

        $stmtDel = $this->createMock(\PDOStatement::class);
        $stmtDel->method('execute')->willReturn(true);

        $this->pdo->method('prepare')->willReturn($stmtDel);

        $response = $this->controller->logout();

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/login', $response->getHeaderLine('Location'));
        $this->assertEmpty($_SESSION);
    }

    public function testProfilePageRendersTemplate(): void
    {
        $_SESSION['user'] = [
            'id' => 42,
            'username' => 'testuser'
        ];

        $this->twigRenderer->method('render')
            ->with('profile', $this->callback(fn($data) => is_array($data)))
            ->willReturn('profile_html');

        $this->router->method('match')->willReturn([
            'target' => [AuthController::class, 'profile']
        ]);

        $response = $this->controller->profile();
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('profile_html', (string)$response->getBody());
    }

    public function testProfileSubmitSuccessWithPasswordChange(): void
    {
        $_SESSION['user'] = [
            'id' => 42,
            'username' => 'testuser'
        ];

        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getParsedBody')->willReturn([
            'username' => 'newusername',
            'password' => 'newsecret',
            'password_confirm' => 'newsecret'
        ]);

        $stmtCheck = $this->createMock(\PDOStatement::class);
        $stmtCheck->method('execute')->willReturn(true);
        $stmtCheck->method('fetch')->willReturn(false); // Username non pris

        $stmtUpdateUser = $this->createMock(\PDOStatement::class);
        $stmtUpdateUser->method('execute')->willReturn(true);

        $stmtUpdatePass = $this->createMock(\PDOStatement::class);
        $stmtUpdatePass->method('execute')->willReturn(true);

        $stmtDelTokens = $this->createMock(\PDOStatement::class);
        $stmtDelTokens->method('execute')->willReturn(true);

        $this->pdo->method('prepare')->willReturnOnConsecutiveCalls(
            $stmtCheck,
            $stmtUpdateUser,
            $stmtUpdatePass,
            $stmtDelTokens
        );

        $response = $this->controller->profileSubmit($request);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/profile', $response->getHeaderLine('Location'));
        $this->assertSame('newusername', $_SESSION['user']['username']);
        $this->assertNotEmpty($_SESSION['user']['last_password_modified']);
        $this->assertSame("Votre profil a bien été mis à jour.", $_SESSION['profile_success']);
    }

    public function testLogoutDevices(): void
    {
        $_SESSION['user'] = [
            'id' => 42,
            'username' => 'testuser'
        ];

        $request = $this->createMock(ServerRequestInterface::class);

        $stmtDel = $this->createMock(\PDOStatement::class);
        $stmtDel->method('execute')->with(['uid' => 42])->willReturn(true);

        $this->pdo->method('prepare')->willReturn($stmtDel);

        $response = $this->controller->logoutDevices($request);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/profile', $response->getHeaderLine('Location'));
        $this->assertSame("Vous avez bien été déconnecté de tous vos autres appareils.", $_SESSION['profile_success']);
    }
}
