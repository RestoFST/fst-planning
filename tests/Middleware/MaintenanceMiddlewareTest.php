<?php

namespace Tests\Middleware;

use App\Middleware\MaintenanceMiddleware;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

class MaintenanceMiddlewareTest extends TestCase
{
    private $request;
    private $handler;
    private $router;
    private $middleware;

    protected function setUp(): void
    {
        $this->request = $this->createMock(ServerRequestInterface::class);
        $this->handler = $this->createMock(RequestHandlerInterface::class);
        $this->router = $this->createMock(\App\Core\Router::class);

        $this->router->method('generate')->willReturnCallback(function($name) {
            if ($name === 'index') return '/';
            if ($name === 'admin.settings.maintenance_bypass') return '/admin/settings/maintenance_bypass';
            return '';
        });

        $this->middleware = new MaintenanceMiddleware($this->router);

        // Réinitialiser les variables d'environnement
        putenv('APP_MAINTENANCE');
        putenv('APP_MAINTENANCE_SECRET');
    }

    public function testProcessProceedsNormallyWhenMaintenanceIsDisabled(): void
    {
        putenv('APP_MAINTENANCE=false');

        $this->request->method('getQueryParams')->willReturn([]);
        
        $expectedResponse = new Response(200);
        $this->handler->expects($this->once())
            ->method('handle')
            ->with($this->request)
            ->willReturn($expectedResponse);

        $response = $this->middleware->process($this->request, $this->handler);
        $this->assertSame($expectedResponse, $response);
    }

    public function testProcessReturns503WhenMaintenanceIsEnabledAndNoCookie(): void
    {
        putenv('APP_MAINTENANCE=true');
        putenv('APP_MAINTENANCE_SECRET=secret123');

        $this->request->method('getQueryParams')->willReturn([]);
        $this->request->method('getCookieParams')->willReturn([]);
        $this->request->method('getUri')->willReturn(new \GuzzleHttp\Psr7\Uri('http://localhost/accueil'));

        $response = $this->middleware->process($this->request, $this->handler);

        $this->assertInstanceOf(ResponseInterface::class, $response);
        $this->assertSame(503, $response->getStatusCode());
    }

    public function testProcessProceedsNormallyWhenCookieIsValid(): void
    {
        putenv('APP_MAINTENANCE=true');
        putenv('APP_MAINTENANCE_SECRET=secret123');

        $this->request->method('getQueryParams')->willReturn([]);
        $this->request->method('getCookieParams')->willReturn(['maintenance_bypass' => 'secret123']);

        $expectedResponse = new Response(200);
        $this->handler->expects($this->once())
            ->method('handle')
            ->with($this->request)
            ->willReturn($expectedResponse);

        $response = $this->middleware->process($this->request, $this->handler);
        $this->assertSame($expectedResponse, $response);
    }

    public function testProcessSetsCookieAndRedirectsWhenBypassQueryParamIsCorrect(): void
    {
        putenv('APP_MAINTENANCE=true');
        putenv('APP_MAINTENANCE_SECRET=secret123');

        $this->request->method('getQueryParams')->willReturn(['bypass' => 'secret123']);

        $response = $this->middleware->process($this->request, $this->handler);

        $this->assertInstanceOf(ResponseInterface::class, $response);
        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/', $response->getHeaderLine('Location'));
    }

    public function testProcessClearsBypassCookieWhenMaintenanceIsDisabledAndCookieIsPresent(): void
    {
        putenv('APP_MAINTENANCE=false');

        $this->request->method('getQueryParams')->willReturn([]);
        $this->request->method('getCookieParams')->willReturn(['maintenance_bypass' => 'secret123']);

        $expectedResponse = new Response(200);
        $this->handler->expects($this->once())
            ->method('handle')
            ->with($this->request)
            ->willReturn($expectedResponse);

        $response = $this->middleware->process($this->request, $this->handler);
        $this->assertSame($expectedResponse, $response);
    }
}
