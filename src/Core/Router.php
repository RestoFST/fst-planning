<?php

namespace App\Core;

use AltoRouter;

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

    public function registerController(string $controllerClass): void
    {
        $reflectionClass = new \ReflectionClass($controllerClass);
        $attributes = $reflectionClass->getAttributes(\App\Attribute\RouteAttribute::class);

        $prefix = "/";
        
        if (!empty($attributes)) {
            /** @var \App\Attribute\RouteAttribute $routeAttribute */
            $routeAttribute = $attributes[0]->newInstance();
            $prefix = rtrim($routeAttribute->getPath(), '/');
        }

        $methods = $reflectionClass->getMethods();

        foreach ($methods as $method) {
            $this->registerMethod($controllerClass, $method, $prefix);
        }
    }

    public function registerMethod(string $controllerClass, \ReflectionMethod $method, string $prefix): void
    {
        $attributes = $method->getAttributes(\App\Attribute\RouteAttribute::class);

        foreach ($attributes as $attribute) {
            /** @var \App\Attribute\RouteAttribute $routeAttribute */
            $routeAttribute = $attribute->newInstance();
            $this->router->map(
                $routeAttribute->getMethod(),
                $prefix . '/' . ltrim($routeAttribute->getPath(), '/'),
                [$controllerClass, $method->getName()],
                $routeAttribute->getName()
            );
        }
    }

    public function addRoutes(array $routes): void
    {
        foreach ($routes as $route) {
            $this->router->addRoutes($routes);
        }
    }

    public function match(): ?array
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