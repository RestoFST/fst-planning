<?php

namespace App\Attribute;

use App\Core\RendererInterface;
use Attribute;

#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_CLASS)]
class RenderAttribute
{
    public function __construct(private string $rendererClass)
    {
    }

    public function getRendererClass(): string
    {
        return $this->rendererClass;
    }
}
