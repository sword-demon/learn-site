<?php

declare(strict_types=1);

namespace Tests;

use support\think\Db;

/**
 * Small deterministic fixtures shared by the learning action loop tests.
 * Production code must never depend on this class.
 */
final class LearningActionLoopFixtures
{
    public static function now(): string
    {
        return '2026-09-04 10:00:00';
    }

    public static function learner(string $login = '13900000001'): int
    {
        $now = self::now();
        $accountId = (int) Db::name('accounts')->insertGetId([
            'kind' => 'learner',
            'login' => $login . random_int(100, 999),
            'password_hash' => 'fixture',
            'must_change_password' => 0,
            'status' => 'active',
            'last_login_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        Db::name('learners')->insert([
            'account_id' => $accountId,
            'nickname' => '行动循环学员',
            'avatar_url' => null,
            'show_on_course' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        return $accountId;
    }

    public static function staff(string $login = 'fixture-staff'): int
    {
        $now = self::now();
        $accountId = (int) Db::name('accounts')->insertGetId([
            'kind' => 'staff',
            'login' => $login . random_int(100, 999),
            'password_hash' => 'fixture',
            'must_change_password' => 0,
            'status' => 'active',
            'last_login_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $departmentId = (int) Db::name('departments')->insertGetId([
            'parent_id' => null,
            'name' => '行动循环部门' . $accountId,
            'path' => '/',
            'depth' => 1,
            'sort' => 0,
            'status' => 'enabled',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        Db::name('staff_users')->insert([
            'account_id' => $accountId,
            'is_super_admin' => 1,
            'department_id' => $departmentId,
            'display_name' => '测试员工',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        return $accountId;
    }

    public static function course(int $staffId, string $title = '行动循环课程', string $status = 'published'): int
    {
        $now = self::now();
        $departmentId = (int) Db::name('staff_users')->where('account_id', $staffId)->value('department_id');
        $categoryId = (int) Db::name('categories')->insertGetId([
            'parent_id' => 0,
            'name' => '行动循环分类' . random_int(1000, 9999),
            'path' => '/',
            'depth' => 1,
            'sort' => 0,
            'status' => 'enabled',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        return (int) Db::name('courses')->insertGetId([
            'department_id' => $departmentId,
            'category_id' => $categoryId,
            'title' => $title,
            'cover_url' => null,
            'teacher_name' => '测试教师',
            'summary' => '测试课程',
            'intro_rich_text' => null,
            'status' => $status,
            'price_mode' => 'free',
            'list_price' => 0,
            'sale_price' => 0,
            'sale_start_at' => null,
            'sale_end_at' => null,
            'created_by_staff_id' => $staffId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public static function lesson(int $courseId, string $title = '行动循环课节', int $sort = 0): int
    {
        $now = self::now();
        $chapterId = (int) Db::name('chapters')->insertGetId([
            'course_id' => $courseId,
            'title' => '第一章',
            'sort' => $sort,
            'status' => 'enabled',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        return (int) Db::name('lessons')->insertGetId([
            'chapter_id' => $chapterId,
            'title' => $title,
            'sort' => $sort,
            'status' => 'enabled',
            'content_type' => 'markdown',
            'body_markdown' => '# 测试课节',
            'asset_id' => null,
            'is_preview' => 0,
            'duration_seconds' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public static function grant(int $learnerId, int $courseId): int
    {
        $now = self::now();
        return (int) Db::name('course_entitlements')->insertGetId([
            'learner_id' => $learnerId,
            'course_id' => $courseId,
            'source' => 'free',
            'order_id' => null,
            'status' => 'active',
            'revoked_at' => null,
            'revoked_reason' => null,
            'revoked_by_staff_id' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public static function enroll(int $learnerId, int $courseId, ?int $lastLessonId = null): int
    {
        $now = self::now();
        return (int) Db::name('course_enrollments')->insertGetId([
            'learner_id' => $learnerId,
            'course_id' => $courseId,
            'progress_percent' => 0,
            'last_lesson_id' => $lastLessonId,
            'last_position' => 0,
            'completed_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public static function favorite(int $learnerId, int $courseId, string $createdAt = '2026-09-03 10:00:00'): int
    {
        return (int) Db::name('favorites')->insertGetId([
            'learner_id' => $learnerId,
            'course_id' => $courseId,
            'created_at' => $createdAt,
        ]);
    }

    public static function progress(int $learnerId, int $lessonId, string $updatedAt, bool $completed = false): int
    {
        return (int) Db::name('lesson_progresses')->insertGetId([
            'learner_id' => $learnerId,
            'lesson_id' => $lessonId,
            'position_seconds' => $completed ? 1 : 0,
            'completed' => $completed ? 1 : 0,
            'completed_at' => $completed ? $updatedAt : null,
            'created_at' => $updatedAt,
            'updated_at' => $updatedAt,
        ]);
    }

    public static function order(
        int $learnerId,
        int $courseId,
        string $createdAt = '2026-09-04 01:50:00',
        string $status = 'pending',
    ): int {
        return (int) Db::name('orders')->insertGetId([
            'learner_id' => $learnerId,
            'course_id' => $courseId,
            'list_price_snapshot' => 100,
            'sale_price_snapshot' => 100,
            'paid_amount' => 100,
            'currency' => 'CNY',
            'status' => $status,
            'provider' => 'fixture',
            'provider_ref' => null,
            'succeeded_at' => $status === 'succeeded' ? $createdAt : null,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);
    }

    public static function couponCampaign(int $staffId, int $categoryId, string $scope = 'all'): int
    {
        $now = self::now();
        $campaignId = (int) Db::name('coupon_campaigns')->insertGetId([
            'name' => '行动循环优惠券',
            'scope_type' => $scope,
            'min_amount' => 0,
            'discount_amount' => 10,
            'claim_mode' => 'public',
            'claim_starts_at' => '2026-09-01 00:00:00',
            'claim_ends_at' => '2026-09-10 00:00:00',
            'use_ends_at' => '2026-09-07 23:59:00',
            'total_quota' => null,
            'claimed_count' => 1,
            'used_count' => 0,
            'per_learner_claim_limit' => 1,
            'per_learner_use_limit' => 1,
            'status' => 'active',
            'created_by' => $staffId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        if ($scope === 'category') {
            Db::name('coupon_campaign_categories')->insert([
                'campaign_id' => $campaignId,
                'category_id' => $categoryId,
            ]);
        }
        return $campaignId;
    }

    public static function coupon(
        int $learnerId,
        int $campaignId,
        string $expiresAt = '2026-09-07 15:59:00',
        string $status = 'unused',
    ): int {
        $now = self::now();
        return (int) Db::name('learner_coupons')->insertGetId([
            'campaign_id' => $campaignId,
            'learner_id' => $learnerId,
            'status' => $status,
            'source' => 'grant',
            'granted_by' => null,
            'locked_order_id' => null,
            'used_order_id' => null,
            'expires_at' => $expiresAt,
            'locked_at' => null,
            'used_at' => null,
            'created_at' => $now,
        ]);
    }

    /** @param list<int> $courseIds */
    public static function map(int $staffId, int $learnerId, array $courseIds): int
    {
        $now = self::now();
        $departmentId = (int) Db::name('staff_users')->where('account_id', $staffId)->value('department_id');
        $mapId = (int) Db::name('learning_maps')->insertGetId([
            'department_id' => $departmentId,
            'title' => '行动循环地图',
            'summary' => '测试地图',
            'cover_url' => null,
            'objective' => null,
            'audience' => null,
            'status' => 'published',
            'created_by_staff_id' => $staffId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $stageId = (int) Db::name('map_stages')->insertGetId([
            'map_id' => $mapId,
            'title' => '第一阶段',
            'summary' => null,
            'sort_order' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        foreach ($courseIds as $index => $courseId) {
            Db::name('map_stage_courses')->insert([
                'stage_id' => $stageId,
                'map_id' => $mapId,
                'course_id' => $courseId,
                'sort_order' => $index,
                'created_at' => $now,
            ]);
        }
        Db::name('map_enrollments')->insert([
            'map_id' => $mapId,
            'learner_id' => $learnerId,
            'enrolled_at' => $now,
            'completed_courses' => 0,
            'total_courses' => count($courseIds),
            'progress_percent' => 0,
            'completed_at' => null,
        ]);
        return $mapId;
    }
}
