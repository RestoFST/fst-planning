<?php

namespace App\Core;

use DI\Attribute\Inject;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Psr\Log\LoggerInterface;

class LogManager
{
    private array $channels = [];
    private string $logFile;
    private Level $level;
 
    public function __construct(#[Inject('app.env')] private string $appEnv)
    {
        $this->logFile = __DIR__ . '/../../app.log';
        
        // On choisit le niveau de log selon le mode Debug
        $this->level = $this->appEnv === 'production' ? Level::Info : Level::Debug;
    }

    /**
     * Récupère ou crée un canal de log spécifique
     */
    public function channel(string $name = 'app'): LoggerInterface
    {
        if (isset($this->channels[$name])) {
            return $this->channels[$name];
        }

        $logger = new Logger($name);
        
        $handler = new StreamHandler($this->logFile, $this->level);
        $logger->pushHandler($handler);
        return $this->channels[$name] = $logger;
    }
}