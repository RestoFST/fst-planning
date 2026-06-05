<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class CreateServicesGroupsTable extends AbstractMigration
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
        $table = $this->table('services_groups', ['id' => false, 'primary_key' => ['sid', 'gid']]);

        $table
            ->addColumn('sid', 'integer', ['signed' => false, 'null' => false])
            ->addColumn('gid', 'integer', ['signed' => false, 'null' => false])
            ->addIndex('gid', ['name' => 'fk_services_groups_group'])
            ->addForeignKey('sid', 'services', 'id', [
                'delete' => 'CASCADE',
                'constraint' => 'fk_services_groups_service',
            ])
            ->addForeignKey('gid', 'groups', 'id', [
                'delete' => 'CASCADE',
                'constraint' => 'fk_services_groups_group',
            ])
            ->create();
    }
}
