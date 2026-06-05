<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class AddActiveToUsers extends AbstractMigration
{
    public function up(): void
    {
        $table = $this->table('users');
        $table->addColumn('active', 'boolean', ['default' => true, 'null' => false])
              ->update();
    }

    public function down(): void
    {
        $table = $this->table('users');
        $table->removeColumn('active')
              ->update();
    }
}
