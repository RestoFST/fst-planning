<?php

namespace Tests\Controllers;

use App\Controllers\CalendarController;
use App\Core\DB;
use App\Core\Router;
use App\Core\TwigRenderer;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use PHPUnit\Framework\TestCase;

class CalendarControllerTest extends TestCase
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

        $this->controller = new CalendarController(
            $this->router,
            $this->logger,
            $this->db
        );

        $this->container = $this->createMock(\DI\Container::class);
        $this->twigRenderer = $this->createMock(TwigRenderer::class);

        $this->container->method('get')
            ->with(TwigRenderer::class)
            ->willReturn($this->twigRenderer);

        $this->router->method('match')->willReturn([
            'target' => [CalendarController::class, 'ical']
        ]);

        $refClass = new \ReflectionClass(CalendarController::class);
        $property = $refClass->getProperty('container');
        $property->setAccessible(true);
        $property->setValue($this->controller, $this->container);
    }

    public function testIcalReturns404ForInvalidToken(): void
    {
        $stmtUser = $this->createMock(\PDOStatement::class);
        $stmtUser->method('execute')->willReturn(true);
        $stmtUser->method('fetch')->willReturn(false); // Utilisateur introuvable

        $this->pdo->method('prepare')->willReturn($stmtUser);

        $request = $this->createMock(ServerRequestInterface::class);
        $response = $this->controller->ical($request, 'invalid_token');

        $this->assertSame(404, $response->getStatusCode());
        $this->assertStringContainsString('introuvable', (string)$response->getBody());
    }

    public function testIcalSuccess(): void
    {
        // Mock de la requête utilisateur
        $stmtUser = $this->createMock(\PDOStatement::class);
        $stmtUser->method('execute')->willReturn(true);
        $stmtUser->method('fetch')->willReturn([
            'id' => 42,
            'firstname' => 'Jean',
            'name' => 'Dupont'
        ]);

        // Mock de la requête d'appointments
        $stmtApps = $this->createMock(\PDOStatement::class);
        $stmtApps->method('execute')->willReturn(true);
        $stmtApps->method('fetchAll')->willReturn([
            [
                'appointment_id' => 101,
                'date' => '2026-07-20',
                'start_time' => '08:00:00',
                'end_time' => '10:00:00',
                'service_name' => 'Accueil bénévoles',
                'service_description' => 'Aider à l\'accueil.'
            ]
        ]);

        // Mock de la requête d'attendees
        $stmtAttendees = $this->createMock(\PDOStatement::class);
        $stmtAttendees->method('execute')->willReturn(true);
        $stmtAttendees->method('fetchAll')->willReturn([
            ['firstname' => 'Marie', 'name' => 'Martin']
        ]);

        // PDO prepare doit renvoyer successivement :
        // 1. $stmtUser (recherche de l'user par token)
        // 2. $stmtApps (récupération de ses créneaux)
        // 3. $stmtAttendees (récupération des participants pour le créneau 101)
        $this->pdo->method('prepare')->willReturnOnConsecutiveCalls(
            $stmtUser,
            $stmtApps,
            $stmtAttendees
        );

        $this->twigRenderer->method('render')
            ->willReturn(''); // Non utilisé, Sabre génère le contenu directement

        $request = $this->createMock(ServerRequestInterface::class);
        $response = $this->controller->ical($request, 'valid_token');

        $body = (string)$response->getBody();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('text/calendar; charset=utf-8', $response->getHeaderLine('Content-Type'));
        $this->assertSame('inline; filename="planning.ics"', $response->getHeaderLine('Content-Disposition'));

        // Vérifier que le contenu est un vrai flux iCal généré par Sabre
        $this->assertStringContainsString('BEGIN:VCALENDAR', $body);
        $this->assertStringContainsString('END:VCALENDAR', $body);
        $this->assertStringContainsString('BEGIN:VEVENT', $body);
        $this->assertStringContainsString('SUMMARY:Accueil bénévoles', $body);
        $this->assertStringContainsString('appointment-101@planning-benevoles.fr', $body);
        $this->assertStringContainsString('Marie Martin', $body);
        $this->assertStringContainsString('PRODID:-//Planning Benevoles//Calendar//FR', $body);
    }
}
