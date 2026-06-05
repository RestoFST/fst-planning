<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class AddCalendarTokenToUsers extends AbstractMigration
{
    public function up(): void
    {
        $table = $this->table('users');
        $table->addColumn('calendar_token', 'string', ['limit' => 64, 'null' => true, 'default' => null])
              ->addIndex(['calendar_token'], ['unique' => true])
              ->update();

        // Générer un token pour tous les utilisateurs existants
        $dbPrefix = $_ENV['DB_PREFIX'] ?? '';
        $usersTable = $dbPrefix . 'users';

        $pdo = $this->getAdapter()->getConnection();
        $stmt = $pdo->query("SELECT id FROM `{$usersTable}` WHERE calendar_token IS NULL");
        $users = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $stmtUpdate = $pdo->prepare("UPDATE `{$usersTable}` SET calendar_token = :token WHERE id = :id");
        foreach ($users as $u) {
            $token = bin2hex(random_bytes(32));
            $stmtUpdate->execute(['token' => $token, 'id' => $u['id']]);
        }
    }

    public function down(): void
    {
        $table = $this->table('users');
        $table->removeIndex(['calendar_token'])
              ->removeColumn('calendar_token')
              ->update();
    }
}
