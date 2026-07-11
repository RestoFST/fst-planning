<?php

namespace App\Core;

class Logger
{
    private static ?string $logFile = null;

    private static function getLogFile(): string
    {
        if (self::$logFile === null) {
            $dir = dirname(__DIR__, 2) . '/logs';
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
            self::$logFile = $dir . '/app.log';
        }
        return self::$logFile;
    }

    public static function log(string $level, string $message, array $context = []): void
    {
        // Anonymisation de l'adresse IP si elle est présente dans le contexte
        if (isset($context['ip'])) {
            $context['ip'] = self::anonymizeIp($context['ip']);
        }

        // Masquage du token de sécurité s'il est présent dans le contexte
        if (isset($context['provided_token'])) {
            $context['provided_token'] = self::maskToken($context['provided_token']);
        }

        // Anonymisation du nom d'utilisateur s'il est présent pour les tentatives échouées de connexion
        if (isset($context['username'])) {
            if (stripos($message, 'échouée') !== false || stripos($message, 'fail') !== false || stripos($message, 'erreur') !== false) {
                $context['username'] = self::maskUsernameForFailure($context['username']);
            }
        }

        $logFile = self::getLogFile();
        $date = (new \DateTime())->format('Y-m-d H:i:s');
        $contextStr = !empty($context) ? ' ' . json_encode($context, JSON_UNESCAPED_UNICODE) : '';
        $line = sprintf("[%s] [%s] %s%s\n", $date, strtoupper($level), $message, $contextStr);

        file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);
    }

    private static function anonymizeIp(?string $ip): string
    {
        if (!$ip || $ip === 'unknown') return 'unknown';
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $parts = explode('.', $ip);
            if (count($parts) === 4) {
                return $parts[0] . '.' . $parts[1] . '.' . $parts[2] . '.xxx';
            }
        } elseif (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $parts = explode(':', $ip);
            if (count($parts) > 1) {
                return $parts[0] . ':' . $parts[1] . ':xxxx::xxxx';
            }
        }
        return 'unknown';
    }

    private static function maskToken(?string $token): string
    {
        if (empty($token)) return '';
        return substr($token, 0, 4) . '...';
    }

    private static function maskUsernameForFailure(?string $username): string
    {
        if (empty($username)) return '';
        $len = strlen($username);
        if ($len <= 3) {
            return '***';
        }
        return substr($username, 0, 3) . '...';
    }

    public static function info(string $message, array $context = []): void
    {
        self::log('INFO', $message, $context);
    }

    public static function warning(string $message, array $context = []): void
    {
        self::log('WARNING', $message, $context);
    }

    public static function error(string $message, array $context = []): void
    {
        self::log('ERROR', $message, $context);
    }

    public static function critical(string $message, array $context = []): void
    {
        self::log('CRITICAL', $message, $context);
    }
}
