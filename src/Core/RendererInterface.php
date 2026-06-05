<?php

namespace App\Core;

interface RendererInterface
{
    public function render(string $template, array $data = []): string;
}