<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class CreateServicesWorkdaysTable extends AbstractMigration
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
        $table = $this->table('services_workdays', ['id' => 'id', 'signed' => false]);

        $table
            ->addColumn('sid', 'integer', ['signed' => false, 'null' => false])
            ->addColumn('workday', 'integer', ['signed' => false, 'null' => false])
            ->addColumn('start_time', 'time', ['null' => true, 'default' => null])
            ->addColumn('end_time', 'time', ['null' => true, 'default' => null])
            ->addIndex('sid', ['name' => 'services_workdays_services_FK'])
            ->addForeignKey('sid', 'services', 'id', [
                'delete' => 'CASCADE',
                'constraint' => 'services_workdays_services_FK',
            ])
            ->create();
    }
}
