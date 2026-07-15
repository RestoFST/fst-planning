<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class CreatePivotTables extends AbstractMigration
{
    /**
     * Migrate Up.
     */
    public function up(): void
    {
        // Table pivot appointments_users
        $appointmentsUsers = $this->table('appointments_users', ['id' => false, 'primary_key' => ['aid', 'uid']]);

        $appointmentsUsers
            ->addColumn('aid', 'integer', ['signed' => false, 'null' => false])
            ->addColumn('uid', 'integer', ['signed' => false, 'null' => false])
            ->addColumn('presence', 'string', ['limit' => 255, 'null' => false, 'default' => 'en_attente'])
            ->addColumn('pointed', 'integer', ['signed' => false, 'null' => false, 'default' => 0])
            ->addIndex('uid', ['name' => 'appointments_users_users_FK'])
            ->addForeignKey('aid', 'appointment', 'id', [
                'delete' => 'CASCADE',
                'constraint' => 'appointments_users_appointment_FK',
            ])
            ->addForeignKey('uid', 'users', 'id', [
                'delete' => 'CASCADE',
                'constraint' => 'appointments_users_users_FK',
            ])
            ->create();

        // Table pivot users_groups
        $usersGroups = $this->table('users_groups', ['id' => false, 'primary_key' => ['uid', 'gid']]);

        $usersGroups
            ->addColumn('uid', 'integer', ['signed' => false, 'null' => false])
            ->addColumn('gid', 'integer', ['signed' => false, 'null' => false])
            ->addIndex('gid', ['name' => 'fk_users_groups_group'])
            ->addForeignKey('uid', 'users', 'id', [
                'delete' => 'CASCADE',
                'constraint' => 'fk_users_groups_user',
            ])
            ->addForeignKey('gid', 'groups', 'id', [
                'delete' => 'CASCADE',
                'constraint' => 'fk_users_groups_group',
            ])
            ->create();
    }

    /**
     * Migrate Down.
     */
    public function down(): void
    {
        $this->table('users_groups')->drop()->save();
        $this->table('appointments_users')->drop()->save();
    }
}
