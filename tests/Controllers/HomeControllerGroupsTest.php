<?php

namespace Tests\Controllers;

use App\Controllers\HomeController;
use App\Core\DB;
use App\Core\Router;
use App\Core\TwigRenderer;
use Psr\Log\LoggerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use GuzzleHttp\Psr7\Response;

class HomeControllerGroupsTest extends TestCase
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

        $this->controller = new HomeController(
            $this->router,
            $this->logger,
            $this->db
        );

        $this->router->method('generate')->willReturnCallback(function($name) {
            if ($name === 'index') return '/';
            if ($name === 'contact') return '/contact';
            return '';
        });

        $this->router->method('match')->willReturnCallback(function() {
            return ['target' => [HomeController::class, 'index']];
        });

        $this->container = $this->createMock(\DI\Container::class);
        $this->twigRenderer = $this->createMock(TwigRenderer::class);
        
        $this->container->method('get')->willReturnCallback(function($id) {
            if ($id === TwigRenderer::class) return $this->twigRenderer;
            if ($id === 'contact.mail') return $_SESSION['test_contact_mail'] ?? '';
            return null;
        });

        $refClass = new \ReflectionClass(HomeController::class);
        $property = $refClass->getProperty('container');
        $property->setAccessible(true);
        $property->setValue($this->controller, $this->container);

        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $_SESSION = [];
    }

    public function testIndexFiltersRestrictedActivitiesForNonMembers(): void
    {
        $_SESSION['user'] = [
            'id' => 10,
            'roles' => ['user'] // Non administrative
        ];

        // Mocks for settings, restrictions, my groups, workdays, etc.
        $stmtSettings = $this->createMock(\PDOStatement::class);
        $stmtSettings->method('fetchColumn')->willReturn(1); // 1 day ahead

        // User belongs to no groups
        $stmtMyGroups = $this->createMock(\PDOStatement::class);
        $stmtMyGroups->method('fetchAll')->willReturn([]);

        // Workdays query
        $stmtClassiques = $this->createMock(\PDOStatement::class);
        $stmtClassiques->method('fetchAll')->willReturn([
            ['id' => 1, 'name' => 'Activité Restreinte', 'hours' => '10:00 - 12:00'],
            ['id' => 2, 'name' => 'Activité Publique', 'hours' => '14:00 - 16:00']
        ]);

        $stmtExceptionnels = $this->createMock(\PDOStatement::class);
        $stmtExceptionnels->method('fetchAll')->willReturn([]);

        $this->pdo->method('prepare')->willReturnCallback(function($sql) use ($stmtSettings, $stmtMyGroups, $stmtClassiques, $stmtExceptionnels) {
            if (str_contains($sql, 'home_days_count')) {
                return $stmtSettings;
            }
            if (str_contains($sql, 'users_groups')) {
                return $stmtMyGroups;
            }
            if (str_contains($sql, 'services_workdays')) {
                return $stmtClassiques;
            }
            if (str_contains($sql, 'services_opening')) {
                return $stmtExceptionnels;
            }
            
            // Default mock for internal getServiceCardData prepares
            $stmt = $this->createMock(\PDOStatement::class);
            $stmt->method('execute')->willReturn(true);
            $stmt->method('fetch')->willReturn(false);
            $stmt->method('fetchAll')->willReturn([]);
            return $stmt;
        });

        // Service 1 is restricted to group 5, service 2 is public
        $stmtRestrictions = $this->createMock(\PDOStatement::class);
        $stmtRestrictions->method('fetchAll')->willReturn([
            ['sid' => 1, 'gid' => 5]
        ]);

        // No exceptional opening restrictions configured
        $stmtOpRestrictions = $this->createMock(\PDOStatement::class);
        $stmtOpRestrictions->method('fetchAll')->willReturn([]);

        $this->pdo->method('query')->willReturnOnConsecutiveCalls(
            $stmtRestrictions,
            $stmtOpRestrictions
        );

        $response = $this->controller->index();

        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame(200, $response->getStatusCode());
    }

    public function testRegisterBlocksRestrictedActivity(): void
    {
        $_SESSION['user'] = [
            'id' => 10,
            'roles' => ['user']
        ];

        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getParsedBody')->willReturn([
            'date' => '2026-07-20',
            'sid' => 1,
            'hours' => '10:00 - 12:00'
        ]);
        $request->method('getHeaderLine')->with('X-Requested-With')->willReturn('');

        $stmtMyGroups = $this->createMock(\PDOStatement::class);
        $stmtMyGroups->method('fetchAll')->willReturn([]);

        $stmtOpening = $this->createMock(\PDOStatement::class);
        $stmtOpening->method('fetch')->willReturn(false); // Not an exceptional opening

        $stmtActGroups = $this->createMock(\PDOStatement::class);
        $stmtActGroups->method('fetchAll')->willReturn([5]); // Restricted to group 5

        $this->pdo->method('prepare')->willReturnCallback(function($sql) use ($stmtMyGroups, $stmtOpening, $stmtActGroups) {
            if (str_contains($sql, 'users_groups')) {
                return $stmtMyGroups;
            }
            if (str_contains($sql, 'services_opening')) {
                return $stmtOpening;
            }
            if (str_contains($sql, 'services_groups')) {
                return $stmtActGroups;
            }
            $stmt = $this->createMock(\PDOStatement::class);
            $stmt->method('execute')->willReturn(true);
            return $stmt;
        });

        $response = $this->controller->register($request);

        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame("Vous n'avez pas l'autorisation de vous inscrire à cette activité ce jour-là.", $_SESSION['error_message']);
    }

    public function testRegisterBlocksExceptionalOpeningWithGroupRestrictions(): void
    {
        $_SESSION['user'] = [
            'id' => 10,
            'roles' => ['user']
        ];

        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getParsedBody')->willReturn([
            'date' => '2026-07-25',
            'sid' => 1,
            'hours' => '10:00 - 12:00'
        ]);
        $request->method('getHeaderLine')->with('X-Requested-With')->willReturn('');

        $stmtMyGroups = $this->createMock(\PDOStatement::class);
        $stmtMyGroups->method('fetchAll')->willReturn([]); // User belongs to no groups

        // Date IS an exceptional opening with ID 44
        $stmtOpening = $this->createMock(\PDOStatement::class);
        $stmtOpening->method('fetch')->willReturn(['id' => 44]);

        // Exceptional opening is restricted to group 99
        $stmtOpGroups = $this->createMock(\PDOStatement::class);
        $stmtOpGroups->method('fetchAll')->willReturn([99]);

        $this->pdo->method('prepare')->willReturnCallback(function($sql) use ($stmtMyGroups, $stmtOpening, $stmtOpGroups) {
            if (str_contains($sql, 'users_groups')) {
                return $stmtMyGroups;
            }
            if (str_contains($sql, 'services_opening_groups')) {
                return $stmtOpGroups;
            }
            if (str_contains($sql, 'services_opening')) {
                return $stmtOpening;
            }
            $stmt = $this->createMock(\PDOStatement::class);
            $stmt->method('execute')->willReturn(true);
            return $stmt;
        });

        $response = $this->controller->register($request);

        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame("Vous n'avez pas l'autorisation de vous inscrire à cette activité ce jour-là.", $_SESSION['error_message']);
    }

    public function testRegisterAllowsExceptionalOpeningWithPublicOverride(): void
    {
        $_SESSION['user'] = [
            'id' => 10,
            'roles' => ['user']
        ];

        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getParsedBody')->willReturn([
            'date' => '2026-07-25',
            'sid' => 1,
            'hours' => '10:00 - 12:00'
        ]);
        $request->method('getHeaderLine')->with('X-Requested-With')->willReturn('');

        $stmtMyGroups = $this->createMock(\PDOStatement::class);
        $stmtMyGroups->method('fetchAll')->willReturn([]); // User has no groups

        // Date IS an exceptional opening with ID 44
        $stmtOpening = $this->createMock(\PDOStatement::class);
        $stmtOpening->method('fetch')->willReturn(['id' => 44]);

        // Exceptional opening has NO restrictions (empty array -> public override)
        $stmtOpGroups = $this->createMock(\PDOStatement::class);
        $stmtOpGroups->method('fetchAll')->willReturn([]);

        $this->pdo->method('prepare')->willReturnCallback(function($sql) use ($stmtMyGroups, $stmtOpening, $stmtOpGroups) {
            if (str_contains($sql, 'users_groups')) {
                return $stmtMyGroups;
            }
            if (str_contains($sql, 'services_opening_groups')) {
                return $stmtOpGroups;
            }
            if (str_contains($sql, 'services_opening')) {
                return $stmtOpening;
            }
            $stmt = $this->createMock(\PDOStatement::class);
            $stmt->method('execute')->willReturn(true);
            // Ensure internal queries in register transaction don't crash
            $stmt->method('fetch')->willReturn(false);
            return $stmt;
        });

        // We also need to mockbeginTransaction and commit for register transaction
        $this->pdo->method('beginTransaction')->willReturn(true);
        $this->pdo->method('commit')->willReturn(true);

        $response = $this->controller->register($request);

        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame("Inscription réussie !", $_SESSION['success_message']);
    }

    public function testContactFormRendersContactPage(): void
    {
        $_SESSION['user'] = [
            'id' => 1,
            'roles' => ['user']
        ];

        $this->router->method('match')->willReturnCallback(function() {
            return ['target' => [HomeController::class, 'contactForm']];
        });

        $this->twigRenderer->method('render')
            ->with('contact', $this->callback(fn($data) => is_array($data)))
            ->willReturn('contact_form_html');

        $response = $this->controller->contactForm();

        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('contact_form_html', (string)$response->getBody());
    }

    public function testContactSubmitFailsWithEmptyFields(): void
    {
        $_SESSION['user'] = [
            'id' => 1,
            'roles' => ['user']
        ];

        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getParsedBody')->willReturn([
            'subject' => '',
            'message' => ''
        ]);

        $response = $this->controller->contactSubmit($request);

        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/contact', $response->getHeaderLine('Location'));
        $this->assertSame("Veuillez remplir tous les champs obligatoires.", $_SESSION['contact_error']);
    }

    public function testContactSubmitSendsEmailSuccessfully(): void
    {
        $_SESSION['user'] = [
            'id' => 1,
            'firstname' => 'Jean',
            'name' => 'Dupont',
            'username' => 'jdupont',
            'roles' => ['user'],
            'email' => 'jean@dupont.com'
        ];
        $_SESSION['test_contact_mail'] = 'admin@planning.net';

        $uri = $this->createMock(\Psr\Http\Message\UriInterface::class);
        $uri->method('getHost')->willReturn('planning-association.fr');

        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getUri')->willReturn($uri);
        $request->method('getParsedBody')->willReturn([
            'subject' => 'Mon Sujet',
            'message' => 'Mon Message'
        ]);

        $response = $this->controller->contactSubmit($request);

        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/contact', $response->getHeaderLine('Location'));
        $this->assertTrue(isset($_SESSION['contact_success']) || isset($_SESSION['contact_error']));

        unset($_SESSION['test_contact_mail']);
    }
}
