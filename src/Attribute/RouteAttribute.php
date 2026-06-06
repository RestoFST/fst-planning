<?php

namespace App\Attribute;

use Attribute;

#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_CLASS)]
class RouteAttribute
{
    private string $method;
    private string $path;
    private ?string $name;

    public function __construct(string $method, string $path, ?string $name = null)
    {
        $this->method = $method;
        $this->path = $path;
        $this->name = $name;
    }

    public function getMethod(): string
    {
        return $this->method;
    }

    public function getPath(): string
    {
        return $this->path;
    }

    public function getName(): ?string
    {
        return $this->name;
    }
}
