<?php

declare(strict_types=1);

namespace Tests;

use App\service\LearningFactFunnelService;
use PHPUnit\Framework\TestCase;
use support\App;
use support\think\Db;
use Webman\ThinkOrm\ThinkOrm;

final class LearningFactFunnelServiceTest extends TestCase
{
    private int $staffId;
    private int $courseId;
    private int $lessonId;
    private int $previewLessonId;
    private int $categoryId;
    private string $now;
    private LearningFactFunnelService $service;

    public static function setUpBeforeClass(): void
    {
        App::loadAllConfig(['route', 'container']);
        ThinkOrm::start(null);
    }

    protected function setUp(): void
    {
        Db::startTrans();
        $this->now = (new \DateTimeImmutable('now', new \DateTimeZone('Asia/Shanghai')))->format('Y-m-d H:i:s');
        $this->staffId = $this->insertStaff();
        $this->categoryId = $this->insertCategory();
        $this->courseId = $this->insertCourse('漏斗课');
        [$this->lessonId, $this->previewLessonId] = $this->insertLessons($this->courseId);
        $this->service = new LearningFactFunnelService();
    }

    protected function tearDown(): void
    {
        Db::rollback();
    }

    public function testFourStagesMatchTenSixFourTwoFixture(): void
    {
        $this->seedTenSixFourTwo();
        $data = $this->service->show($this->staffId, $this->courseId, 30, 'all');
        self::assertSame(10, $data['stages'][0]['count']);
        self::assertSame(6, $data['stages'][1]['count']);
        self::assertSame(4, $data['stages'][2]['count']);
        self::assertSame(2, $data['stages'][3]['count']);
        self::assertSame('entitled', $data['stages'][0]['id']);
        self::assertSame(
            '本报告展示事实转化，不表示增量效果，也不把订单成功或券已使用当成学习完成。',
            $data['disclaimer'],
        );
        self::assertSame(1.0, $data['stages'][0]['of_cohort_rate']);
        self::assertSame(0.6, $data['stages'][1]['of_cohort_rate']);
        self::assertNull(
            (new LearningFactFunnelService())->show($this->staffId, $this->insertCourse('空课'), 30, 'all')['stages'][0]['of_cohort_rate'],
        );
    }

    public function testPreviewWithoutEntitlementIsNotInCohort(): void
    {
        $learnerId = $this->insertLearner();
        $this->insertProgress($learnerId, $this->previewLessonId, $this->now, false);
        $data = $this->service->show($this->staffId, $this->courseId, 30, 'all');
        self::assertSame(0, $data['stages'][0]['count']);
        self::assertSame(1, $data['trial']['learners']);
    }

    public function testPendingSplitsInWindowAndElapsed(): void
    {
        $fresh = $this->insertLearner();
        $this->insertEntitlement($fresh, 'free', $this->now);
        $old = $this->insertLearner();
        $oldGrant = (new \DateTimeImmutable('now', new \DateTimeZone('Asia/Shanghai')))
            ->modify('-40 days')
            ->format('Y-m-d H:i:s');
        $this->insertEntitlement($old, 'free', $oldGrant);
        $data = $this->service->show($this->staffId, $this->courseId, 30, 'all');
        self::assertSame(2, $data['stages'][0]['count']);
        self::assertSame(0, $data['stages'][1]['count']);
        self::assertSame(1, $data['pending']['in_window']);
        self::assertSame(1, $data['pending']['window_elapsed']);
        self::assertSame(
            $data['pending']['in_window'] + $data['pending']['window_elapsed'] + $data['stages'][1]['count'],
            $data['stages'][0]['count'],
        );
        self::assertSame('窗口进行中、尚未开始', $data['pending']['in_window_label']);
        self::assertSame('窗口内未转化', $data['pending']['window_elapsed_label']);
        self::assertStringNotContainsString('流失', $data['pending']['in_window_label']);
        self::assertStringNotContainsString('弃学', $data['pending']['window_elapsed_label']);
    }

    public function testOrdersAndPublishArePartitionedFromCompleted(): void
    {
        $learnerId = $this->insertLearner();
        $this->insertEntitlement($learnerId, 'purchase', $this->now);
        $this->insertOrder($learnerId, 'succeeded');
        $this->insertOrder($learnerId, 'succeeded');
        Db::name('notification_dispatches')->insert([
            'type' => 'course_published',
            'title' => '发布',
            'body' => '课',
            'resource_type' => 'course',
            'resource_id' => $this->courseId,
            'sender_staff_id' => $this->staffId,
            'recipient_mode' => 'all',
            'recipient_count' => 8,
            'fan_out_status' => 'pending',
            'fan_out_done_count' => 0,
            'created_at' => $this->now,
        ]);
        $data = $this->service->show($this->staffId, $this->courseId, 30, 'all');
        self::assertSame(2, $data['orders']['succeeded']);
        self::assertSame(0, $data['stages'][3]['count']);
        self::assertSame(8, $data['publish_reach']['recipient_count']);
        self::assertSame('订单成功不是完成课程', $data['orders']['note']);
        self::assertSame('发布触达与已有访问权人数分开，触达不是首次打开课节', $data['publish_reach']['note']);
    }

