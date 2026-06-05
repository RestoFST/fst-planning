<?php

namespace App\Controllers;

use App\Attribute\RenderAttribute;
use App\Core\Router;
use App\Core\DB;
use DI\Attribute\Inject;
use Psr\Log\LoggerInterface;

abstract class BaseController
{

    #[Inject('app.env')]
    protected string $app_env;

    #[Inject]
    protected \DI\Container $container;

    public function __construct(
        protected Router $router,
        protected LoggerInterface $logger, 
        protected DB $database
    )
    {
    }

    public function render(string $view, array $data = []): string
    {
        $target = $this->router->match()['target'];
        $reflectionClass = new \ReflectionClass($target[0]);
        $reflectionMethod = $reflectionClass->getMethod($target[1]);
        $attributes = $reflectionMethod->getAttributes(RenderAttribute::class);
        
        $rendererClass = null;
        if (!empty($attributes)) {
            $rendererClass = $attributes[0]->newInstance()->getRendererClass();
        } else {
            $attributes = $reflectionClass->getAttributes(RenderAttribute::class);
            if (!empty($attributes)) {
                $rendererClass = $attributes[0]->newInstance()->getRendererClass();
            }
        }

        if ($rendererClass) {
            /** @var \App\Core\RendererInterface $renderer */
            $renderer = $this->container->get($rendererClass);
            return $renderer->render($view, $data);
        }
        return '';
    }

    public function generateUrl(string $routeName, array $params = []): string
    {
        return $this->router->generate($routeName, $params);
    }

    public function redirect(string $routeName, array $params = [], int $status = 302): \GuzzleHttp\Psr7\Response
    {
        return new \GuzzleHttp\Psr7\Response($status, ['Location' => $this->generateUrl($routeName, $params)]);
    }
}