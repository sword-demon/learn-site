<?php
declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class CreateOpsInboxState extends AbstractMigration
{
    public function up(): void
    {
        if (!$this->hasTable('ops_inbox_state')) {
            $this->table('ops_inbox_state', ['id' => false, 'primary_key' => ['id']])
                ->addColumn('id', 'biginteger', ['identity' => true, 'signed' => false])
                ->addColumn('actor_id', 'biginteger', ['signed' => false])
                ->addColumn('source_type', 'string', ['limit' => 32])
                ->addColumn('source_key', 'string', ['limit' => 64])
                ->addColumn('status', 'enum', ['values' => ['open', 'retrying', 'snoozed', 'assigned', 'resolved'], 'default' => 'open'])
                ->addColumn('assignee_id', 'biginteger', ['signed' => false, 'null' => true])
                ->addColumn('snooze_until', 'datetime', ['null' => true])
                ->addColumn('last_actor_id', 'biginteger', ['signed' => false, 'null' => true])
                ->addColumn('created_at', 'datetime')
                ->addColumn('updated_at', 'datetime')
                ->addIndex(['actor_id', 'source_type', 'source_key'], ['unique' => true, 'name' => 'uk_actor_source'])
                ->addIndex(['status', 'snooze_until'], ['name' => 'idx_status_snooze'])
                ->addIndex(['actor_id', 'status'], ['name' => 'idx_actor_status'])
                ->create();
        }

        // Retry metadata belongs to the existing dispatch record; keeping it there
        // avoids a second queue table and preserves dispatch idempotency.
        if ($this->hasTable('notification_dispatches')) {
            $dispatches = $this->table('notification_dispatches');
            if (!$dispatches->hasColumn('retry_count')) {
                $dispatches->addColumn('retry_count', 'integer', ['signed' => false, 'default' => 0, 'after' => 'fan_out_error']);
            }
            if (!$dispatches->hasColumn('retry_at')) {
                $dispatches->addColumn('retry_at', 'datetime', ['null' => true, 'after' => 'retry_count']);
            }
            $dispatches->update();
        }
    }

    public function down(): void
    {
        if ($this->hasTable('notification_dispatches')) {
            $dispatches = $this->table('notification_dispatches');
            if ($dispatches->hasColumn('retry_at')) $dispatches->removeColumn('retry_at');
            if ($dispatches->hasColumn('retry_count')) $dispatches->removeColumn('retry_count');
            $dispatches->update();
        }
        if ($this->hasTable('ops_inbox_state')) {
            $this->table('ops_inbox_state')->drop()->save();
        }
    }
}
