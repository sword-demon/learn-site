<?php

declare(strict_types=1);

namespace app\process;

use App\queue\QueueNames;
use App\support\Logger;
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

    private ?int $lastSeenMaxId = null;

    public function onWorkerStart(): void
    {
        $this->reload();
        Timer::add(30, function (): void {
            $this->maybeReload();
        });
    }

    private function maybeReload(): void
    {
        // ponytail: rely on max(id) instead of max(updated_at) string equality
        // so back-to-back updates in the same second are still noticed. Cheap
        // query on a small admin-managed table; the heartbeat is 30s.
        try {
            $maxId = (int) Db::name('scheduled_tasks')->max('id');
        } catch (\Throwable $e) {
            // ponytail: heartbeat must never throw — an uncaught exception
            // kills the Timer and we silently stop discovering new tasks.
            Logger::warning('scheduled.reload.heartbeat_failed', ['err' => $e->getMessage()]);
            return;
        }
        if ($maxId === $this->lastSeenMaxId) {
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

        $this->lastSeenMaxId = (int) Db::name('scheduled_tasks')->max('id');
    }
}
