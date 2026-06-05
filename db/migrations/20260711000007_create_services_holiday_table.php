<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class CreateServicesHolidayTable extends AbstractMigration
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
        $table = $this->table('services_holiday', ['id' => 'id', 'signed' => false]);

        $table
            ->addColumn('sid', 'integer', ['signed' => false, 'null' => true, 'default' => null])
            ->addColumn('name', 'string', ['limit' => 255, 'null' => false])
            ->addColumn('start_date', 'date', ['null' => false])
            ->addColumn('end_date', 'date', ['null' => false])
            ->addIndex('sid', ['name' => 'fk_services_holiday_sid'])
            ->addForeignKey('sid', 'services', 'id', [
                'delete' => 'CASCADE',
                'constraint' => 'fk_services_holiday_sid',
            ])
            ->create();
    }
}
