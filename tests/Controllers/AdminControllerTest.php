<?php

namespace Tests\Controllers;

use App\Controllers\AdminController;
use App\Core\DB;
use App\Core\Router;
use App\Core\TwigRenderer;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use PHPUnit\Framework\TestCase;

class AdminControllerTest extends TestCase
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

        $webPushService = $this->createMock(\App\Core\WebPushService::class);

        $this->controller = new AdminController(
            $this->router,
            $this->logger,
            $this->db,
            $webPushService
        );

        $this->router->method('generate')->willReturnCallback(function($name) {
            if ($name === 'admin.pointage') return '/admin/pointage';
            if ($name === 'admin.users') return '/admin/users';
            if ($name === 'admin.display_settings') return '/admin/display-settings';
            return '';
        });

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
            session_name('planning_session');
            session_start();
        }
        $_SESSION = [];
    }

    public function testRegistrationsListForSimpleUser(): void
    {
        $_SESSION['user'] = [
            'id' => 12,
            'role' => 'user'
        ];

        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getQueryParams')->willReturn([
            'uid' => 'all', // Should be forced to '12' internally
            'date_debut' => '2026-07-01',
            'date_fin' => '2026-07-31'
        ]);

        $stmtRegs = $this->createMock(\PDOStatement::class);
        $stmtRegs->method('execute')->with($this->callback(function($params) {
            return $params['uid'] === 12 && $params['date_debut'] === '2026-07-01' && $params['date_fin'] === '2026-07-31';
        }))->willReturn(true);
        $stmtRegs->method('fetchAll')->willReturn([
            ['date' => '2026-07-03', 'service_name' => 'Distribution', 'user_name' => 'Dupont', 'user_firstname' => 'Jean', 'presence' => 'present']
        ]);

        $this->pdo->method('prepare')->willReturn($stmtRegs);

        $stmtServices = $this->createMock(\PDOStatement::class);
        $stmtServices->method('fetchAll')->willReturn([
            ['id' => 1, 'name' => 'Distribution']
        ]);
        $this->pdo->method('query')->willReturn($stmtServices);

        $this->twigRenderer->method('render')
            ->with('admin/registrations', $this->callback(function($data) {
                return $data['isSimpleUser'] === true && $data['selectedUid'] === '12';
            }))
            ->willReturn('registrations_html');

        $this->router->method('match')->willReturn([
            'target' => [AdminController::class, 'registrationsList']
        ]);

        $response = $this->controller->registrationsList($request);

        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('registrations_html', (string)$response->getBody());
    }

    public function testRegistrationsListForAdmin(): void
    {
        $_SESSION['user'] = [
            'id' => 1,
            'role' => 'admin'
        ];

        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getQueryParams')->willReturn([
            'uid' => 'all',
            'date_debut' => '',
            'date_fin' => ''
        ]);

        // Mock 1. Query lists of all users and services
        $stmtUsers = $this->createMock(\PDOStatement::class);
        $stmtUsers->method('fetchAll')->willReturn([
            ['id' => 12, 'name' => 'Dupont', 'firstname' => 'Jean']
        ]);
        $stmtServices = $this->createMock(\PDOStatement::class);
        $stmtServices->method('fetchAll')->willReturn([
            ['id' => 1, 'name' => 'Distribution']
        ]);
        $this->pdo->method('query')->willReturnCallback(function($sql) use ($stmtUsers, $stmtServices) {
            if (str_contains($sql, 'users')) {
                return $stmtUsers;
            }
            return $stmtServices;
        });

        // Mock 2. Query registrations
        $stmtRegs = $this->createMock(\PDOStatement::class);
        $stmtRegs->method('execute')->willReturn(true);
        $stmtRegs->method('fetchAll')->willReturn([]);
        $this->pdo->method('prepare')->willReturn($stmtRegs);

        $this->twigRenderer->method('render')
            ->with('admin/registrations', $this->callback(function($data) {
                return $data['isSimpleUser'] === false && $data['selectedUid'] === 'all';
            }))
            ->willReturn('registrations_html_admin');

        $this->router->method('match')->willReturn([
            'target' => [AdminController::class, 'registrationsList']
        ]);

        $response = $this->controller->registrationsList($request);

        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame('registrations_html_admin', (string)$response->getBody());
    }

    public function testRegistrationsExportForSimpleUser(): void
    {
        $_SESSION['user'] = [
            'id' => 12,
            'role' => 'user'
        ];

        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getQueryParams')->willReturn([
            'uid' => 'all', // Forced to '12'
            'date_debut' => '',
            'date_fin' => ''
        ]);

        $stmtUser = $this->createMock(\PDOStatement::class);
        $stmtUser->method('execute')->willReturn(true);
        $stmtUser->method('fetch')->willReturn([
            'name' => 'Dupont',
            'firstname' => 'Jean'
        ]);

        $stmtRegs = $this->createMock(\PDOStatement::class);
        $stmtRegs->method('execute')->willReturn(true);
        $stmtRegs->method('fetchAll')->willReturn([
            ['date' => '2026-07-03', 'service_name' => 'Distribution', 'user_name' => 'Dupont', 'user_firstname' => 'Jean', 'presence' => 'present']
        ]);

        $this->pdo->method('prepare')->willReturnOnConsecutiveCalls($stmtUser, $stmtRegs);

        $response = $this->controller->registrationsExport($request);

        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('text/csv; charset=utf-8', $response->getHeaderLine('Content-Type'));
        $this->assertSame('attachment; filename="historique_inscriptions_dupont_jean.csv"', $response->getHeaderLine('Content-Disposition'));
        
        $body = (string)$response->getBody();
        $this->assertStringContainsString('Date;Bénévole;Activité;Présence', $body);
        $this->assertStringContainsString('2026-07-03;"Dupont Jean";Distribution;Présent', $body);
    }

    public function testHolidaysList(): void
    {
        $_SESSION['user'] = [
            'id' => 1,
            'role' => 'responsable'
        ];

        // Mock 1. Query holidays
        $stmtHols = $this->createMock(\PDOStatement::class);
        $stmtHols->method('fetchAll')->willReturn([
            ['id' => 1, 'sid' => 5, 'name' => 'Vacances', 'start_date' => '2026-07-10', 'end_date' => '2026-07-20', 'service_name' => 'Distribution']
        ]);
        
        // Mock 2. Query services
        $stmtServices = $this->createMock(\PDOStatement::class);
        $stmtServices->method('fetchAll')->willReturn([
            ['id' => 5, 'name' => 'Distribution']
        ]);

        $this->pdo->method('query')->willReturnOnConsecutiveCalls($stmtHols, $stmtServices);

        $this->router->method('match')->willReturn([
            'target' => [AdminController::class, 'holidaysList']
        ]);

        $this->twigRenderer->method('render')
            ->with('admin/holidays', $this->callback(function($data) {
                return count($data['holidays']) === 1 && $data['holidays'][0]['name'] === 'Vacances';
            }))
            ->willReturn('holidays_html');

        $response = $this->controller->holidaysList();

        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame('holidays_html', (string)$response->getBody());
    }

    public function testHolidayCreate(): void
    {
        $_SESSION['user'] = [
            'id' => 1,
            'role' => 'responsable'
        ];

        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getParsedBody')->willReturn([
            'sid' => 5,
            'start_date' => '2026-07-10',
            'end_date' => '2026-07-15',
            'name' => 'Fermeture été'
        ]);

        // Mocking check, insert, select appointments
        $stmtCheck = $this->createMock(\PDOStatement::class);
        $stmtCheck->method('fetch')->willReturn(false); // No overlap

        $stmtInsert = $this->createMock(\PDOStatement::class);
        $stmtInsert->method('execute')->willReturn(true);

        $stmtApps = $this->createMock(\PDOStatement::class);
        $stmtApps->method('fetchAll')->willReturn([
            ['id' => 20]
        ]);

        $stmtDelInscrits = $this->createMock(\PDOStatement::class);
        $stmtDelApps = $this->createMock(\PDOStatement::class);

        $this->pdo->method('prepare')->willReturnOnConsecutiveCalls(
            $stmtCheck,
            $stmtInsert,
            $stmtApps,
            $stmtDelInscrits,
            $stmtDelApps
        );

        $response = $this->controller->holidayCreate($request);

        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('La plage de fermeture a bien été enregistrée. Toutes les inscriptions existantes pour ce créneau ont été annulées.', $_SESSION['holiday_success']);
    }

    public function testHolidayDelete(): void
    {
        $_SESSION['user'] = [
            'id' => 1,
            'role' => 'responsable'
        ];

        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getParsedBody')->willReturn([
            'id' => 12
        ]);

        $stmtDelete = $this->createMock(\PDOStatement::class);
        $stmtDelete->method('execute')->with(['id' => 12])->willReturn(true);

        $this->pdo->method('prepare')->willReturn($stmtDelete);

        $response = $this->controller->holidayDelete($request);

        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('La fermeture a bien été supprimée. Le créneau a été réouvert.', $_SESSION['holiday_success']);
    }

    public function testHolidayCreateGlobal(): void
    {
        $_SESSION['user'] = [
            'id' => 1,
            'role' => 'responsable'
        ];

        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getParsedBody')->willReturn([
            'sid' => 0, // CENTRE
            'start_date' => '2026-07-20',
            'end_date' => '2026-07-25',
            'name' => 'Fermeture complète centre'
        ]);

        $stmtCheck = $this->createMock(\PDOStatement::class);
        $stmtCheck->method('fetch')->willReturn(false);

        $stmtInsert = $this->createMock(\PDOStatement::class);
        $stmtInsert->method('execute')->willReturn(true);

        $stmtApps = $this->createMock(\PDOStatement::class);
        $stmtApps->method('fetchAll')->willReturn([]);

        $this->pdo->method('prepare')->willReturnOnConsecutiveCalls(
            $stmtCheck,
            $stmtInsert,
            $stmtApps
        );

        $response = $this->controller->holidayCreate($request);

        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('La plage de fermeture a bien été enregistrée. Toutes les inscriptions existantes pour ce créneau ont été annulées.', $_SESSION['holiday_success']);
    }

    public function testHolidayEdit(): void
    {
        $_SESSION['user'] = [
            'id' => 1,
            'role' => 'responsable'
        ];

        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getParsedBody')->willReturn([
            'id' => 12,
            'sid' => 5,
            'start_date' => '2026-07-12',
            'end_date' => '2026-07-18',
            'name' => 'Vacances modifiées'
        ]);

        $stmtCheck = $this->createMock(\PDOStatement::class);
        $stmtCheck->method('fetch')->willReturn(false); // No overlap

        $stmtUpdate = $this->createMock(\PDOStatement::class);
        $stmtUpdate->method('execute')->willReturn(true);

        $stmtApps = $this->createMock(\PDOStatement::class);
        $stmtApps->method('fetchAll')->willReturn([]);

        $this->pdo->method('prepare')->willReturnOnConsecutiveCalls(
            $stmtCheck,
            $stmtUpdate,
            $stmtApps
        );

        $response = $this->controller->holidayEdit($request);

        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('La fermeture a bien été mise à jour. Les inscriptions pour la période modifiée ont été actualisées.', $_SESSION['holiday_success']);
    }

    public function testOpeningsList(): void
    {
        $_SESSION['user'] = [
            'id' => 1,
            'role' => 'responsable'
        ];

        $stmtOpenings = $this->createMock(\PDOStatement::class);
        $stmtOpenings->method('fetchAll')->willReturn([
            ['id' => 12, 'sid' => 5, 'date' => '2026-07-25', 'hours' => '9h-12h', 'service_name' => 'Distribution']
        ]);

        $stmtServices = $this->createMock(\PDOStatement::class);
        $stmtServices->method('fetchAll')->willReturn([
            ['id' => 5, 'name' => 'Distribution']
        ]);

        $stmtAllGroups = $this->createMock(\PDOStatement::class);
        $stmtAllGroups->method('fetchAll')->willReturn([
            ['id' => 1, 'name' => 'Chauffeurs']
        ]);

        $this->pdo->method('query')->willReturnOnConsecutiveCalls($stmtOpenings, $stmtServices, $stmtAllGroups);

        $stmtOpGroups = $this->createMock(\PDOStatement::class);
        $stmtOpGroups->method('fetchAll')->willReturn([]);
        $this->pdo->method('prepare')->willReturn($stmtOpGroups);

        $this->router->method('match')->willReturn([
            'target' => [AdminController::class, 'openingsList']
        ]);

        $this->twigRenderer->method('render')
            ->with('admin/openings', $this->callback(function($data) {
                return count($data['openings']) === 1 && $data['openings'][0]['hours'] === '9h-12h';
            }))
            ->willReturn('openings_html');

        $response = $this->controller->openingsList();

        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame('openings_html', (string)$response->getBody());
    }

    public function testOpeningCreate(): void
    {
        $_SESSION['user'] = [
            'id' => 1,
            'role' => 'responsable'
        ];

        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getParsedBody')->willReturn([
            'sid' => 5,
            'date' => '2026-07-25',
            'start_time' => '09:00',
            'end_time' => '12:00',
            'description' => 'Portes ouvertes'
        ]);

        $stmtCheck = $this->createMock(\PDOStatement::class);
        $stmtCheck->method('fetchAll')->willReturn([]); // No existing openings

        $stmtInsert = $this->createMock(\PDOStatement::class);
        $stmtInsert->method('execute')->willReturn(true);

        $stmtGroup = $this->createMock(\PDOStatement::class);
        $stmtGroup->method('execute')->willReturn(true);

        $this->pdo->method('prepare')->willReturnOnConsecutiveCalls(
            $stmtCheck, 
            $stmtInsert,
            $stmtGroup
        );

        $response = $this->controller->openingCreate($request);

        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame("L'ouverture exceptionnelle a bien été enregistrée.", $_SESSION['opening_success']);
    }

    public function testOpeningEdit(): void
    {
        $_SESSION['user'] = [
            'id' => 1,
            'role' => 'responsable'
        ];

        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getParsedBody')->willReturn([
            'id' => 12,
            'sid' => 5,
            'date' => '2026-07-26',
            'start_time' => '14:00',
            'end_time' => '17:00',
            'description' => 'Séance spéciale'
        ]);

        $stmtCheck = $this->createMock(\PDOStatement::class);
        $stmtCheck->method('fetchAll')->willReturn([]); // No overlaps

        $stmtUpdate = $this->createMock(\PDOStatement::class);
        $stmtUpdate->method('execute')->willReturn(true);

        $stmtDelGroups = $this->createMock(\PDOStatement::class);
        $stmtDelGroups->method('execute')->willReturn(true);

        $stmtGroup = $this->createMock(\PDOStatement::class);
        $stmtGroup->method('execute')->willReturn(true);

        $this->pdo->method('prepare')->willReturnOnConsecutiveCalls(
            $stmtCheck, 
            $stmtUpdate,
            $stmtDelGroups,
            $stmtGroup
        );

        $response = $this->controller->openingEdit($request);

        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame("L'ouverture exceptionnelle a bien été modifiée.", $_SESSION['opening_success']);
    }

    public function testOpeningDelete(): void
    {
        $_SESSION['user'] = [
            'id' => 1,
            'role' => 'responsable'
        ];

        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getParsedBody')->willReturn([
            'id' => 12
        ]);

        $stmtFind = $this->createMock(\PDOStatement::class);
        $stmtFind->method('fetch')->willReturn([
            'sid' => 5,
            'date' => '2026-07-25',
            'start_time' => '14:00:00',
            'end_time' => '17:00:00'
        ]);

        $stmtDelApp = $this->createMock(\PDOStatement::class);
        $stmtDelApp->method('execute')->willReturn(true);

        $stmtDelOp = $this->createMock(\PDOStatement::class);
        $stmtDelOp->method('execute')->willReturn(true);

        $this->pdo->method('prepare')->willReturnOnConsecutiveCalls(
            $stmtFind,
            $stmtDelApp,
            $stmtDelOp
        );

        $response = $this->controller->openingDelete($request);

        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame("L'ouverture exceptionnelle a bien été supprimée.", $_SESSION['opening_success']);
    }

    public function testActivityCreate(): void
    {
        $_SESSION['user'] = [
            'id' => 1,
            'role' => 'responsable'
        ];

        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getParsedBody')->willReturn([
            'name' => 'Nouvelle Activité',
            'description' => 'Description test',
            'optimal_count' => 3,
            'workdays' => [1, 2],
            'start_hours' => ['08:30', '14:00'],
            'end_hours' => ['12:00', '17:30']
        ]);

        $stmtCheck = $this->createMock(\PDOStatement::class);
        $stmtCheck->method('fetch')->willReturn(false); // No duplicate name

        $stmtInsert = $this->createMock(\PDOStatement::class);
        $stmtInsert->method('execute')->willReturn(true);

        $stmtDay = $this->createMock(\PDOStatement::class);
        $stmtDay->method('execute')->willReturn(true);

        $stmtGroup = $this->createMock(\PDOStatement::class);
        $stmtGroup->method('execute')->willReturn(true);

        $this->pdo->method('prepare')->willReturnOnConsecutiveCalls(
            $stmtCheck,
            $stmtInsert,
            $stmtDay,
            $stmtGroup
        );

        $response = $this->controller->activityCreate($request);

        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame("L'activité 'Nouvelle Activité' a bien été créée.", $_SESSION['activity_success']);
    }

    public function testActivityEdit(): void
    {
        $_SESSION['user'] = [
            'id' => 1,
            'role' => 'responsable'
        ];

        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getParsedBody')->willReturn([
            'id' => 10,
            'name' => 'Activité Modifiée',
            'description' => 'Nouvelle description',
            'optimal_count' => 4,
            'workdays' => [3],
            'start_hours' => ['09:00'],
            'end_hours' => ['13:00']
        ]);

        $stmtCheck = $this->createMock(\PDOStatement::class);
        $stmtCheck->method('fetch')->willReturn(false);

        $stmtUpdate = $this->createMock(\PDOStatement::class);
        $stmtUpdate->method('execute')->willReturn(true);

        $stmtDelDays = $this->createMock(\PDOStatement::class);
        $stmtDelDays->method('execute')->willReturn(true);

        $stmtDay = $this->createMock(\PDOStatement::class);
        $stmtDay->method('execute')->willReturn(true);

        $stmtDelGroups = $this->createMock(\PDOStatement::class);
        $stmtDelGroups->method('execute')->willReturn(true);

        $stmtGroup = $this->createMock(\PDOStatement::class);
        $stmtGroup->method('execute')->willReturn(true);

        $this->pdo->method('prepare')->willReturnOnConsecutiveCalls(
            $stmtCheck,
            $stmtUpdate,
            $stmtDelDays,
            $stmtDay,
            $stmtDelGroups,
            $stmtGroup
        );

        $response = $this->controller->activityEdit($request);

        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame("L'activité a bien été mise à jour.", $_SESSION['activity_success']);
    }

    public function testDisplaySettingsPage(): void
    {
        $_SESSION['user'] = [
            'id' => 1,
            'role' => 'responsable'
        ];

        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->method('execute')->willReturn(true);
        $stmt->method('fetchColumn')->willReturn('7');
        $stmt->method('fetchAll')->willReturn([]);
        $this->pdo->method('prepare')->willReturn($stmt);

        // Mocker match() du router car render() l'appelle
        $this->router->method('match')->willReturn([
            'target' => [AdminController::class, 'displaySettings']
        ]);

        $response = $this->controller->displaySettings();

        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame(200, $response->getStatusCode());
    }

    public function testUpdateHomeDaysCountUpdatesDatabase(): void
    {
        $_SESSION['user'] = [
            'id' => 1,
            'role' => 'responsable'
        ];

        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getParsedBody')->willReturn([
            'home_days_count' => '15'
        ]);

        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->method('execute')->willReturn(true);
        $this->pdo->method('prepare')->willReturn($stmt);

        $response = $this->controller->updateHomeDaysCount($request);

        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/admin/display-settings', $response->getHeaderLine('Location'));
        $this->assertSame("Le nombre de jours affichés sur la page d'accueil a été mis à jour à 15 jours.", $_SESSION['display_success']);
    }

    public function testUpdateBannerUpdatesDatabase(): void
    {
        $_SESSION['user'] = [
            'id' => 1,
            'role' => 'responsable'
        ];

        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getParsedBody')->willReturn([
            'banner_message' => 'Annonce importante !',
            'banner_type' => 'warning',
            'banner_active' => '1'
        ]);

        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->method('execute')->willReturn(true);
        $this->pdo->method('prepare')->willReturn($stmt);

        $response = $this->controller->updateBanner($request);

        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/admin/display-settings', $response->getHeaderLine('Location'));
        $this->assertSame("La bannière d'information globale a été mise à jour avec succès.", $_SESSION['display_success']);
    }
}