    public function testSourceFilterSumsToAll(): void
    {
        $this->insertEntitlement($this->insertLearner(), 'free', $this->now);
        $this->insertEntitlement($this->insertLearner(), 'free', $this->now);
        $this->insertEntitlement($this->insertLearner(), 'purchase', $this->now);
        $this->insertEntitlement($this->insertLearner(), 'activation_code', $this->now);
        $all = $this->service->show($this->staffId, $this->courseId, 30, 'all');
        $free = $this->service->show($this->staffId, $this->courseId, 30, 'free');
        $purchase = $this->service->show($this->staffId, $this->courseId, 30, 'purchase');
        $code = $this->service->show($this->staffId, $this->courseId, 30, 'activation_code');
        self::assertSame(4, $all['stages'][0]['count']);
        self::assertSame(2, $free['stages'][0]['count']);
        self::assertSame(1, $purchase['stages'][0]['count']);
        self::assertSame(1, $code['stages'][0]['count']);
        self::assertSame(
            $all['stages'][0]['count'],
            $free['stages'][0]['count'] + $purchase['stages'][0]['count'] + $code['stages'][0]['count'],
        );
    }

    public function testPreviewBeforePurchaseDoesNotCountAsFirstOpen(): void
    {
        $learnerId = $this->insertLearner();
        $before = (new \DateTimeImmutable('now', new \DateTimeZone('Asia/Shanghai')))
            ->modify('-2 days')
            ->format('Y-m-d H:i:s');
        $this->insertProgress($learnerId, $this->previewLessonId, $before, false);
        $this->insertEntitlement($learnerId, 'purchase', $this->now);
        $data = $this->service->show($this->staffId, $this->courseId, 30, 'purchase');
        self::assertSame(1, $data['stages'][0]['count']);
        self::assertSame(0, $data['stages'][1]['count']);
        self::assertSame(1, $data['trial']['learners']);
    }

    public function testOpenAfterGrantCountsEvenIfPreviewOpenedEarlier(): void
    {
        $learnerId = $this->insertLearner();
        $before = (new \DateTimeImmutable('now', new \DateTimeZone('Asia/Shanghai')))
            ->modify('-2 days')
            ->format('Y-m-d H:i:s');
        $this->insertProgress($learnerId, $this->previewLessonId, $before, false);
        $this->insertEntitlement($learnerId, 'purchase', $this->now);
        $this->insertProgress($learnerId, $this->lessonId, $this->now, false);
        $data = $this->service->show($this->staffId, $this->courseId, 30, 'purchase');
        self::assertSame(1, $data['stages'][1]['count']);
    }

    public function testPurchaseFilterDoesNotCountFreeLearnersAsTrial(): void
    {
        $freeLearner = $this->insertLearner();
        $this->insertEntitlement($freeLearner, 'free', $this->now);
        $this->insertProgress($freeLearner, $this->previewLessonId, $this->now, false);
        $paid = $this->insertLearner();
        $this->insertEntitlement($paid, 'purchase', $this->now);
        $data = $this->service->show($this->staffId, $this->courseId, 30, 'purchase');
        self::assertSame(1, $data['stages'][0]['count']);
        self::assertSame(0, $data['trial']['learners']);
    }

    public function testEventsAfterRevokeAreIgnored(): void
    {
        $learnerId = $this->insertLearner();
        $granted = (new \DateTimeImmutable('now', new \DateTimeZone('Asia/Shanghai')))
            ->modify('-10 days')
            ->format('Y-m-d H:i:s');
        $revoked = (new \DateTimeImmutable('now', new \DateTimeZone('Asia/Shanghai')))
            ->modify('-5 days')
            ->format('Y-m-d H:i:s');
        $this->insertEntitlement($learnerId, 'free', $granted, null, $revoked);
        $this->insertProgress($learnerId, $this->lessonId, $this->now, true);
        $data = $this->service->show($this->staffId, $this->courseId, 30, 'all');
        self::assertSame(1, $data['stages'][0]['count']);
        self::assertSame(0, $data['stages'][1]['count']);
        self::assertSame(0, $data['stages'][2]['count']);
    }

