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
        date_default_timezone_set('Europe/Paris');

        // Générer automatiquement les clés VAPID si elles sont absentes
        if (empty($_ENV['VAPID_PUBLIC_KEY']) || empty($_ENV['VAPID_PRIVATE_KEY'])) {
            try {
                $keys = \App\Core\WebPushService::generateVapidKeys();
                \App\Core\WebPushService::updateEnvKeys($keys['publicKey'], $keys['privateKey']);
            } catch (\Exception $e) {
                // Ignorer silencieusement si OpenSSL n'est pas disponible ou écriture protégée
            }
        }
    }

    public function require(string|array $keys): Validator
    {
        return $this->dotenv->required($keys);
    }
}
