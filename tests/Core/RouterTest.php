<?php

namespace Tests\Core;

use App\Attribute\RouteAttribute;
use App\Core\Router;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Router::class)]
#[UsesClass(RouteAttribute::class)]
class RouterTest extends TestCase
{
    private Router $router;

    protected function setUp(): void
    {
        $this->router = new Router();
    }

    public function testRegisterControllerAddsNamedRouteWithClassPrefix(): void
    {
        $this->router->registerController(DummyController::class);

        $this->assertSame('/api/list', $this->router->generate('dummy_list'));

        $match = $this->router->getRouter()->match('/api/list', 'GET');

        $this->assertIsArray($match);
        $this->assertSame('dummy_list', $match['name']);
        $this->assertSame([DummyController::class, 'list'], $match['target']);
    }

    public function testGenerateIncludesBasePathWhenSet(): void
    {
        $this->router->setBasePath('/app');
        $this->router->registerController(DummyController::class);

        $this->assertSame('/app/api/list', $this->router->generate('dummy_list'));
    }

    public function testRegisterControllerThrowsExceptionWhenClassDoesNotExist(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->router->registerController('NonExistentController');
    }

    public function testAddRoutesRegistersMultipleRoutesWithoutDuplicates(): void
    {
        $routes = [
            ['GET', '/news', [DummyController::class, 'list'], 'news_list'],
            ['POST', '/news', [DummyController::class, 'submit'], 'news_submit'],
        ];

        $this->router->addRoutes($routes);

        $this->assertSame('/news', $this->router->generate('news_list'));
        $this->assertSame('/news', $this->router->generate('news_submit'));

        $matchGet = $this->router->getRouter()->match('/news', 'GET');
        $this->assertSame('news_list', $matchGet['name']);

        $matchPost = $this->router->getRouter()->match('/news', 'POST');
        $this->assertSame('news_submit', $matchPost['name']);
    }

        public function testMatchReturnsArray(): void
        {
            $this->router->registerController(DummyController::class);
    
            $_SERVER['REQUEST_METHOD'] = 'GET';
            $_SERVER['REQUEST_URI'] = '/api/list';
            $this->assertEquals(['target' => [DummyController::class, 'list'], 'params' => [], 'name' => 'dummy_list'], $this->router->match());
        }

        public function testMatchReturnsNullOnNonExistent(): void
        {
            $this->router->registerController(DummyController::class);
    
            $_SERVER['REQUEST_METHOD'] = 'GET';
            $_SERVER['REQUEST_URI'] = '/non-existent';
            $this->assertEquals(false, $this->router->match());
        }
}

#[RouteAttribute('GET', '/api')]
class DummyController
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