    public function testArchivedLessonsKeepPriorOpens(): void
    {
        $learnerId = $this->insertLearner();
        $this->insertEntitlement($learnerId, 'free', $this->now);
        $this->insertProgress($learnerId, $this->lessonId, $this->now, false);
        Db::name('lessons')->where('id', $this->lessonId)->update(['status' => 'disabled']);
        Db::name('lessons')->where('id', $this->previewLessonId)->update(['status' => 'disabled']);
        $data = $this->service->show($this->staffId, $this->courseId, 30, 'all');
        self::assertTrue($data['no_effective_lesson']);
        self::assertSame(1, $data['stages'][1]['count']);
    }

    public function testOrdersAndPublishHonorLookbackWindow(): void
    {
        $learnerId = $this->insertLearner();
        $this->insertEntitlement($learnerId, 'purchase', $this->now);
        $this->insertOrder($learnerId, 'succeeded', $this->now);
        $old = (new \DateTimeImmutable('now', new \DateTimeZone('Asia/Shanghai')))
            ->modify('-40 days')
            ->format('Y-m-d H:i:s');
        $this->insertOrder($learnerId, 'succeeded', $old);
        Db::name('notification_dispatches')->insert([
            'type' => 'course_published',
            'title' => '旧发布',
            'body' => '课',
            'resource_type' => 'course',
            'resource_id' => $this->courseId,
            'sender_staff_id' => $this->staffId,
            'recipient_mode' => 'all',
            'recipient_count' => 99,
            'fan_out_status' => 'pending',
            'fan_out_done_count' => 0,
            'created_at' => $old,
        ]);
        $data = $this->service->show($this->staffId, $this->courseId, 30, 'all');
        self::assertSame(1, $data['orders']['succeeded']);
        self::assertSame(0, $data['publish_reach']['recipient_count']);
    }

    public function testNoEffectiveLessonDoesNotLabelChurn(): void
    {
        $emptyId = $this->insertCourse('无课节');
        $this->insertEntitlement($this->insertLearner(), 'free', $this->now, $emptyId);
        $data = $this->service->show($this->staffId, $emptyId, 30, 'all');
        self::assertTrue($data['no_effective_lesson']);
        self::assertSame(1, $data['stages'][0]['count']);
        self::assertSame(0, $data['stages'][1]['count']);
        self::assertSame(1, $data['pending']['in_window']);
    }

    private function seedTenSixFourTwo(): void
    {
        for ($i = 0; $i < 4; $i++) {
            $this->insertEntitlement($this->insertLearner(), 'free', $this->now);
        }
        for ($i = 0; $i < 2; $i++) {
            $learnerId = $this->insertLearner();
            $this->insertEntitlement($learnerId, 'free', $this->now);
            $this->insertProgress($learnerId, $this->lessonId, $this->now, false);
        }
        for ($i = 0; $i < 2; $i++) {
            $learnerId = $this->insertLearner();
            $this->insertEntitlement($learnerId, 'purchase', $this->now);
            $this->insertProgress($learnerId, $this->lessonId, $this->now, true);
        }
        for ($i = 0; $i < 2; $i++) {
            $learnerId = $this->insertLearner();
            $this->insertEntitlement($learnerId, 'activation_code', $this->now);
            $this->insertProgress($learnerId, $this->lessonId, $this->now, true);
            $this->insertEnrollment($learnerId, true);
        }
    }

    private function insertStaff(): int
    {
        $id = (int) Db::name('accounts')->insertGetId([
            'kind' => 'staff',
            'login' => 'funnel-staff-' . bin2hex(random_bytes(4)),
            'password_hash' => 'x',
            'must_change_password' => 0,
            'status' => 'active',
            'created_at' => $this->now,
            'updated_at' => $this->now,
        ]);
        Db::name('staff_users')->insert([
            'account_id' => $id,
            'is_super_admin' => 1,
            'department_id' => null,
            'display_name' => 'Funnel Admin',
            'created_at' => $this->now,
            'updated_at' => $this->now,
        ]);
        return $id;
    }

    private function insertCategory(): int
    {
        return (int) Db::name('categories')->insertGetId([
            'parent_id' => 0,
            'name' => 'funnel-' . bin2hex(random_bytes(3)),
            'path' => '/',
            'depth' => 1,
            'sort' => 0,
            'status' => 'enabled',
            'created_at' => $this->now,
            'updated_at' => $this->now,
        ]);
    }

