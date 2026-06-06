<?php

namespace App\Core;

use Twig\Environment;

class TwigRenderer implements RendererInterface
{
    public function __construct(private Environment $twig)
    {}

    public function render(string $template, array $data = []): string
    {
        return $this->twig->render($template, $data);
    }
}