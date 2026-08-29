<?php

declare(strict_types=1);

namespace App\service;

use App\scheduled\ScheduledTaskHandlerRegistry;
use support\think\Db;

final class ScheduledTaskService
{
    public function __construct(
        private readonly ScheduleExpressionService $expressions = new ScheduleExpressionService(),
        private readonly ScheduledTaskHandlerRegistry $registry = new ScheduledTaskHandlerRegistry(),
        private readonly ScheduledTaskExecutor $executor = new ScheduledTaskExecutor(),
    ) {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function list(): array
    {
        $rows = Db::name('scheduled_tasks')->order('id', 'asc')->select()->toArray();
        $out = [];
        foreach ($rows as $row) {
            $out[] = $this->mapTask($row);
        }
        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    public function show(int $id): array
    {
        $row = Db::name('scheduled_tasks')->where('id', $id)->find();
        if (!is_array($row)) {
            throw new BusinessException('NOT_FOUND', 'TASK_NOT_FOUND');
        }
        return $this->mapTask($row);
    }

    /**
     * @return array<string, mixed>
     */
    public function validateExpression(string $expression): array
    {
        return $this->expressions->preview($expression);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function update(int $id, int $staffId, array $payload): array
    {
        $row = Db::name('scheduled_tasks')->where('id', $id)->find();
        if (!is_array($row)) {
            throw new BusinessException('NOT_FOUND', 'TASK_NOT_FOUND');
        }

        $handlerCode = (string) $row['handler_code'];
        $handler = $this->registry->get($handlerCode);
        $handlerAvailable = $handler !== null;

        $updates = [];
        if (isset($payload['schedule_expression'])) {
            $expression = trim((string) $payload['schedule_expression']);
            $this->expressions->validateForSave($expression);
            $updates['schedule_expression'] = $expression;
        }
        if (isset($payload['params']) && is_array($payload['params'])) {
            if (!$handlerAvailable) {
                throw new BusinessException('VALIDATION_FAILED', 'HANDLER_UNAVAILABLE');
            }
            $updates['params_json'] = json_encode(
                $handler->normalizeParams($payload['params']),
                JSON_UNESCAPED_UNICODE,
            );
        }
        if (isset($payload['enabled'])) {
            $enabled = (bool) $payload['enabled'];
            if ($enabled && !$handlerAvailable) {
                throw new BusinessException('VALIDATION_FAILED', 'HANDLER_UNAVAILABLE');
            }
            $updates['enabled'] = $enabled ? 1 : 0;
        }

        if ($updates === []) {
            throw new BusinessException('VALIDATION_FAILED', 'EMPTY_UPDATE');
        }

        $updates['updated_at'] = date('Y-m-d H:i:s');
        Db::name('scheduled_tasks')->where('id', $id)->update($updates);

        Db::name('audit_log')->insert([
            'actor_id' => $staffId,
            'action' => 'scheduled_task.update',
            'target_type' => 'scheduled_task',
            'target_id' => $id,
            'payload_json' => json_encode($updates, JSON_UNESCAPED_UNICODE),
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        $fresh = Db::name('scheduled_tasks')->where('id', $id)->find();
        return $this->mapTask(is_array($fresh) ? $fresh : $row);
    }

    /**
     * @return array<string, mixed>
     */
    public function runNow(int $taskId, int $staffId): array
    {
        Db::name('audit_log')->insert([
            'actor_id' => $staffId,
            'action' => 'scheduled_task.run',
            'target_type' => 'scheduled_task',
            'target_id' => $taskId,
            'payload_json' => json_encode(['trigger' => 'manual'], JSON_UNESCAPED_UNICODE),
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        return $this->executor->run($taskId, 'manual', $staffId);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function mapTask(array $row): array
    {
        $handlerCode = (string) $row['handler_code'];
        $available = $this->registry->has($handlerCode);
        $expression = (string) $row['schedule_expression'];
        $nextRun = null;
        if ($available && (int) $row['enabled'] === 1 && $this->expressions->isValid($expression)) {
            $ts = $this->expressions->nextRunAt($expression);
            $nextRun = $ts !== null ? date('Y-m-d H:i:s', $ts) : null;
        }

        $params = null;
        if ($row['params_json'] !== null && $row['params_json'] !== '') {
            $decoded = json_decode((string) $row['params_json'], true);
            $params = is_array($decoded) ? $decoded : null;
        }

        return [
            'id' => (int) $row['id'],
            'handler_code' => $handlerCode,
            'name' => (string) $row['name'],
            'description' => $row['description'] !== null ? (string) $row['description'] : null,
            'schedule_expression' => $expression,
            'enabled' => (int) $row['enabled'] === 1,
            'params' => $params,
            'handler_status' => $available ? 'available' : 'unavailable',
            'last_run_at' => $row['last_run_at'] !== null ? (string) $row['last_run_at'] : null,
            'last_run_status' => $row['last_run_status'] !== null ? (string) $row['last_run_status'] : null,
            'next_run_at' => $nextRun,
            'updated_at' => (string) $row['updated_at'],
        ];
    }
}
