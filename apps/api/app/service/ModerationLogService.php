<?php

declare(strict_types=1);

namespace App\service;

use support\think\Db;

final class ModerationLogService
{
    public function __construct(private readonly DataScopeService $scope)
    {
    }

    /** @return array{items:list<array<string,mixed>>,total:int,page:int,limit:int} */
    public function list(
        int $staffId,
        ?string $objectType,
        ?string $action,
        ?int $actorStaffId,
        int $page,
        int $pageSize,
    ): array {
        if ($staffId <= 0) {
            throw new BusinessException('UNAUTHENTICATED', 'UNAUTHENTICATED');
        }
        if ($objectType !== null && !in_array($objectType, ['review', 'reply'], true)) {
            throw new BusinessException('VALIDATION_FAILED', 'INVALID_OBJECT_TYPE');
        }
        if ($action !== null && !in_array($action, ['hide', 'restore'], true)) {
            throw new BusinessException('VALIDATION_FAILED', 'INVALID_ACTION');
        }
        $page = max(1, $page);
        $pageSize = max(1, min(100, $pageSize));
        $query = Db::name('moderation_logs')
            ->alias('ml')
            ->leftJoin('review_replies rr', "ml.object_type = 'reply' AND rr.id = ml.object_id")
            ->leftJoin(
                'reviews r',
                "r.id = CASE WHEN ml.object_type = 'review' THEN ml.object_id ELSE rr.review_id END",
            )
            ->leftJoin('courses c', 'c.id = r.course_id')
            ->leftJoin('accounts a', 'a.id = ml.staff_id')
            ->field(
                'ml.id, ml.object_type, ml.object_id, ml.action, ml.reason, '
                . 'ml.staff_id, ml.created_at, a.login AS staff_login, '
                . "CASE WHEN ml.action = 'hide' "
                . 'AND ml.id = (SELECT MAX(latest.id) FROM moderation_logs latest '
                . 'WHERE latest.object_type = ml.object_type AND latest.object_id = ml.object_id) '
                . "AND ((ml.object_type = 'review' AND r.visibility = 'hidden') "
                . "OR (ml.object_type = 'reply' AND rr.visibility = 'hidden')) "
                . 'THEN 1 ELSE 0 END AS restorable',
            );
        if ($objectType !== null) {
            $query->where('ml.object_type', $objectType);
        }
        if ($action !== null) {
            $query->where('ml.action', $action);
        }
        if ($actorStaffId !== null && $actorStaffId > 0) {
            $query->where('ml.staff_id', $actorStaffId);
        }
        $resolvedScope = $this->scope->resolveForCourses($staffId);
        if (!$resolvedScope['all']) {
            if ($resolvedScope['department_ids'] === [] && !$resolvedScope['include_self']) {
                $query->where('c.id', 0);
            } else {
                $query->where(function ($where) use ($resolvedScope, $staffId): void {
                    if ($resolvedScope['department_ids'] !== []) {
                        $where->where('c.department_id', 'in', $resolvedScope['department_ids']);
                    }
                    if ($resolvedScope['include_self']) {
                        if ($resolvedScope['department_ids'] !== []) {
                            $where->whereOr('c.created_by_staff_id', $staffId);
                        } else {
                            $where->where('c.created_by_staff_id', $staffId);
                        }
                    }
                });
            }
        }

        $total = (int) (clone $query)->count();
        $rows = $query->order('ml.id', 'desc')->page($page, $pageSize)->select()->toArray();
        $items = array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'object_type' => (string) $row['object_type'],
            'object_id' => (int) $row['object_id'],
            'action' => (string) $row['action'],
            'reason' => (string) $row['reason'],
            'staff_id' => (int) $row['staff_id'],
            'staff_login' => (string) ($row['staff_login'] ?? ''),
            'restorable' => (bool) $row['restorable'],
            'created_at' => (string) $row['created_at'],
        ], is_array($rows) ? $rows : []);

        return [
            'items' => $items,
            'total' => $total,
            'page' => $page,
            'limit' => $pageSize,
        ];
    }
}
