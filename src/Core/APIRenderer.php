<?php

namespace App\Core;

class APIRenderer implements RendererInterface
{
    public function render(string $template, array $data = []): string
    {
        header('Content-Type: application/json');
        return json_encode($data);
    }
}