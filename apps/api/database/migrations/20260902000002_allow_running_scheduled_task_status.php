<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class AllowRunningScheduledTaskStatus extends AbstractMigration
{
    public function up(): void
    {
        $this->execute(
            "ALTER TABLE scheduled_task_runs MODIFY status ENUM('running','success','failed','skipped') NOT NULL",
        );
    }

    public function down(): void
    {
        $this->execute(
            "UPDATE scheduled_task_runs SET status = 'failed', finished_at = COALESCE(finished_at, NOW()) "
            . "WHERE status = 'running'",
        );
        $this->execute(
            "ALTER TABLE scheduled_task_runs MODIFY status ENUM('success','failed','skipped') NOT NULL",
        );
    }
}
