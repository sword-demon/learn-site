<?php

declare(strict_types=1);

namespace Tests;

use App\service\CourseStudentService;
use App\service\DataScopeService;
use App\service\EntitlementService;
use PHPUnit\Framework\TestCase;
use support\App;
use support\think\Db;
use Webman\ThinkOrm\ThinkOrm;

final class CourseStartQueueTest extends TestCase
{
    private int $staffId;
    private int $courseId;
    private int $lessonId;

    public static function setUpBeforeClass(): void
    {
        App::loadAllConfig(['route', 'container']);
        ThinkOrm::start(null);
    }

    protected function setUp(): void
    {
        Db::startTrans();
        $this->seedCourse();
    }

    protected function tearDown(): void
    {
        Db::rollback();
    }

    public function testNeverOpenedLearnerPastThresholdAppearsWithSourceAndIdleDuration(): void
    {
        $grantedAt = date('Y-m-d H:i:s', time() - 80 * 3600);
        $learnerId = $this->insertLearner('从未打开学员', 'purchase', $grantedAt);

        $result = $this->service()->listStartQueue($this->staffId, $this->courseId, [
            'page' => 1,
            'limit' => 20,
        ]);

        self::assertSame(1, $result['total']);
        $item = $result['items'][0];
        self::assertSame($learnerId, $item['account_id']);
        self::assertSame('never_opened', $item['startup_state']);
        self::assertSame('purchase', $item['source']);
        self::assertSame($grantedAt, $item['entitled_at']);
        self::assertGreaterThanOrEqual(80, $item['idle_hours']);
        self::assertSame(72, $result['policy']['idle_threshold_hours']);
        self::assertSame(72, $result['policy']['reminder_frequency_hours']);
        self::assertSame(3, $result['policy']['reminder_cap']);
        self::assertSame(0, $item['progress_percent']);
        self::assertSame('从未打开学员', $item['nickname']);
        self::assertSame(0, $item['reminder_count']);
        self::assertTrue($item['can_remind']);
        self::assertNull($item['reminder_blocked_reason']);
    }

    public function testOpenedZeroProgressIsDistinctFromNeverOpened(): void
    {
        $grantedAt = date('Y-m-d H:i:s', time() - 80 * 3600);
        $openedId = $this->insertLearner('打开过零进度', 'free', $grantedAt);
        $neverId = $this->insertLearner('从未打开对照', 'activation_code', $grantedAt);
        $this->openLesson($openedId);

        $result = $this->service()->listStartQueue($this->staffId, $this->courseId, [
            'page' => 1,
            'limit' => 20,
        ]);
        $byId = [];
        foreach ($result['items'] as $item) {
            $byId[(int) $item['account_id']] = $item;
        }

        self::assertSame(2, $result['total']);
        self::assertSame('opened_zero_progress', $byId[$openedId]['startup_state']);
        self::assertSame('never_opened', $byId[$neverId]['startup_state']);
        self::assertSame(0, $byId[$openedId]['progress_percent']);
    }

    public function testLearnerInsideThresholdIsExcluded(): void
    {
        $this->insertLearner('阈值内学员', 'purchase', date('Y-m-d H:i:s', time() - 10 * 3600));

        $result = $this->service()->listStartQueue($this->staffId, $this->courseId, [
            'page' => 1,
            'limit' => 20,
        ]);

        self::assertSame(0, $result['total']);
        self::assertSame([], $result['items']);
    }

    public function testCompletedPositiveProgressRevokedAndNoEffectiveLessonAreExcluded(): void
    {
        $grantedAt = date('Y-m-d H:i:s', time() - 80 * 3600);
        $completedId = $this->insertLearner('已完成学员', 'purchase', $grantedAt);
        $progressId = $this->insertLearner('有进度学员', 'free', $grantedAt);
        $revokedId = $this->insertLearner('已撤销学员', 'activation_code', $grantedAt);
        $this->setEnrollment($completedId, 100, $grantedAt);
        $this->setEnrollment($progressId, 20, null);
        Db::name('course_entitlements')->where('learner_id', $revokedId)->update([
            'status' => 'revoked',
            'revoked_at' => date('Y-m-d H:i:s'),
            'revoked_reason' => 'test',
        ]);

        $withLessons = $this->service()->listStartQueue($this->staffId, $this->courseId, [
            'page' => 1,
            'limit' => 20,
        ]);
        self::assertSame(0, $withLessons['total']);

        $emptyCourseId = $this->insertEmptyCourse();
        $this->insertLearnerForCourse($emptyCourseId, '无课节学员', 'purchase', $grantedAt);
        $empty = $this->service()->listStartQueue($this->staffId, $emptyCourseId, [
            'page' => 1,
            'limit' => 20,
        ]);
        self::assertSame(0, $empty['total']);
        self::assertSame([], $empty['items']);
    }

