<?php

namespace App\Core;

use PDO;

class PrefixedPDO extends PDO
{
    private string $prefix;

    public function __construct(string $dsn, ?string $username = null, ?string $password = null, ?array $options = null, string $prefix = '')
    {
        parent::__construct($dsn, $username, $password, $options);
        $this->prefix = $prefix;
    }

    public function getPrefix(): string
    {
        return $this->prefix;
    }

    public function prefixQuery(string $sql): string
    {
        if (empty($this->prefix)) {
            return $sql;
        }

        $tables = [
            'appointments_users', // Doit être en premier pour éviter que 'appoinment' ne matche partiellement
            'appoinment',
            'remember_tokens',
            'services_holyday',
            'services_workdays',
            'services_opening',
            'services',
            'settings',
            'users'
        ];

        foreach ($tables as $table) {
            // Expression régulière cherchant le nom exact de la table, non précédé du préfixe déjà présent
            $pattern = '/(?<!' . preg_quote($this->prefix, '/') . ')\b' . preg_quote($table, '/') . '\b/i';
            $sql = preg_replace($pattern, $this->prefix . $table, $sql);
        }

        return $sql;
    }

    public function prepare(string $query, array $options = []): \PDOStatement|false
    {
        return parent::prepare($this->prefixQuery($query), $options);
    }

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): \PDOStatement|false
    {
        $prefixed = $this->prefixQuery($query);
        if ($fetchMode === null) {
            return parent::query($prefixed);
        }
        return parent::query($prefixed, $fetchMode, ...$fetchModeArgs);
    }

    public function exec(string $statement): int|false
    {
        return parent::exec($this->prefixQuery($statement));
    }
}
