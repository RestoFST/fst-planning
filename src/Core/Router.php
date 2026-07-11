<?php

namespace App\Core;

use AltoRouter;
use App\Controllers\BaseController as Controller;

class Router
{
    private AltoRouter $router;
    private array|bool $matchedRoute = [];
    private static ?self $instance = null;

    public function __construct()
    {
        $this->router = new AltoRouter();
        
        // Lire le basepath depuis les variables d'environnement (.env)
        $basePath = getenv('APP_BASEPATH');
        if ($basePath === false) {
            $basePath = $_ENV['APP_BASEPATH'] ?? '';
        }
        $basePath = trim($basePath);

        // Nettoyer le basePath pour AltoRouter (pas de slash final)
        if ($basePath !== '/' && $basePath !== '\\') {
            $basePath = rtrim($basePath, '/\\');
        } else {
            $basePath = '';
        }
        
        if (!empty($basePath)) {
            $this->setBasePath($basePath);
        }

        self::$instance = $this;
    }

    public static function getInstance(): ?self
    {
        return self::$instance;
    }

    public function setBasePath(string $basePath): void
    {
        $this->router->setBasePath($basePath);
    }

    public function registerController(Controller $controller): void
    {
        $reflectionClass = new \ReflectionClass($controller::class);

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
            $path = '/' . ltrim($prefix . '/' . ltrim($routeAttribute->getPath(), '/'), '/');
            $path = preg_replace('#/{2,}#', '/', $path);
            
            $this->router->map(
                $routeAttribute->getMethod(),
                $path,
                [$controller, $method->getName()],
                $routeAttribute->getName()
            );
        }
    }

    public function match(): array|bool
    {
        if (!$this->matchedRoute||empty($this->matchedRoute)) {
            $this->matchedRoute = $this->router->match();
        }
        return $this->matchedRoute;
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
