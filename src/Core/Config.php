<?php

namespace App\Core;

use Dotenv\Dotenv;
use Dotenv\Validator;

class Config
{
    private Dotenv $dotenv;

    public const REQUIRED_ENV_VARS = ['APP_ENV', 'DB_HOST', 'DB_NAME', 'DB_USER', 'DB_PASS'];

    public function __construct()
    {
        $this->dotenv = Dotenv::createImmutable(__DIR__ . '/../../');
        $this->dotenv->load();
    }

    public function require(...$keys): Validator
    {
        return $this->dotenv->required($keys);
    }
}