    public function testLessonCompletionWithoutEnrollmentIsExcludedFromQueueAndSend(): void
    {
        $grantedAt = date('Y-m-d H:i:s', time() - 80 * 3600);
        $lessonProgressId = $this->insertLearner('课节已完成无报名', 'purchase', $grantedAt);
        $now = date('Y-m-d H:i:s');
        Db::name('lesson_progresses')->insert([
            'learner_id' => $lessonProgressId,
            'lesson_id' => $this->lessonId,
            'position_seconds' => 1,
            'completed' => 1,
            'completed_at' => $now,
            'opened_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $afterLessonProgress = $this->service()->listStartQueue($this->staffId, $this->courseId, [
            'page' => 1,
            'limit' => 20,
        ]);
        self::assertSame(0, $afterLessonProgress['total']);
        $blockedSend = $this->service()->sendStartReminders($this->staffId, $this->courseId, [$lessonProgressId]);
        self::assertSame(0, $blockedSend['sent_count']);
        self::assertSame('started', $blockedSend['outcomes'][0]['blocked_reason']);
    }

    public function testReactivatedLearnerUsesCurrentEntitlementAndHistoricalRevokedDoesNotQualify(): void
    {
        $oldGrant = date('Y-m-d H:i:s', time() - 200 * 3600);
        $newGrant = date('Y-m-d H:i:s', time() - 80 * 3600);
        $learnerId = $this->insertLearner('重新开通学员', 'free', $oldGrant);
        Db::name('course_entitlements')->where('learner_id', $learnerId)->update([
            'status' => 'revoked',
            'revoked_at' => $oldGrant,
            'revoked_reason' => 'temporary',
            'revoked_by_staff_id' => $this->staffId,
        ]);
        Db::name('course_entitlements')->insert([
            'learner_id' => $learnerId,
            'course_id' => $this->courseId,
            'source' => 'purchase',
            'order_id' => null,
            'status' => 'active',
            'created_at' => $newGrant,
            'updated_at' => $newGrant,
        ]);

        $result = $this->service()->listStartQueue($this->staffId, $this->courseId, [
            'page' => 1,
            'limit' => 20,
        ]);

        self::assertSame(1, $result['total']);
        self::assertSame($learnerId, $result['items'][0]['account_id']);
        self::assertSame('purchase', $result['items'][0]['source']);
        self::assertSame($newGrant, $result['items'][0]['entitled_at']);
        self::assertGreaterThanOrEqual(80, $result['items'][0]['idle_hours']);
        self::assertLessThan(200, $result['items'][0]['idle_hours']);
    }

    public function testSourceAndStartupStateFiltersPreservePaginationTotals(): void
    {
        $grantedAt = date('Y-m-d H:i:s', time() - 80 * 3600);
        $purchaseNever = $this->insertLearner('付费从未打开', 'purchase', $grantedAt);
        $freeOpened = $this->insertLearner('免费打开过', 'free', $grantedAt);
        $this->openLesson($freeOpened);
        $this->insertLearner('激活码从未打开', 'activation_code', date('Y-m-d H:i:s', time() - 90 * 3600));

        $purchase = $this->service()->listStartQueue($this->staffId, $this->courseId, [
            'source' => 'purchase',
            'page' => 1,
            'limit' => 1,
        ]);
        self::assertSame(1, $purchase['total']);
        self::assertSame($purchaseNever, $purchase['items'][0]['account_id']);

        $opened = $this->service()->listStartQueue($this->staffId, $this->courseId, [
            'startup_state' => 'opened_zero_progress',
            'page' => 1,
            'limit' => 20,
        ]);
        self::assertSame(1, $opened['total']);
        self::assertSame($freeOpened, $opened['items'][0]['account_id']);

        $sorted = $this->service()->listStartQueue($this->staffId, $this->courseId, [
            'sort' => 'idle_hours',
            'order' => 'desc',
            'page' => 1,
            'limit' => 20,
        ]);
        self::assertSame(3, $sorted['total']);
        self::assertGreaterThanOrEqual($sorted['items'][1]['idle_hours'], $sorted['items'][0]['idle_hours']);
    }

