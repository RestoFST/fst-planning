<?php

namespace App\Core;

use Dotenv\Dotenv;
use Dotenv\Validator;

class Config
{
    private static $instance = null;
    private $config;
    private Dotenv $dotenv;

    private function __construct()
    {
        $this->dotenv = Dotenv::createImmutable(__DIR__ . '/../../');
        $this->dotenv->load();
    }

    public static function getInstance()
    {
        if (self::$instance === null) {
            self::$instance = new Config();
        }
        return self::$instance;
    }

    public function get($key, $default = null)
    {
        return $_ENV[$key] ?? $default;
    }

    public function all()
    {
        return $_ENV;
    }

    public function has($key)
    {
        return isset($_ENV[$key]);
    }

    public function require(...$keys): Validator
    {
        return $this->dotenv->required($keys);
    }
}
