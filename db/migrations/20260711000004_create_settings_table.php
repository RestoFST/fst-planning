<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class CreateSettingsTable extends AbstractMigration
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
        $table = $this->table('settings', ['id' => false, 'primary_key' => ['name']]);

        $table
            ->addColumn('name', 'string', ['limit' => 50, 'null' => false])
            ->addColumn('value', 'string', ['limit' => 255, 'null' => true, 'default' => null])
            ->create();
    }
}
