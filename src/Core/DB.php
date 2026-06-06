<?php

namespace App\Core;

use DI\Attribute\Inject;
use PDO;

class DB
{
    private PDO $pdo;

    public function getConnection(): PDO
    {
        return $this->pdo;
    }

    public function __construct(
        #[Inject('db.config')] array $config
    )
    {
        $this->pdo = new PDO(
            'mysql:host=' . $config['host'] . ';dbname=' . $config['name'],
            $config['user'],
            $config['pass']
        );
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }
}