    private function insertCourse(string $title): int
    {
        return (int) Db::name('courses')->insertGetId([
            'department_id' => null,
            'category_id' => $this->categoryId,
            'title' => $title,
            'cover_url' => null,
            'teacher_name' => '讲师',
            'summary' => '',
            'intro_rich_text' => '<p>简介</p>',
            'status' => 'published',
            'price_mode' => 'free',
            'list_price' => 0,
            'sale_price' => 0,
            'sale_start_at' => null,
            'sale_end_at' => null,
            'created_by_staff_id' => $this->staffId,
            'created_at' => $this->now,
            'updated_at' => $this->now,
        ]);
    }

    /** @return array{0:int,1:int} */
    private function insertLessons(int $courseId): array
    {
        $chapterId = (int) Db::name('chapters')->insertGetId([
            'course_id' => $courseId,
            'title' => '第一章',
            'sort' => 1,
            'status' => 'enabled',
            'created_at' => $this->now,
            'updated_at' => $this->now,
        ]);
        $lessonId = (int) Db::name('lessons')->insertGetId([
            'chapter_id' => $chapterId,
            'title' => '正课',
            'sort' => 1,
            'status' => 'enabled',
            'content_type' => 'markdown',
            'body_markdown' => '正文',
            'asset_id' => null,
            'is_preview' => 0,
            'duration_seconds' => 0,
            'created_at' => $this->now,
            'updated_at' => $this->now,
        ]);
        $previewId = (int) Db::name('lessons')->insertGetId([
            'chapter_id' => $chapterId,
            'title' => '试看',
            'sort' => 2,
            'status' => 'enabled',
            'content_type' => 'markdown',
            'body_markdown' => '试看',
            'asset_id' => null,
            'is_preview' => 1,
            'duration_seconds' => 0,
            'created_at' => $this->now,
            'updated_at' => $this->now,
        ]);
        return [$lessonId, $previewId];
    }

    private function insertLearner(): int
    {
        $id = (int) Db::name('accounts')->insertGetId([
            'kind' => 'learner',
            'login' => '139' . random_int(100000000, 999999999),
            'password_hash' => 'x',
            'must_change_password' => 0,
            'status' => 'active',
            'created_at' => $this->now,
            'updated_at' => $this->now,
        ]);
        Db::name('learners')->insert([
            'account_id' => $id,
            'nickname' => null,
            'avatar_url' => null,
            'show_on_course' => 0,
            'created_at' => $this->now,
            'updated_at' => $this->now,
        ]);
        return $id;
    }

    private function insertEntitlement(
        int $learnerId,
        string $source,
        string $at,
        ?int $courseId = null,
        ?string $revokedAt = null,
    ): void {
        Db::name('course_entitlements')->insert([
            'learner_id' => $learnerId,
            'course_id' => $courseId ?? $this->courseId,
            'source' => $source,
            'order_id' => null,
            'status' => $revokedAt === null ? 'active' : 'revoked',
            'revoked_at' => $revokedAt,
            'created_at' => $at,
            'updated_at' => $revokedAt ?? $at,
        ]);
    }

    private function insertProgress(int $learnerId, int $lessonId, string $at, bool $completed): void
    {
        Db::name('lesson_progresses')->insert([
            'learner_id' => $learnerId,
            'lesson_id' => $lessonId,
            'position_seconds' => $completed ? 1 : 0,
            'completed' => $completed ? 1 : 0,
            'completed_at' => $completed ? $at : null,
            'opened_at' => $at,
            'created_at' => $at,
            'updated_at' => $at,
        ]);
    }

    private function insertEnrollment(int $learnerId, bool $completed): void
    {
        Db::name('course_enrollments')->insert([
            'learner_id' => $learnerId,
            'course_id' => $this->courseId,
            'progress_percent' => $completed ? 100 : 10,
            'last_lesson_id' => $this->lessonId,
            'last_position' => 0,
            'completed_at' => $completed ? $this->now : null,
            'created_at' => $this->now,
            'updated_at' => $this->now,
        ]);
    }

    private function insertOrder(int $learnerId, string $status, ?string $at = null): void
    {
        $at ??= $this->now;
        Db::name('orders')->insert([
            'learner_id' => $learnerId,
            'course_id' => $this->courseId,
            'learner_coupon_id' => null,
            'list_price_snapshot' => 99,
            'sale_price_snapshot' => 99,
            'coupon_discount_snapshot' => 0,
            'paid_amount' => 99,
            'currency' => 'CNY',
            'status' => $status,
            'provider' => 'fake',
            'provider_ref' => 'funnel-' . bin2hex(random_bytes(3)),
            'succeeded_at' => $status === 'succeeded' ? $at : null,
            'created_at' => $at,
            'updated_at' => $at,
        ]);
    }
}
