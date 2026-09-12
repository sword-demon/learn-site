<?php

declare(strict_types=1);

namespace App\service;

use App\model\DistributionCourseOverride as CourseOverrideModel;
use App\support\DistributionConfigValidator;
use support\think\Db;

/**
 * DistributionCourseOverrideService
 * 
 * T032: Manage course-level distribution overrides
 * 
 * Core Responsibilities:
 * - upsert(): Create/update override with audit
 * - list(): Paginated list of all course overrides
 */
final class DistributionCourseOverrideService
{
    public function __construct(
        private readonly DistributionAuditService $audit = new DistributionAuditService(),
    ) {}

    /**
     * Upsert a course-level override
     * Called by admin with distribution.config permission
     * 
     * @param int $courseId Target course ID
     * @param array<string, mixed> $input From request body
     * @param int $actorId Admin account_id who made the change
     * @return array<string, mixed> Updated override record
     */
    public function upsert(int $courseId, array $input, int $actorId): array
    {
        $course = \App\model\Course::find($courseId);
        if (!$course) { throw new BusinessException('NOT_FOUND', 'COURSE_NOT_FOUND'); }
        $enabled = (bool) ($input['enabled'] ?? false);

        DistributionConfigValidator::assertValidConfig(array_merge([
            // Absent per-level ratios mean "fall back to the global config"
            // (effectiveConfig() in CommissionService); validate them as 0 so
            // a partial course override passes the ratio / cap guards.
            'level_cap' => 1, 'payout_form' => 'cash_record_only', 'settlement' => 'order_settled_after_refund_window', 'refund_void_rule' => 'void_all',
            'enabled' => $enabled, 'learner_can_view_detail' => true, 'level1_pct' => 0, 'level2_pct' => 0, 'level3_pct' => 0,
            'per_order_cap_cents' => (int)($input['per_order_cap_cents'] ?? 0),
            'per_learner_course_cap_cents' => (int)($input['per_learner_course_cap_cents'] ?? 0),
        ], $input));

        // Get current config (or create new)
        $existing = CourseOverrideModel::find($courseId);

        $before = null;
        if ($existing) {
            $before = [
                'enabled' => $existing->enabled,
                'level1_pct' => $existing->level1_pct,
                'level2_pct' => $existing->level2_pct,
                'level3_pct' => $existing->level3_pct,
                'per_order_cap_cents' => $existing->per_order_cap_cents,
                'updated_at' => $existing->updated_at,
            ];
        }

        // Prepare data for upsert
        $data = [
            'course_id' => $courseId,
            'enabled' => $enabled,
            'level1_pct' => isset($input['level1_pct']) ? floatval($input['level1_pct']) : null,
            'level2_pct' => isset($input['level2_pct']) ? floatval($input['level2_pct']) : null,
            'level3_pct' => isset($input['level3_pct']) ? floatval($input['level3_pct']) : null,
            'per_order_cap_cents' => isset($input['per_order_cap_cents']) ? intval($input['per_order_cap_cents']) : null,
            'per_learner_course_cap_cents' => isset($input['per_learner_course_cap_cents']) ? intval($input['per_learner_course_cap_cents']) : 0,
            'updated_by_staff_id' => $actorId,
            'updated_at' => nowDatetime(),
        ];

        // Update or create
        if ($existing) {
            // save() applies attrs to the same instance; Model::update() is
            // static semantics and returns a fresh row we'd never see.
            $existing->save($data);
            $override = $existing;
        } else {
            $override = CourseOverrideModel::create($data);
        }

        // Audit log after successful save
        $after = [
            'enabled' => $override->enabled,
            'level1_pct' => $override->level1_pct,
            'level2_pct' => $override->level2_pct,
            'level3_pct' => $override->level3_pct,
            'per_order_cap_cents' => $override->per_order_cap_cents,
            'updated_at' => $override->updated_at,
        ];

        $this->audit->record(
            'admin', // actorType
            $actorId, // actorId
            'course.override.update', // action
            'course_override', // subjectType
            $courseId, // subjectId
            $before, // before
            $after, // after
            $input['reason'] ?? null, // reason
        );

        return [
            'course_id' => $override->course_id,
            'enabled' => $override->enabled,
            'level1_pct' => $override->level1_pct,
            'level2_pct' => $override->level2_pct,
            'level3_pct' => $override->level3_pct,
            'per_order_cap_cents' => $override->per_order_cap_cents,
            'per_learner_course_cap_cents' => $override->per_learner_course_cap_cents,
            'updated_by' => (int) $override->updated_by_staff_id,
            'updated_at' => $override->updated_at,
        ];
    }

    /**
     * List all course overrides with pagination
     * Used by admin dashboard to show all covered courses
     *
     * @return array{items: list<array<string, mixed>>, total: int, page: int, limit: int}
     */
    public function list(int $page = 1, int $limit = 20): array
    {
        $page = max(1, $page); $limit = max(1, min(100, $limit));

        $rows = Db::name('distribution_course_overrides')
            ->where('course_id', '>', 0)
            ->order('updated_at', 'desc')
            ->page($page, $limit)
            ->select()
            ->toArray();

        $total = (int) Db::name('distribution_course_overrides')
            ->where('course_id', '>', 0)
            ->count();

        $result = [];
        foreach ($rows as $row) {
            // Fetch course details for display
            $course = \App\model\Course::find((int) $row['course_id']);

            $result[] = [
                'course_id' => (int) $row['course_id'],
                'course_name' => $course?->title ?? 'Deleted Course',
                'enabled' => (bool) $row['enabled'],
                'level1_pct' => $row['level1_pct'] ?? 'Use global',
                'level2_pct' => $row['level2_pct'] ?? 'Use global',
                'level3_pct' => $row['level3_pct'] ?? 'Use global',
                'per_order_cap_cents' => $row['per_order_cap_cents'] ?? 'Use global',
                'updated_by' => isset($row['updated_by_staff_id']) ? (int) $row['updated_by_staff_id'] : null,
                'updated_at' => $row['updated_at'],
            ];
        }

        return [
            'items' => $result,
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
        ];
    }

    /**
     * Get single course override
     *
     * @return array<string, mixed>|null
     */
    public function get(int $courseId): ?array
    {
        $override = CourseOverrideModel::find($courseId);

        if (!$override) {
            return null;
        }

        return [
            'course_id' => $override->course_id,
            'enabled' => $override->enabled,
            'level1_pct' => $override->level1_pct,
            'level2_pct' => $override->level2_pct,
            'level3_pct' => $override->level3_pct,
            'per_order_cap_cents' => $override->per_order_cap_cents,
            'per_learner_course_cap_cents' => $override->per_learner_course_cap_cents,
            'updated_by' => (int) $override->updated_by_staff_id,
            'updated_at' => $override->updated_at,
        ];
    }
}
