<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class CreateRememberTokensTable extends AbstractMigration
{
    /**
     * Change Method.
     *
     * Write your reversible migrations using this method.
     *
     * More information on writing migrations is available here:
     * https://book.cakephp.org/phinx/0/en/migrations.html#the-change-method
     */
    public function change(): void
    {
        $table = $this->table('remember_tokens', ['id' => 'id', 'signed' => false]);

        $table
            ->addColumn('uid', 'integer', ['signed' => false, 'null' => false])
            ->addColumn('public_token', 'string', ['limit' => 255, 'null' => false])
            ->addColumn('private_hash', 'string', ['limit' => 255, 'null' => false])
            ->addColumn('expiration_date', 'datetime', ['null' => false])
            ->addForeignKey('uid', 'users', 'id', [
                'delete' => 'CASCADE',
                'constraint' => 'remember_tokens_users_FK',
            ])
            ->create();
    }
}
