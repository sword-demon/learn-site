<?php

declare(strict_types=1);

namespace App\queue\redis;

use App\service\ScheduledTaskExecutor;
use Webman\RedisQueue\Consumer;

final class ScheduledTaskConsumer implements Consumer
{
    public string $queue = 'scheduled.task';

    public string $connection = 'default';

    public function consume(mixed $data): void
    {
        if (!is_array($data)) {
            return;
        }
        $taskId = (int) ($data['task_id'] ?? 0);
        if ($taskId <= 0) {
            return;
        }
        $triggerType = (string) ($data['trigger_type'] ?? 'schedule');
        $actorStaffId = isset($data['actor_staff_id']) ? (int) $data['actor_staff_id'] : null;
        if ($actorStaffId !== null && $actorStaffId <= 0) {
            $actorStaffId = null;
        }
        (new ScheduledTaskExecutor())->run($taskId, $triggerType, $actorStaffId);
    }
}
