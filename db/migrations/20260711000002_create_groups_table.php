<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class CreateGroupsTable extends AbstractMigration
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
        $table = $this->table('groups', ['id' => 'id', 'signed' => false]);

        $table
            ->addColumn('name', 'string', ['limit' => 255, 'null' => false])
            ->addColumn('description', 'string', ['limit' => 255, 'null' => true, 'default' => null])
            ->addIndex(['name'], ['unique' => true, 'name' => 'name'])
            ->create();
    }
}
