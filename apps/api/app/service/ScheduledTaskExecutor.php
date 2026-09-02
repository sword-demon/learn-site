<?php

declare(strict_types=1);

namespace App\service;

use App\scheduled\ScheduledTaskHandlerRegistry;
use App\support\Logger;
use support\think\Db;

/**
 * Executes scheduled tasks and writes run logs.
 */
final class ScheduledTaskExecutor
{
    public function __construct(
        private readonly ScheduledTaskHandlerRegistry $registry = new ScheduledTaskHandlerRegistry(),
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function run(int $taskId, string $triggerType, ?int $actorStaffId = null): array
    {
        $task = Db::name('scheduled_tasks')->where('id', $taskId)->find();
        if (!is_array($task)) {
            throw new BusinessException('NOT_FOUND', 'TASK_NOT_FOUND');
        }

        $handler = $this->registry->get((string) $task['handler_code']);
        if ($handler === null) {
            throw new BusinessException('VALIDATION_FAILED', 'HANDLER_UNAVAILABLE');
        }

        if ($this->hasActiveRun($taskId)) {
            if ($triggerType === 'schedule') {
                return $this->recordSkipped($taskId, $triggerType, '上次执行尚未结束');
            }
            throw new BusinessException('CONFLICT', 'TASK_ALREADY_RUNNING');
        }

        $params = $this->decodeParams($task['params_json']);
        $params = $handler->normalizeParams($params);
        $startedAt = date('Y-m-d H:i:s');
        // ponytail: stage 1 — claim the run as 'running'. If we get SIGKILLed
        // between this insert and the terminal update below, the row stays
        // open; SIGKILL zombies are rare, but a thrown handler that would
        // also leave finished_at=NULL is the common case — and we close
        // that path by writing the terminal row in its own tx.
        $runId = (int) Db::name('scheduled_task_runs')->insertGetId([
            'task_id' => $taskId,
            'trigger_type' => $triggerType,
            'status' => 'running',
            'started_at' => $startedAt,
            'finished_at' => null,
            'duration_ms' => null,
            'error_message' => null,
            'context_json' => null,
            'actor_staff_id' => $actorStaffId,
        ]);

        $status = 'success';
        $errorMessage = null;
        $context = null;
        $startMs = (int) round(microtime(true) * 1000);

        try {
            $context = $handler->execute($params);
        } catch (\Throwable $exception) {
            $status = 'failed';
            $errorMessage = mb_substr($exception->getMessage(), 0, 2000);
        }

        $durationMs = (int) round(microtime(true) * 1000) - $startMs;
        $finishedAt = date('Y-m-d H:i:s');
        $terminal = [
            'status' => $status,
            'finished_at' => $finishedAt,
            'duration_ms' => $durationMs,
            'error_message' => $errorMessage,
            'context_json' => $context !== null ? json_encode($context, JSON_UNESCAPED_UNICODE) : null,
        ];

        try {
            Db::name('scheduled_task_runs')->where('id', $runId)->update($terminal);
            Db::name('scheduled_tasks')->where('id', $taskId)->update([
                'last_run_at' => $startedAt,
                'last_run_status' => $status,
                'updated_at' => $finishedAt,
            ]);
        } catch (\Throwable $writeError) {
            // ponytail: terminal-write failure is the only path that can
            // leave a zombie running row. Log loudly so operators see it;
            // next cron tick will see the open run and skip with the
            // existing hasActiveRun() guard, surfacing the problem.
            Logger::error('scheduled.run.terminal_write_failed', [
                'run_id' => $runId,
                'task_id' => $taskId,
                'err' => $writeError->getMessage(),
            ]);
            throw $writeError;
        }

        return [
            'run_id' => $runId,
            'task_id' => $taskId,
            'trigger_type' => $triggerType,
            'status' => $status,
            'started_at' => $startedAt,
            'finished_at' => $finishedAt,
            'duration_ms' => $durationMs,
            'error_message' => $errorMessage,
        ];
    }

  private function hasActiveRun(int $taskId): bool
  {
    return Db::name('scheduled_task_runs')
      ->where('task_id', $taskId)
      ->whereNull('finished_at')
      ->count() > 0;
  }

  /**
   * @return array<string, mixed>
   */
  private function recordSkipped(int $taskId, string $triggerType, string $reason): array
  {
    $startedAt = date('Y-m-d H:i:s');
    $runId = (int) Db::name('scheduled_task_runs')->insertGetId([
      'task_id' => $taskId,
      'trigger_type' => $triggerType,
      'status' => 'skipped',
      'started_at' => $startedAt,
      'finished_at' => $startedAt,
      'duration_ms' => 0,
      'error_message' => mb_substr($reason, 0, 2000),
      'context_json' => null,
      'actor_staff_id' => null,
    ]);

    Db::name('scheduled_tasks')->where('id', $taskId)->update([
      'last_run_at' => $startedAt,
      'last_run_status' => 'skipped',
      'updated_at' => $startedAt,
    ]);

    return [
      'run_id' => $runId,
      'task_id' => $taskId,
      'trigger_type' => $triggerType,
      'status' => 'skipped',
      'started_at' => $startedAt,
      'finished_at' => $startedAt,
      'duration_ms' => 0,
      'error_message' => $reason,
    ];
  }

  /**
   * @return array<string, mixed>
   */
  private function decodeParams(?string $json): array
  {
    if ($json === null || $json === '') {
      return [];
    }
    $decoded = json_decode($json, true);
    return is_array($decoded) ? $decoded : [];
  }
}
