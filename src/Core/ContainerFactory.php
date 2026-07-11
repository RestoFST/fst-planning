<?php

namespace App\Core;

use DI\ContainerBuilder;
use DI\Container;

class ContainerFactory
{
    private static ?Container $container = null;

    public static function build(): Container
    {
        if (self::$container !== null) {
            return self::$container;
        }

        $config = new Config(); // Load .env variables
        $config->require(Config::REQUIRED_ENV_VARS);

        $builder = new ContainerBuilder();
        $builder->addDefinitions(__DIR__ . '/../../config.php');
        $builder->addDefinitions([
            Config::class => $config
        ]);
        $builder->useAutowiring(true);
        $builder->useAttributes(true);
        if (getenv('APP_ENV') === 'production') {
            $builder->enableCompilation(__DIR__ . '/../tmp');
            $builder->writeProxiesToFile(true, __DIR__ . '/../tmp/proxies');
        }

        self::$container = $builder->build();
        return self::$container;
    }

    public static function getContainer(): ?Container
    {
        return self::$container;
    }
}