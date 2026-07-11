<?php

namespace App\Core;

use DI\Attribute\Inject;
use PDO;

class DB
{
    private PrefixedPDO $pdo;

    public function getConnection(): PDO
    {
        return $this->pdo;
    }

    public function __construct(
        #[Inject('db.config')] array $config
    )
    {
        $this->pdo = new PrefixedPDO(
            'mysql:host=' . $config['host'] . ';dbname=' . $config['name'],
            $config['user'],
            $config['pass'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
            $config['prefix'] ?? ''
        );
    }
}