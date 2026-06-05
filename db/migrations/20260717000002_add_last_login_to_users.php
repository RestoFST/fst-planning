<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class AddLastLoginToUsers extends AbstractMigration
{
    public function up(): void
    {
        $table = $this->table('users');
        $table->addColumn('last_login', 'datetime', ['null' => true, 'default' => null])
              ->update();
    }

    public function down(): void
    {
        $table = $this->table('users');
        $table->removeColumn('last_login')
              ->update();
    }
}
