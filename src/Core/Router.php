<?php

namespace App\Core;

use AltoRouter;
use App\Controllers\BaseController as Controller;

class Router
{
    private AltoRouter $router;

    public function __construct()
    {
        $this->router = new AltoRouter();
    }

    public function setBasePath(string $basePath): void
    {
        $this->router->setBasePath($basePath);
    }

    public function registerController(Controller $controller): void
    {
        try {
            $reflectionClass = new \ReflectionClass($controller::class);
        } catch (\ReflectionException $exception) {
            throw new \InvalidArgumentException(sprintf('Controller class "%s" does not exist.', $controller::class), 0, $exception);
        }

        $attributes = $reflectionClass->getAttributes(\App\Attribute\RouteAttribute::class);

        $prefix = "/";
        
        if (!empty($attributes)) {
            /** @var \App\Attribute\RouteAttribute $routeAttribute */
            $routeAttribute = $attributes[0]->newInstance();
            $prefix = rtrim($routeAttribute->getPath(), '/');
        }

        $methods = $reflectionClass->getMethods();

        foreach ($methods as $method) {
            $this->registerMethod($controller, $method, $prefix);
        }
    }

    public function registerMethod(Controller $controller, \ReflectionMethod $method, string $prefix): void
    {
        $attributes = $method->getAttributes(\App\Attribute\RouteAttribute::class);

        foreach ($attributes as $attribute) {
            /** @var \App\Attribute\RouteAttribute $routeAttribute */
            $routeAttribute = $attribute->newInstance();
            $this->router->map(
                $routeAttribute->getMethod(),
                $prefix . '/' . ltrim($routeAttribute->getPath(), '/'),
                [$controller, $method->getName()],
                $routeAttribute->getName()
            );
        }
    }

    public function match(): array|bool
    {
        return $this->router->match();
    }

    public function generate(string $name, array $params = []): string
    {
        return $this->router->generate($name, $params);
    }

    public function getRouter(): AltoRouter
    {
        return $this->router;
    }

}