<?php

namespace App\Attribute;

use App\Core\RendererInterface;
use Attribute;

#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_CLASS)]
class RenderAttribute
{

    public function __construct(private RendererInterface $renderer)
    {
    }

    public function render(string $view, array $data = []): string
    {
        return $this->renderer->render($view, $data);
    }
}
