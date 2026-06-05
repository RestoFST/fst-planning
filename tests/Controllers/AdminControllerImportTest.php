<?php

namespace Tests\Controllers;

use App\Controllers\AdminController;
use App\Core\DB;
use App\Core\Router;
use App\Core\TwigRenderer;
use Psr\Log\LoggerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;
use Psr\Http\Message\StreamInterface;
use GuzzleHttp\Psr7\Response;

class AdminControllerImportTest extends TestCase
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
            if ($name === 'admin.users') return '/admin/users';
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
            session_start();
        }
        $_SESSION = [];
    }

    public function testUserImportSuccess(): void
    {
        $_SESSION['user'] = [
            'id' => 1,
            'role' => 'responsable'
        ];

        // Créer un fichier CSV temporaire
        $csvContent = "firstname;name\n";
        $csvContent .= "Paul;Martin\n";
        $tempFile = tempnam(sys_get_temp_dir(), 'csv');
        file_put_contents($tempFile, $csvContent);

        $uploadedFile = $this->createMock(UploadedFileInterface::class);
        $uploadedFile->method('getError')->willReturn(UPLOAD_ERR_OK);
        
        $stream = $this->createMock(StreamInterface::class);
        $stream->method('getMetadata')->with('uri')->willReturn($tempFile);
        $uploadedFile->method('getStream')->willReturn($stream);

        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getUploadedFiles')->willReturn([
            'csv_file' => $uploadedFile
        ]);

        // Mock PDO check query (user doesn't exist)
        $stmtCheck = $this->createMock(\PDOStatement::class);
        $stmtCheck->method('fetch')->willReturn(false);

        // Mock PDO insert query
        $stmtInsert = $this->createMock(\PDOStatement::class);
        $stmtInsert->method('execute')->willReturn(true);

        $this->pdo->method('prepare')->willReturnOnConsecutiveCalls(
            $stmtCheck,
            $stmtInsert
        );

        $response = $this->controller->userImport($request);

        unlink($tempFile);

        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame(302, $response->getStatusCode());
        $this->assertStringContainsString("Importation terminée : 1 bénévole(s) importé(s)", $_SESSION['admin_success']);
    }

    public function testUserImportInvalidHeader(): void
    {
        $_SESSION['user'] = [
            'id' => 1,
            'role' => 'responsable'
        ];

        // Header sans 'name' ou 'firstname'
        $csvContent = "email;phone;role\n";
        $csvContent .= "paul@test.com;0601020304;user\n";
        $tempFile = tempnam(sys_get_temp_dir(), 'csv');
        file_put_contents($tempFile, $csvContent);

        $uploadedFile = $this->createMock(UploadedFileInterface::class);
        $uploadedFile->method('getError')->willReturn(UPLOAD_ERR_OK);
        
        $stream = $this->createMock(StreamInterface::class);
        $stream->method('getMetadata')->with('uri')->willReturn($tempFile);
        $uploadedFile->method('getStream')->willReturn($stream);

        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getUploadedFiles')->willReturn([
            'csv_file' => $uploadedFile
        ]);

        $response = $this->controller->userImport($request);

        unlink($tempFile);

        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame("En-têtes incorrects. Le fichier CSV doit contenir au moins des colonnes pour le Prénom (firstname) et le Nom (name).", $_SESSION['admin_error']);
    }
}
