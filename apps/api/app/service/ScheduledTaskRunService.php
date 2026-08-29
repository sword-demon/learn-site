<?php

declare(strict_types=1);

namespace App\service;

use support\think\Db;

final class ScheduledTaskRunService
{
    /**
     * @param array<string, mixed> $filters
     * @return array{items: list<array<string, mixed>>, total: int, page: int, per_page: int}
     */
    public function list(array $filters): array
    {
        $page = max(1, (int) ($filters['page'] ?? 1));
        $perPage = max(1, min(100, (int) ($filters['per_page'] ?? 20)));

        $query = Db::name('scheduled_task_runs')->alias('r')
            ->leftJoin('scheduled_tasks t', 'r.task_id = t.id')
            ->leftJoin('accounts a', 'r.actor_staff_id = a.id');

        if (($taskId = (int) ($filters['task_id'] ?? 0)) > 0) {
            $query->where('r.task_id', $taskId);
        }
        $status = (string) ($filters['status'] ?? '');
        if ($status !== '') {
            $query->where('r.status', $status);
        }
        $triggerType = (string) ($filters['trigger_type'] ?? '');
        if ($triggerType !== '') {
            $query->where('r.trigger_type', $triggerType);
        }
        $startedFrom = (string) ($filters['started_from'] ?? '');
        if ($startedFrom !== '') {
            $query->where('r.started_at', '>=', $this->normalizeDateStart($startedFrom));
        }
        $startedTo = (string) ($filters['started_to'] ?? '');
        if ($startedTo !== '') {
            $query->where('r.started_at', '<=', $this->normalizeDateEnd($startedTo));
        }

        $total = (int) $query->count();
        $rows = $query
            ->field('r.*, t.name as task_name, a.login as actor_login')
            ->order('r.started_at', 'desc')
            ->page($page, $perPage)
            ->select()
            ->toArray();

        $items = [];
        foreach ($rows as $row) {
            $items[] = $this->mapListRow($row);
        }

        return [
            'items' => $items,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function show(int $id): array
    {
        $row = Db::name('scheduled_task_runs')->alias('r')
            ->leftJoin('scheduled_tasks t', 'r.task_id = t.id')
            ->leftJoin('accounts a', 'r.actor_staff_id = a.id')
            ->field('r.*, t.name as task_name, a.login as actor_login')
            ->where('r.id', $id)
            ->find();

        if (!is_array($row)) {
            throw new BusinessException('NOT_FOUND', 'RUN_NOT_FOUND');
        }

        return $this->mapDetailRow($row);
    }

    /**
     * @param array<string, mixed> $row
   * @return array<string, mixed>
   */
  private function mapListRow(array $row): array
  {
    return [
      'id' => (int) $row['id'],
      'task_id' => (int) $row['task_id'],
      'task_name' => (string) ($row['task_name'] ?? ''),
      'trigger_type' => (string) $row['trigger_type'],
      'status' => (string) $row['status'],
      'started_at' => (string) $row['started_at'],
      'finished_at' => $row['finished_at'] !== null ? (string) $row['finished_at'] : null,
      'duration_ms' => $row['duration_ms'] !== null ? (int) $row['duration_ms'] : null,
      'error_message' => $row['error_message'] !== null ? (string) $row['error_message'] : null,
      'actor_staff_id' => $row['actor_staff_id'] !== null ? (int) $row['actor_staff_id'] : null,
      'actor_login' => $row['actor_login'] !== null ? (string) $row['actor_login'] : null,
    ];
  }

  /**
   * @param array<string, mixed> $row
   * @return array<string, mixed>
   */
  private function mapDetailRow(array $row): array
  {
    $detail = $this->mapListRow($row);
    $context = null;
    if ($row['context_json'] !== null && $row['context_json'] !== '') {
      $decoded = json_decode((string) $row['context_json'], true);
      $context = is_array($decoded) ? $decoded : null;
    }
    $detail['context'] = $context;
    return $detail;
  }

  private function normalizeDateStart(string $value): string
  {
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
      return $value . ' 00:00:00';
    }
    return $value;
  }

  private function normalizeDateEnd(string $value): string
  {
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
      return $value . ' 23:59:59';
    }
    return $value;
  }
}