    public function testOutOfScopeStaffCannotReadQueue(): void
    {
        $grantedAt = date('Y-m-d H:i:s', time() - 80 * 3600);
        $this->insertLearner('范围外对照', 'purchase', $grantedAt);
        $now = date('Y-m-d H:i:s');
        $outsideDepartmentId = (int) Db::name('departments')->insertGetId([
            'parent_id' => null,
            'name' => 'queue-out-' . bin2hex(random_bytes(3)),
            'path' => '/',
            'depth' => 1,
            'sort' => 0,
            'status' => 'enabled',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $outsiderId = (int) Db::name('accounts')->insertGetId([
            'kind' => 'staff',
            'login' => 'queue-out-' . bin2hex(random_bytes(4)),
            'password_hash' => 'hash',
            'must_change_password' => 0,
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        Db::name('staff_users')->insert([
            'account_id' => $outsiderId,
            'is_super_admin' => 0,
            'department_id' => $outsideDepartmentId,
            'display_name' => 'Outside Queue Staff',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $roleId = (int) Db::name('roles')->insertGetId([
            'name' => 'queue-out-role',
            'code' => 'queue-out-' . bin2hex(random_bytes(3)),
            'data_scope' => 'dept',
            'status' => 'enabled',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        Db::name('staff_role')->insert([
            'staff_user_id' => $outsiderId,
            'role_id' => $roleId,
        ]);

        try {
            $this->service()->listStartQueue($outsiderId, $this->courseId, ['page' => 1, 'limit' => 20]);
            self::fail('Expected out-of-scope staff to be rejected on queue read.');
        } catch (\App\service\BusinessException $exception) {
            self::assertSame('FORBIDDEN', $exception->apiCode);
            self::assertSame('DEPARTMENT_OUT_OF_SCOPE', $exception->getMessage());
        }

        try {
            $this->service()->sendStartReminders($outsiderId, $this->courseId, [1]);
            self::fail('Expected out-of-scope staff to be rejected on reminder send.');
        } catch (\App\service\BusinessException $exception) {
            self::assertSame('FORBIDDEN', $exception->apiCode);
            self::assertSame('DEPARTMENT_OUT_OF_SCOPE', $exception->getMessage());
        }
    }

    public function testEligibleLearnerReceivesOneCourseLinkedReminder(): void
    {
        $grantedAt = date('Y-m-d H:i:s', time() - 80 * 3600);
        $learnerId = $this->insertLearner('待提醒学员', 'purchase', $grantedAt);
        $progressBefore = (int) Db::name('lesson_progresses')->where('learner_id', $learnerId)->count();
        $enrollmentBefore = (int) Db::name('course_enrollments')
            ->where('learner_id', $learnerId)
            ->where('course_id', $this->courseId)
            ->count();
        $entitlementStatus = (string) Db::name('course_entitlements')
            ->where('learner_id', $learnerId)
            ->where('course_id', $this->courseId)
            ->value('status');

        $result = $this->service()->sendStartReminders($this->staffId, $this->courseId, [$learnerId]);

        self::assertSame(1, $result['sent_count']);
        self::assertSame(0, $result['blocked_count']);
        self::assertNotNull($result['dispatch_id']);
        self::assertTrue($result['outcomes'][0]['sent']);
        self::assertSame(
            1,
            (int) Db::name('learner_notifications')
                ->where('learner_id', $learnerId)
                ->where('kind', 'learning_reminder')
                ->where('resource_type', 'course')
                ->where('resource_id', $this->courseId)
                ->count(),
        );
        $dispatch = Db::name('notification_dispatches')->where('id', $result['dispatch_id'])->find();
        self::assertSame('learning_reminder', (string) $dispatch['type']);
        self::assertSame('course', (string) $dispatch['resource_type']);
        self::assertSame($this->courseId, (int) $dispatch['resource_id']);
        self::assertSame($progressBefore, (int) Db::name('lesson_progresses')->where('learner_id', $learnerId)->count());
        self::assertSame($enrollmentBefore, (int) Db::name('course_enrollments')->where('learner_id', $learnerId)->where('course_id', $this->courseId)->count());
        self::assertSame($entitlementStatus, (string) Db::name('course_entitlements')->where('learner_id', $learnerId)->where('course_id', $this->courseId)->value('status'));
        self::assertSame(0, (int) Db::name('learner_daily_checkins')->where('learner_id', $learnerId)->count());
    }

    public function testStaleAndThrottledSelectionsAreBlockedWithoutSending(): void
    {
        $grantedAt = date('Y-m-d H:i:s', time() - 80 * 3600);
        $startedId = $this->insertLearner('已开始学员', 'free', $grantedAt);
        $this->setEnrollment($startedId, 20, null);
        $insideId = $this->insertLearner('阈值内学员', 'purchase', date('Y-m-d H:i:s', time() - 10 * 3600));
        $throttledId = $this->insertLearner('频率受限学员', 'activation_code', $grantedAt);
        $this->insertPriorReminder($throttledId);

        $result = $this->service()->sendStartReminders(
            $this->staffId,
            $this->courseId,
            [$startedId, $insideId, $throttledId],
        );

        self::assertSame(0, $result['sent_count']);
        self::assertSame(3, $result['blocked_count']);
        self::assertNull($result['dispatch_id']);
        $reasons = [];
        foreach ($result['outcomes'] as $outcome) {
            $reasons[(int) $outcome['account_id']] = $outcome['blocked_reason'];
        }
        self::assertSame('started', $reasons[$startedId]);
        self::assertSame('below_threshold', $reasons[$insideId]);
        self::assertSame('frequency', $reasons[$throttledId]);
        self::assertSame(
            1,
            (int) Db::name('learner_notifications')
                ->where('learner_id', $throttledId)
                ->where('kind', 'learning_reminder')
                ->count(),
        );
        self::assertSame(
            0,
            (int) Db::name('learner_notifications')
                ->whereIn('learner_id', [$startedId, $insideId])
                ->where('kind', 'learning_reminder')
                ->count(),
        );
    }

    public function testDuplicateSendDoesNotCreateSecondInboxRowBeyondCap(): void
    {
        $grantedAt = date('Y-m-d H:i:s', time() - 80 * 3600);
        $learnerId = $this->insertLearner('幂等学员', 'purchase', $grantedAt);
        Db::name('courses')->where('id', $this->courseId)->update([
            'reminder_frequency_hours' => 1,
            'reminder_cap' => 1,
        ]);

        $first = $this->service()->sendStartReminders($this->staffId, $this->courseId, [$learnerId]);
        $second = $this->service()->sendStartReminders($this->staffId, $this->courseId, [$learnerId]);

        self::assertSame(1, $first['sent_count']);
        self::assertSame(0, $second['sent_count']);
        self::assertSame('cap', $second['outcomes'][0]['blocked_reason']);
        self::assertSame(
            1,
            (int) Db::name('learner_notifications')
                ->where('learner_id', $learnerId)
                ->where('kind', 'learning_reminder')
                ->where('resource_id', $this->courseId)
                ->count(),
        );
    }

    private function service(): CourseStudentService
    {
        return new CourseStudentService(new DataScopeService(), new EntitlementService());
    }

    private function seedCourse(): void
    {
        $now = date('Y-m-d H:i:s');
        $suffix = bin2hex(random_bytes(4));
        $this->staffId = (int) Db::name('accounts')->insertGetId([
            'kind' => 'staff',
            'login' => "queue-admin-{$suffix}",
            'password_hash' => 'hash',
            'must_change_password' => 0,
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        Db::name('staff_users')->insert([
            'account_id' => $this->staffId,
            'is_super_admin' => 1,
            'department_id' => null,
            'display_name' => '启动队列管理员',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $departmentId = (int) Db::name('departments')->insertGetId([
            'parent_id' => null,
            'name' => "启动队列部门 {$suffix}",
            'path' => '/',
            'depth' => 1,
            'sort' => 0,
            'status' => 'enabled',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        Db::name('departments')->where('id', $departmentId)->update(['path' => "/{$departmentId}"]);
        $categoryId = (int) Db::name('categories')->insertGetId([
            'parent_id' => 0,
            'name' => "启动队列分类 {$suffix}",
            'path' => '/',
            'depth' => 1,
            'sort' => 0,
            'status' => 'enabled',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $this->courseId = (int) Db::name('courses')->insertGetId([
            'department_id' => $departmentId,
            'category_id' => $categoryId,
            'title' => "启动队列课程 {$suffix}",
            'cover_url' => null,
            'teacher_name' => '测试教师',
            'summary' => '测试摘要',
            'intro_rich_text' => '<p>测试</p>',
            'status' => 'published',
            'price_mode' => 'free',
            'list_price' => 0,
            'sale_price' => 0,
            'sale_start_at' => null,
            'sale_end_at' => null,
            'created_by_staff_id' => $this->staffId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $chapterId = (int) Db::name('chapters')->insertGetId([
            'course_id' => $this->courseId,
            'title' => '第一章',
            'sort' => 0,
            'status' => 'enabled',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $this->lessonId = (int) Db::name('lessons')->insertGetId([
            'chapter_id' => $chapterId,
            'title' => '第一节',
            'sort' => 0,
            'status' => 'enabled',
            'content_type' => 'markdown',
            'body_markdown' => '内容',
            'asset_id' => null,
            'is_preview' => 0,
            'duration_seconds' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function insertLearner(string $nickname, string $source, string $grantedAt): int
    {
        $now = date('Y-m-d H:i:s');
        $learnerId = (int) Db::name('accounts')->insertGetId([
            'kind' => 'learner',
            'login' => '139' . str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT),
            'password_hash' => 'hash',
            'must_change_password' => 0,
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        Db::name('learners')->insert([
            'account_id' => $learnerId,
            'nickname' => $nickname,
            'avatar_url' => null,
            'show_on_course' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        Db::name('course_entitlements')->insert([
            'learner_id' => $learnerId,
            'course_id' => $this->courseId,
            'source' => $source,
            'order_id' => null,
            'status' => 'active',
            'created_at' => $grantedAt,
            'updated_at' => $grantedAt,
        ]);
        return $learnerId;
    }

    private function insertPriorReminder(int $learnerId): void
    {
        $now = date('Y-m-d H:i:s');
        $dispatchId = (int) Db::name('notification_dispatches')->insertGetId([
            'type' => 'learning_reminder',
            'title' => '先前提醒',
            'body' => '先前提醒',
            'resource_type' => 'course',
            'resource_id' => $this->courseId,
            'sender_staff_id' => $this->staffId,
            'recipient_mode' => 'selected',
            'recipient_count' => 1,
            'fan_out_status' => 'completed',
            'fan_out_done_count' => 1,
            'created_at' => $now,
        ]);
        Db::name('notification_dispatch_recipients')->insert([
            'dispatch_id' => $dispatchId,
            'learner_id' => $learnerId,
        ]);
        Db::name('learner_notifications')->insert([
            'learner_id' => $learnerId,
            'kind' => 'learning_reminder',
            'title' => '先前提醒',
            'body' => '先前提醒',
            'resource_type' => 'course',
            'resource_id' => $this->courseId,
            'dispatch_id' => $dispatchId,
            'idempotency_key' => $dispatchId . ':' . $learnerId,
            'created_at' => $now,
        ]);
    }

    private function insertLearnerForCourse(int $courseId, string $nickname, string $source, string $grantedAt): int
    {
        $now = date('Y-m-d H:i:s');
        $learnerId = (int) Db::name('accounts')->insertGetId([
            'kind' => 'learner',
            'login' => '138' . str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT),
            'password_hash' => 'hash',
            'must_change_password' => 0,
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        Db::name('learners')->insert([
            'account_id' => $learnerId,
            'nickname' => $nickname,
            'avatar_url' => null,
            'show_on_course' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        Db::name('course_entitlements')->insert([
            'learner_id' => $learnerId,
            'course_id' => $courseId,
            'source' => $source,
            'order_id' => null,
            'status' => 'active',
            'created_at' => $grantedAt,
            'updated_at' => $grantedAt,
        ]);
        return $learnerId;
    }

    private function openLesson(int $learnerId): void
    {
        $now = date('Y-m-d H:i:s');
        Db::name('lesson_progresses')->insert([
            'learner_id' => $learnerId,
            'lesson_id' => $this->lessonId,
            'position_seconds' => 0,
            'completed' => 0,
            'completed_at' => null,
            'opened_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        Db::name('course_enrollments')->insert([
            'learner_id' => $learnerId,
            'course_id' => $this->courseId,
            'progress_percent' => 0,
            'last_lesson_id' => $this->lessonId,
            'last_position' => 0,
            'completed_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function setEnrollment(int $learnerId, int $progress, ?string $completedAt): void
    {
        $now = date('Y-m-d H:i:s');
        Db::name('course_enrollments')->insert([
            'learner_id' => $learnerId,
            'course_id' => $this->courseId,
            'progress_percent' => $progress,
            'last_lesson_id' => $this->lessonId,
            'last_position' => 1,
            'completed_at' => $completedAt,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function insertEmptyCourse(): int
    {
        $now = date('Y-m-d H:i:s');
        $departmentId = (int) Db::name('courses')->where('id', $this->courseId)->value('department_id');
        $categoryId = (int) Db::name('courses')->where('id', $this->courseId)->value('category_id');
        return (int) Db::name('courses')->insertGetId([
            'department_id' => $departmentId,
            'category_id' => $categoryId,
            'title' => '无有效课节课程 ' . bin2hex(random_bytes(3)),
            'cover_url' => null,
            'teacher_name' => '测试教师',
            'summary' => '无课节',
            'intro_rich_text' => '<p>无课节</p>',
            'status' => 'published',
            'price_mode' => 'free',
            'list_price' => 0,
            'sale_price' => 0,
            'sale_start_at' => null,
            'sale_end_at' => null,
            'created_by_staff_id' => $this->staffId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
}
