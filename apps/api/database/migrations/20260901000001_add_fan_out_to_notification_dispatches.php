<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * 008-api-scale-100k — async fan-out progress on notification_dispatches.
 */
final class AddFanOutToNotificationDispatches extends AbstractMigration
{
    public function up(): void
    {
        $table = $this->table('notification_dispatches');
        if (!$table->hasColumn('fan_out_status')) {
            $table->addColumn('fan_out_status', 'enum', [
                'values' => ['pending', 'running', 'completed', 'failed'],
                'default' => 'completed',
                'null' => false,
                'after' => 'recipient_count',
            ]);
        }
        if (!$table->hasColumn('fan_out_done_count')) {
            $table->addColumn('fan_out_done_count', 'integer', [
                'signed' => false,
                'default' => 0,
                'null' => false,
                'after' => 'fan_out_status',
            ]);
        }
        if (!$table->hasColumn('fan_out_error')) {
            $table->addColumn('fan_out_error', 'string', [
                'limit' => 500,
                'null' => true,
                'after' => 'fan_out_done_count',
            ]);
        }
        if (!$table->hasColumn('fan_out_started_at')) {
            $table->addColumn('fan_out_started_at', 'timestamp', [
                'null' => true,
                'after' => 'fan_out_error',
            ]);
        }
        if (!$table->hasColumn('fan_out_finished_at')) {
            $table->addColumn('fan_out_finished_at', 'timestamp', [
                'null' => true,
                'after' => 'fan_out_started_at',
            ]);
        }
        $table->update();

        $this->execute(
            'UPDATE notification_dispatches SET fan_out_status = \'completed\', '
            . 'fan_out_done_count = recipient_count WHERE fan_out_done_count = 0',
        );

        if ($this->isUsingMysql()) {
            $this->execute(
                'ALTER TABLE notification_dispatches MODIFY fan_out_status '
                . 'ENUM(\'pending\',\'running\',\'completed\',\'failed\') NOT NULL DEFAULT \'pending\'',
            );
        }
    }

    public function down(): void
    {
        $table = $this->table('notification_dispatches');
        foreach (
            [
                'fan_out_finished_at',
                'fan_out_started_at',
                'fan_out_error',
                'fan_out_done_count',
                'fan_out_status',
            ] as $column
        ) {
            if ($table->hasColumn($column)) {
                $table->removeColumn($column);
            }
        }
        $table->update();
    }

    private function isUsingMysql(): bool
    {
        return $this->getAdapter()->getAdapterType() === 'mysql';
    }
}
