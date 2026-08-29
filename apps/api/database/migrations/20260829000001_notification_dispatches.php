<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * 003-admin-notifications — dispatch records + inbox extensions.
 */
final class NotificationDispatches extends AbstractMigration
{
    public function up(): void
    {
        if (!$this->hasTable('notification_dispatches')) {
            $this->table('notification_dispatches', ['id' => false, 'primary_key' => ['id']])
                ->addColumn('id', 'biginteger', ['identity' => true, 'signed' => false])
                ->addColumn('type', 'enum', ['values' => ['announcement', 'internal_message']])
                ->addColumn('title', 'string', ['limit' => 200])
                ->addColumn('body', 'text')
                ->addColumn('sender_staff_id', 'biginteger', ['signed' => false, 'null' => false])
                ->addColumn('recipient_mode', 'enum', ['values' => ['all', 'selected']])
                ->addColumn('recipient_count', 'integer', ['signed' => false, 'default' => 0])
                ->addColumn('created_at', 'timestamp', ['default' => 'CURRENT_TIMESTAMP'])
                ->addIndex(['type', 'created_at'])
                ->addIndex(['sender_staff_id', 'created_at'])
                ->addForeignKey('sender_staff_id', 'accounts', 'id', [
                    'delete' => 'RESTRICT',
                    'update' => 'RESTRICT',
                    'constraint' => 'fk_notification_dispatches_sender',
                ])
                ->create();
        }

        if (!$this->hasTable('notification_dispatch_recipients')) {
            $this->table('notification_dispatch_recipients', ['id' => false, 'primary_key' => ['dispatch_id', 'learner_id']])
                ->addColumn('dispatch_id', 'biginteger', ['signed' => false, 'null' => false])
                ->addColumn('learner_id', 'biginteger', ['signed' => false, 'null' => false])
                ->addForeignKey('dispatch_id', 'notification_dispatches', 'id', [
                    'delete' => 'CASCADE',
                    'update' => 'RESTRICT',
                    'constraint' => 'fk_notification_dispatch_recipients_dispatch',
                ])
                ->addForeignKey('learner_id', 'accounts', 'id', [
                    'delete' => 'CASCADE',
                    'update' => 'RESTRICT',
                    'constraint' => 'fk_notification_dispatch_recipients_learner',
                ])
                ->create();
        }

        $table = $this->table('learner_notifications');
        if (!$table->hasColumn('dispatch_id')) {
            $table->addColumn('dispatch_id', 'biginteger', ['signed' => false, 'null' => true, 'after' => 'kind']);
        }
        $table->update();

        $this->execute(
            'ALTER TABLE learner_notifications MODIFY kind VARCHAR(32) NOT NULL',
        );

        $table = $this->table('learner_notifications');
        if (!$table->hasIndex(['dispatch_id'])) {
            $table->addIndex(['dispatch_id']);
        }
        if (!$table->hasIndex(['created_at'])) {
            $table->addIndex(['created_at']);
        }
        $table->update();

        if (!$table->hasForeignKey('dispatch_id')) {
            $this->table('learner_notifications')
                ->addForeignKey('dispatch_id', 'notification_dispatches', 'id', [
                    'delete' => 'SET_NULL',
                    'update' => 'RESTRICT',
                    'constraint' => 'fk_learner_notifications_dispatch',
                ])
                ->update();
        }
    }

    public function down(): void
    {
        $table = $this->table('learner_notifications');
        if ($table->hasForeignKey('dispatch_id')) {
            $table->dropForeignKey('dispatch_id')->save();
        }
        if ($table->hasColumn('dispatch_id')) {
            $table->removeColumn('dispatch_id')->save();
        }

        if ($this->hasTable('notification_dispatch_recipients')) {
            $this->table('notification_dispatch_recipients')->drop()->save();
        }
        if ($this->hasTable('notification_dispatches')) {
            $this->table('notification_dispatches')->drop()->save();
        }
    }
}
