<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class CreatePushSubscriptionsTable extends AbstractMigration
{
    public function up(): void
    {
        $table = $this->table('push_subscriptions');
        $table->addColumn('uid', 'integer', ['null' => false, 'signed' => false])
              ->addColumn('endpoint', 'text', ['null' => false])
              ->addColumn('p256dh', 'string', ['limit' => 255, 'null' => false])
              ->addColumn('auth', 'string', ['limit' => 255, 'null' => false])
              ->addColumn('created_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
              ->addForeignKey('uid', 'users', 'id', ['delete'=> 'CASCADE', 'update'=> 'CASCADE'])
              ->addIndex(['endpoint'], ['unique' => false, 'limit' => ['endpoint' => 191]])
              ->create();
    }

    public function down(): void
    {
        $table = $this->table('push_subscriptions');
        $table->drop()->save();
    }
}
