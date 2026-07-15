<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class CreateServicesOpeningGroupsTable extends AbstractMigration
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
        $table = $this->table('services_opening_groups', ['id' => false, 'primary_key' => ['soid', 'gid']]);

        $table
            ->addColumn('soid', 'integer', ['signed' => false, 'null' => false])
            ->addColumn('gid', 'integer', ['signed' => false, 'null' => false])
            ->addIndex('gid', ['name' => 'gid'])
            ->addForeignKey('soid', 'services_opening', 'id', [
                'delete' => 'CASCADE',
                'constraint' => 'services_opening_groups_ibfk_1',
            ])
            ->addForeignKey('gid', 'groups', 'id', [
                'delete' => 'CASCADE',
                'constraint' => 'services_opening_groups_ibfk_2',
            ])
            ->create();
    }
}
