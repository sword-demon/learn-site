<?php

declare(strict_types=1);

namespace App\service;

use support\think\Db;

use function nowDatetime;

/**
 * DistributionAuditService — single entry point for every distribution_audit_log
 * write. Routes through `record()` so the actor_type / actor_id / created_at
 * contract is uniform across admin and system actions.
 *
 * Per CLAUDE.md, audit is a "forgettable side-effect" that must not be
 * scattered across controllers. Every write path that mutates distribution
 * state goes through here, then forgets about it.
 */
final class DistributionAuditService
{
    /**
     * @param array<string, mixed>|null $before
     * @param array<string, mixed>|null $after
     * @param string|null $reason
     */
    public function record(
        string $actorType,
        ?int $actorId,
        string $action,
        string $subjectType,
        ?int $subjectId,
        ?array $before = null,
        ?array $after = null,
        ?string $reason = null,
    ): int {
        $now = nowDatetime();
        $beforeJson = $before === null ? null : json_encode($before, JSON_UNESCAPED_UNICODE);
        $afterJson = $after === null ? null : json_encode($after, JSON_UNESCAPED_UNICODE);
        $id = (int) Db::name('distribution_audit_log')->insertGetId([
            'actor_type' => $actorType,
            'actor_id' => $actorId,
            'action' => $action,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'before_json' => $beforeJson,
            'after_json' => $afterJson,
            'reason' => $reason,
            'created_at' => $now,
        ]);
        return $id;
    }

    /**
     * @return array{items: list<array<string, mixed>>, total: int, page: int, limit: int}
     */
    public function list(int $page, int $limit, ?string $action = null, ?string $actorType = null): array
    {
        $page = max(1, $page);
        $limit = max(1, min(200, $limit));
        $totalQ = Db::name('distribution_audit_log');
        $listQ = Db::name('distribution_audit_log');
        if ($action !== null && $action !== '') {
            $totalQ->where('action', $action);
            $listQ->where('action', $action);
        }
        if ($actorType === 'admin' || $actorType === 'system') {
            $totalQ->where('actor_type', $actorType);
            $listQ->where('actor_type', $actorType);
        }
        $total = (int) $totalQ->count();
        $rows = $listQ->order('id', 'desc')->page($page, $limit)->select()->toArray();
        $items = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            $items[] = [
                'id' => (int) $row['id'],
                'actor_type' => (string) $row['actor_type'],
                'actor_id' => $row['actor_id'] !== null ? (int) $row['actor_id'] : null,
                'action' => (string) $row['action'],
                'subject_type' => (string) $row['subject_type'],
                'subject_id' => $row['subject_id'] !== null ? (int) $row['subject_id'] : null,
                'before_json' => $row['before_json'] !== null ? json_decode((string) $row['before_json'], true) : null,
                'after_json' => $row['after_json'] !== null ? json_decode((string) $row['after_json'], true) : null,
                'reason' => $row['reason'] !== null ? (string) $row['reason'] : null,
                'created_at' => (string) $row['created_at'],
            ];
        }
        return [
            'items' => $items,
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
        ];
    }
}