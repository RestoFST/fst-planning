<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class CreateServicesOpeningTable extends AbstractMigration
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
        $table = $this->table('services_opening', ['id' => 'id', 'signed' => false]);

        $table
            ->addColumn('sid', 'integer', ['signed' => false, 'null' => false])
            ->addColumn('date', 'date', ['null' => false])
            ->addColumn('description', 'string', ['limit' => 255, 'null' => true, 'default' => null])
            ->addColumn('start_time', 'time', ['null' => true, 'default' => null])
            ->addColumn('end_time', 'time', ['null' => true, 'default' => null])
            ->addIndex('sid', ['name' => 'services_opening_ibfk_1'])
            ->addForeignKey('sid', 'services', 'id', [
                'delete' => 'CASCADE',
                'constraint' => 'services_opening_ibfk_1',
            ])
            ->create();
    }
}
