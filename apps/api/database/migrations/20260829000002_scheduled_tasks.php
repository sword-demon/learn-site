<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * 004-admin-crontab-tasks — scheduled task config + execution logs.
 */
final class ScheduledTasks extends AbstractMigration
{
    public function up(): void
    {
        if (!$this->hasTable('scheduled_tasks')) {
            $this->table('scheduled_tasks', ['id' => false, 'primary_key' => ['id']])
                ->addColumn('id', 'biginteger', ['identity' => true, 'signed' => false])
                ->addColumn('handler_code', 'string', ['limit' => 64])
                ->addColumn('name', 'string', ['limit' => 120])
                ->addColumn('description', 'string', ['limit' => 500, 'null' => true])
                ->addColumn('schedule_expression', 'string', ['limit' => 128])
                ->addColumn('enabled', 'boolean', ['default' => true])
                ->addColumn('params_json', 'json', ['null' => true])
                ->addColumn('last_run_at', 'timestamp', ['null' => true])
                ->addColumn('last_run_status', 'enum', [
                    'values' => ['success', 'failed', 'skipped'],
                    'null' => true,
                ])
                ->addColumn('created_at', 'timestamp', ['default' => 'CURRENT_TIMESTAMP'])
                ->addColumn('updated_at', 'timestamp', [
                    'default' => 'CURRENT_TIMESTAMP',
                    'update' => 'CURRENT_TIMESTAMP',
                ])
                ->addIndex(['handler_code'], ['unique' => true])
                ->addIndex(['enabled'])
                ->addIndex(['updated_at'])
                ->create();
        }

        if (!$this->hasTable('scheduled_task_runs')) {
            $this->table('scheduled_task_runs', ['id' => false, 'primary_key' => ['id']])
                ->addColumn('id', 'biginteger', ['identity' => true, 'signed' => false])
                ->addColumn('task_id', 'biginteger', ['signed' => false])
                ->addColumn('trigger_type', 'enum', ['values' => ['schedule', 'manual']])
                ->addColumn('status', 'enum', ['values' => ['success', 'failed', 'skipped']])
                ->addColumn('started_at', 'timestamp', ['default' => 'CURRENT_TIMESTAMP'])
                ->addColumn('finished_at', 'timestamp', ['null' => true])
                ->addColumn('duration_ms', 'integer', ['signed' => false, 'null' => true])
                ->addColumn('error_message', 'string', ['limit' => 2000, 'null' => true])
                ->addColumn('context_json', 'json', ['null' => true])
                ->addColumn('actor_staff_id', 'biginteger', ['signed' => false, 'null' => true])
                ->addIndex(['task_id', 'started_at'])
                ->addIndex(['status', 'started_at'])
                ->addIndex(['started_at'])
                ->addForeignKey('task_id', 'scheduled_tasks', 'id', [
                    'delete' => 'CASCADE',
                    'update' => 'RESTRICT',
                    'constraint' => 'fk_scheduled_task_runs_task',
                ])
                ->addForeignKey('actor_staff_id', 'accounts', 'id', [
                    'delete' => 'SET_NULL',
                    'update' => 'RESTRICT',
                    'constraint' => 'fk_scheduled_task_runs_actor',
                ])
                ->create();
        }
    }

    public function down(): void
    {
        if ($this->hasTable('scheduled_task_runs')) {
            $this->table('scheduled_task_runs')->drop()->save();
        }
        if ($this->hasTable('scheduled_tasks')) {
            $this->table('scheduled_tasks')->drop()->save();
        }
    }
}
