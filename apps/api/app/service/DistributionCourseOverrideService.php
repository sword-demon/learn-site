<?php

declare(strict_types=1);

namespace App\service;

use support\think\Db;

use function nowDatetime;
use function toIso8601;

final class DistributionCourseOverrideService
{
    public function __construct(
        private readonly DistributionAuditService $audit = new DistributionAuditService(),
    ) {
    }

    /** @return array{items: list<array<string, mixed>>, total: int, page: int, limit: int} */
    public function list(int $page, int $limit): array
    {
        $page = max(1, $page);
        $limit = max(1, min(100, $limit));
        $total = (int) Db::name('distribution_course_overrides')->count();
        $rows = Db::name('distribution_course_overrides')
            ->order('course_id', 'asc')
            ->page($page, $limit)
            ->select()
            ->toArray();
        return [
            'items' => array_map([$this, 'shape'], $rows),
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
        ];
    }

    /** @param array<string, mixed> $input */
    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function upsert(int $courseId, array $input, int $actorId): array
    {
        $course = Db::name('courses')->where('id', $courseId)->find();
        if (!$course) {
            throw new BusinessException('NOT_FOUND', 'COURSE_NOT_FOUND');
        }
        $now = nowDatetime();
        $row = [
            'course_id' => $courseId,
            'enabled' => !empty($input['enabled']) ? 1 : 0,
            'level1_pct' => $input['level1_pct'] ?? null,
            'level2_pct' => $input['level2_pct'] ?? null,
            'level3_pct' => $input['level3_pct'] ?? null,
            'per_order_cap_cents' => $input['per_order_cap_cents'] ?? null,
            'per_learner_course_cap_cents' => $input['per_learner_course_cap_cents'] ?? null,
            'updated_by_staff_id' => $actorId,
            'updated_at' => $now,
        ];
        $before = Db::name('distribution_course_overrides')->where('course_id', $courseId)->find();
        if ($before) {
            Db::name('distribution_course_overrides')->where('course_id', $courseId)->update($row);
        } else {
            Db::name('distribution_course_overrides')->insert($row);
        }
        $after = Db::name('distribution_course_overrides')->where('course_id', $courseId)->find();
        $this->audit->record(
            'admin',
            $actorId,
            'course.override.update',
            'course_override',
            $courseId,
            is_array($before) ? $before : null,
            is_array($after) ? $after : null,
            null,
        );
        $this->writeAudit($actorId, 'course.override.update', $courseId, [
            'course_id' => $courseId,
            'enabled' => $row['enabled'],
        ]);
        return $this->shape($after ?? $row);
    }

    /** @param array<string, mixed> $payload */
    private function writeAudit(int $actorId, string $action, int $targetId, array $payload): void
    {
        Db::name('audit_log')->insert([
            'actor_id' => $actorId > 0 ? $actorId : null,
            'action' => $action,
            'target_type' => 'distribution',
            'target_id' => $targetId > 0 ? $targetId : null,
            'payload_json' => json_encode($payload, JSON_UNESCAPED_UNICODE),
            'created_at' => nowDatetime(),
        ]);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function shape(array $row): array
    {
        return [
            'course_id' => (int) $row['course_id'],
            'enabled' => (bool) $row['enabled'],
            'level1_pct' => $row['level1_pct'] === null ? null : (float) $row['level1_pct'],
            'level2_pct' => $row['level2_pct'] === null ? null : (float) $row['level2_pct'],
            'level3_pct' => $row['level3_pct'] === null ? null : (float) $row['level3_pct'],
            'per_order_cap_cents' => $row['per_order_cap_cents'] === null ? null : (int) $row['per_order_cap_cents'],
            'per_learner_course_cap_cents' => $row['per_learner_course_cap_cents'] === null ? null : (int) $row['per_learner_course_cap_cents'],
            'updated_at' => toIso8601(strtotime((string) $row['updated_at']) ?: time()),
            'updated_by' => (int) ($row['updated_by_staff_id'] ?? 0),
        ];
    }
}
