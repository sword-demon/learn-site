<?php

declare(strict_types=1);

namespace app\process;

use App\queue\QueueNames;
use App\support\queue\JobDispatcher;
use support\think\Db;
use Workerman\Crontab\Crontab;
use Workerman\Timer;

/**
 * Loads scheduled tasks from DB and registers workerman/crontab instances.
 */
final class ScheduledTaskRunner
{
    /** @var array<int, Crontab> */
    private array $crontabs = [];

    private ?string $lastSeenUpdatedAt = null;

    public function onWorkerStart(): void
    {
        $this->reload();
        Timer::add(30, function (): void {
            $this->maybeReload();
        });
    }

    private function maybeReload(): void
    {
        $maxUpdated = Db::name('scheduled_tasks')->max('updated_at');
        $maxUpdated = $maxUpdated !== null ? (string) $maxUpdated : null;
        if ($maxUpdated === $this->lastSeenUpdatedAt) {
            return;
        }
        $this->reload();
    }

    private function reload(): void
    {
        foreach ($this->crontabs as $crontab) {
            $crontab->destroy();
        }
        $this->crontabs = [];

        $rows = Db::name('scheduled_tasks')
            ->where('enabled', 1)
            ->order('id', 'asc')
            ->select()
            ->toArray();

        $jobs = new JobDispatcher();
        foreach ($rows as $row) {
            $taskId = (int) $row['id'];
            $expression = (string) $row['schedule_expression'];
            $this->crontabs[$taskId] = new Crontab($expression, function () use ($jobs, $taskId): void {
                $jobs->dispatch(QueueNames::SCHEDULED_TASK, [
                    'task_id' => $taskId,
                    'trigger_type' => 'schedule',
                    'actor_staff_id' => null,
                ]);
            });
        }

        $maxUpdated = Db::name('scheduled_tasks')->max('updated_at');
        $this->lastSeenUpdatedAt = $maxUpdated !== null ? (string) $maxUpdated : null;
    }
}
