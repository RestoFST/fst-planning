<?php

namespace Tests\Core;

use App\Attribute\RouteAttribute;
use App\Core\Router;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use App\Controller\BaseController;
use stdClass;

#[CoversClass(Router::class)]
#[UsesClass(RouteAttribute::class)]
#[UsesClass(BaseController::class)]
class RouterTest extends TestCase
{
    private Router $router;

    protected function setUp(): void
    {
        $this->router = new Router();
    }

    public function testRegisterControllerAddsNamedRouteWithClassPrefix(): void
    {
        $controller = new DummyController();
        $this->router->registerController($controller);

        $this->assertSame('/api/list', $this->router->generate('dummy_list'));

        $match = $this->router->getRouter()->match('/api/list', 'GET');

        $this->assertIsArray($match);
        $this->assertSame('dummy_list', $match['name']);
        $this->assertSame([$controller, 'list'], $match['target']);
    }

    public function testGenerateIncludesBasePathWhenSet(): void
    {
        $this->router->setBasePath('/app');
        $controller = new DummyController();
        $this->router->registerController($controller);

        $this->assertSame('/app/api/list', $this->router->generate('dummy_list'));
    }

    public function testMatchReturnsArray(): void
    {
        $controller = new DummyController();
        $this->router->registerController($controller);

        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/api/list';
        $this->assertEquals(['target' => [$controller, 'list'], 'params' => [], 'name' => 'dummy_list'], $this->router->match());
    }

    public function testMatchReturnsNullOnNonExistent(): void
    {
        $controller = new DummyController();
        $this->router->registerController($controller);

        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/non-existent';
        $this->assertEquals(false, $this->router->match());
    }
}

#[RouteAttribute('GET', '/api')]
class DummyController extends BaseController
{
    #[RouteAttribute('GET', '/list', 'dummy_list')]
    public function list(): void
    {
    }

    #[RouteAttribute('POST', '/submit', 'dummy_submit')]
    public function submit(): void
    {
    }

    public function helper(): void
    {
    }
}
