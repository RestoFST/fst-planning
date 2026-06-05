<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class CreateUsersTable extends AbstractMigration
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
        $table = $this->table('users', ['id' => 'id', 'signed' => false]);

        $table
            ->addColumn('name', 'string', ['limit' => 255, 'null' => false])
            ->addColumn('firstname', 'string', ['limit' => 255, 'null' => false])
            ->addColumn('username', 'string', ['limit' => 255, 'null' => false])
            ->addColumn('password', 'string', ['limit' => 255, 'null' => false])
            ->addColumn('role', 'string', ['limit' => 255, 'null' => false])
            ->addColumn('lastModifiedPassword', 'datetime', ['null' => true, 'default' => null])
            ->addColumn('calendarToken', 'string', ['limit' => 255, 'null' => true, 'default' => null])
            ->addIndex(['firstname', 'name'], ['unique' => true, 'name' => 'users_unique'])
            ->addIndex(['calendarToken'], ['unique' => true, 'name' => 'users_unique_1'])
            ->create();
    }
}
