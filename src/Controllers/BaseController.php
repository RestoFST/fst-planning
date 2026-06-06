<?php

namespace App\Controllers;

use App\Attribute\RenderAttribute;
use App\Core\Router;
use App\Core\DB;
use DI\Attribute\Inject;
use LoggerInterface;

abstract class BaseController
{

    #[Inject('app.env')]
    protected string $app_env;

    public function __construct(
        protected Router $router,
        protected LoggerInterface $logger, 
        protected DB $database
    )
    {
        //throw new \Exception('Not implemented');
    }

    public function render(string $view, array $data = []): string
    {
        $target = $this->router->match()['target'];
        $reflectionClass = new \ReflectionClass($target[0]);
        $reflectionMethod = $reflectionClass->getMethod($target[1]);
        $attributes = $reflectionMethod->getAttributes(RenderAttribute::class);
        if (!empty($attributes)) {
            return $attributes[0]->newInstance()->render($view, $data);
        }
        $attributes = $reflectionClass->getAttributes(RenderAttribute::class);
        if (!empty($attributes)) {
            return $attributes[0]->newInstance()->render($view, $data);
        }
        return '';
    }
}