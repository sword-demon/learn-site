<?php

declare(strict_types=1);

namespace Tests;

use App\service\BusinessException;
use App\service\CourseService;
use App\service\NotificationDispatchService;
use PHPUnit\Framework\TestCase;
use support\App;
use support\think\Db;
use Webman\ThinkOrm\ThinkOrm;

/**
 * 010-course-notify-feedback-codes / US1 — course publish fan-out.
 *
 * In tests APP_ENV=testing makes JobDispatcher run the fan-out consumer
 * synchronously, so a publish immediately produces inbox rows; the retry
 * path is exercised by stamping a dispatch `failed` first.
 */
final class CoursePublishNotifyTest extends TestCase
{
    private int $staffId;

    public static function setUpBeforeClass(): void
    {
        App::loadAllConfig(['route', 'container']);
        ThinkOrm::start(null);
    }

    protected function setUp(): void
    {
        Db::startTrans();
        $this->staffId = $this->insertStaff();
    }

    protected function tearDown(): void
    {
        Db::rollback();
    }

    public function testPublishCreatesDispatchAndCopiesResourceIntoInbox(): void
    {
        $courseId = $this->insertCourse();
        $learnerA = $this->insertLearner();
        $learnerB = $this->insertLearner();
        self::assertNotSame($learnerA, $learnerB);
        // The shared test database holds other active learners: recipients
        // must be "every active learner", so compare against a live count
        // instead of an absolute number.
        $expectedRecipients = (int) Db::name('accounts')
            ->alias('a')
            ->join('learners l', 'l.account_id = a.id')
            ->where('a.status', 'active')
            ->count();

        $service = new CourseService();
        $service->publishCourse($courseId, $this->staffId);

        $dispatches = Db::name('notification_dispatches')
            ->where('type', 'course_published')
            ->where('resource_id', $courseId)
            ->select()->toArray();
        self::assertCount(1, $dispatches);
        self::assertSame('course', (string) $dispatches[0]['resource_type']);
        self::assertSame($courseId, (int) $dispatches[0]['resource_id']);
        self::assertSame($this->staffId, (int) $dispatches[0]['sender_staff_id']);
        self::assertSame('all', (string) $dispatches[0]['recipient_mode']);
        self::assertSame('completed', (string) $dispatches[0]['fan_out_status']);
        self::assertSame($expectedRecipients, (int) $dispatches[0]['recipient_count']);

        $inbox = Db::name('learner_notifications')
            ->where('kind', 'course_published')
            ->whereIn('learner_id', [$learnerA, $learnerB])
            ->select()->toArray();
        self::assertCount(2, $inbox);
        foreach ($inbox as $row) {
            self::assertSame('course', (string) $row['resource_type']);
            self::assertSame($courseId, (int) $row['resource_id']);
            self::assertSame((int) $dispatches[0]['id'], (int) $row['dispatch_id']);
        }
        $keys = array_column($inbox, 'idempotency_key');
        self::assertCount(2, array_unique($keys));

        self::assertSame(
            1,
            (int) Db::name('audit_log')
                ->where('action', 'notification.send')
                ->where('target_id', (int) $dispatches[0]['id'])
                ->count(),
        );
    }

    public function testPublishRejectsStaffOutsideCourseDataScope(): void
    {
        $courseId = $this->insertCourse();
        $limitedStaff = $this->insertLimitedStaff();

        $this->expectException(BusinessException::class);
        $this->expectExceptionMessage('DEPARTMENT_OUT_OF_SCOPE');
        (new CourseService())->publishCourse($courseId, $limitedStaff);
    }

    public function testRepublishAlreadyPublishedCourseDoesNotCreateNewDispatch(): void
    {
        $courseId = $this->insertCourse();
        $service = new CourseService();
        $service->publishCourse($courseId, $this->staffId);
        $service->publishCourse($courseId, $this->staffId);

        self::assertSame(
            1,
            (int) Db::name('notification_dispatches')
                ->where('type', 'course_published')
                ->where('resource_id', $courseId)
                ->count(),
        );
    }

    public function testEditingPublishedCourseContentDoesNotNotifyAgain(): void
    {
        $courseId = $this->insertCourse();
        $service = new CourseService();
        $service->publishCourse($courseId, $this->staffId);

        // Edit the summary of the already-published course: FR-002 forbids
        // a new course_published dispatch on content-only updates.
        $service->updateCourse($courseId, ['summary' => '更新后的课程简介'], $this->staffId);

        self::assertSame(
            1,
            (int) Db::name('notification_dispatches')
                ->where('type', 'course_published')
                ->where('resource_id', $courseId)
                ->count(),
        );
    }

    public function testUnpublishAndRepublishCreatesSecondDispatch(): void
    {
        $courseId = $this->insertCourse();
        $learnerA = $this->insertLearner();
        $learnerB = $this->insertLearner();
        $service = new CourseService();
        $service->publishCourse($courseId, $this->staffId);
        $service->unpublishCourse($courseId);
        $service->publishCourse($courseId, $this->staffId);

        $dispatches = Db::name('notification_dispatches')
            ->where('type', 'course_published')
            ->where('resource_id', $courseId)
            ->order('id', 'asc')
            ->select()->toArray();
        self::assertCount(2, $dispatches);
        $myInbox = Db::name('learner_notifications')
            ->where('kind', 'course_published')
            ->whereIn('learner_id', [$learnerA, $learnerB])
            ->count();
        self::assertSame(4, (int) $myInbox);
    }

    public function testRetryDoesNotBackfillLearnersRegisteredAfterPublish(): void
    {
        $courseId = $this->insertCourse();
        $publishedLearner = $this->insertLearner();
        (new CourseService())->publishCourse($courseId, $this->staffId);
        $dispatchId = (int) Db::name('notification_dispatches')
            ->where('type', 'course_published')
            ->where('resource_id', $courseId)
            ->value('id');

        // Created in the same second on purpose: recipient_snapshot_max_id,
        // not wall-clock precision, excludes this post-publish learner.
        $lateLearner = $this->insertLearner();
        Db::name('notification_dispatches')->where('id', $dispatchId)->update([
            'fan_out_status' => 'failed',
            'fan_out_error' => 'retry after a new registration',
        ]);
        (new NotificationDispatchService())->retryFanOut($dispatchId);

        self::assertSame(
            1,
            (int) Db::name('learner_notifications')
                ->where('dispatch_id', $dispatchId)
                ->where('learner_id', $publishedLearner)
                ->count(),
        );
        self::assertSame(
            0,
            (int) Db::name('learner_notifications')
                ->where('dispatch_id', $dispatchId)
                ->where('learner_id', $lateLearner)
                ->count(),
        );
    }

    public function testDisabledLearnersAreNotRecipients(): void
    {
        $courseId = $this->insertCourse();
        $active = $this->insertLearner();
        $disabled = $this->insertLearner();
        Db::name('accounts')->where('id', $disabled)->update(['status' => 'disabled']);

        (new CourseService())->publishCourse($courseId, $this->staffId);

        self::assertSame(
            0,
            (int) Db::name('learner_notifications')
                ->where('kind', 'course_published')
                ->where('learner_id', $disabled)
                ->count(),
        );
        self::assertSame(
            1,
            (int) Db::name('learner_notifications')
                ->where('kind', 'course_published')
                ->where('learner_id', $active)
                ->count(),
        );
    }

    public function testPublishSurvivesNotificationFailureWithoutRollingBack(): void
    {
        $courseId = $this->insertCourse();

        // actor id <= 0 makes the dispatch service throw; the publish path
        // must swallow it (FR-008) and keep the course published.
        (new CourseService())->publishCourse($courseId, 0);

        self::assertSame(
            'published',
            (string) Db::name('courses')->where('id', $courseId)->value('status'),
        );
        self::assertSame(
            0,
            (int) Db::name('notification_dispatches')
                ->where('type', 'course_published')
                ->where('resource_id', $courseId)
                ->count(),
        );
    }

    public function testRetryRequeuesFailedDispatchAndCompletes(): void
    {
        $courseId = $this->insertCourse();
        $this->insertLearner();
        (new CourseService())->publishCourse($courseId, $this->staffId);

        $dispatchId = (int) Db::name('notification_dispatches')
            ->where('type', 'course_published')
            ->where('resource_id', $courseId)
            ->value('id');
        Db::name('notification_dispatches')->where('id', $dispatchId)->update([
            'fan_out_status' => 'failed',
            'fan_out_error' => 'boom',
        ]);

        $dispatchService = new NotificationDispatchService();
        $dispatchService->retryFanOut($dispatchId);
        // The in-process sync consumer finishes the re-enqueued job right
        // away, so the row is back to `completed` with the error cleared.
        $row = Db::name('notification_dispatches')->where('id', $dispatchId)->find();
        self::assertSame('completed', (string) $row['fan_out_status']);
        self::assertNull($row['fan_out_error']);
    }

    public function testRetryRestoresOnlyMissingInboxRows(): void
    {
        $courseId = $this->insertCourse();
        $learnerA = $this->insertLearner();
        $learnerB = $this->insertLearner();
        (new CourseService())->publishCourse($courseId, $this->staffId);

        $dispatchId = (int) Db::name('notification_dispatches')
            ->where('type', 'course_published')
            ->where('resource_id', $courseId)
            ->value('id');
        $recipientCount = (int) Db::name('notification_dispatches')
            ->where('id', $dispatchId)
            ->value('recipient_count');
        Db::name('learner_notifications')
            ->where('dispatch_id', $dispatchId)
            ->where('learner_id', $learnerB)
            ->delete();
        Db::name('notification_dispatches')->where('id', $dispatchId)->update([
            'fan_out_status' => 'failed',
            'fan_out_error' => 'partial write',
        ]);

        (new NotificationDispatchService())->retryFanOut($dispatchId);

        self::assertSame(
            $recipientCount,
            (int) Db::name('learner_notifications')->where('dispatch_id', $dispatchId)->count(),
        );
        self::assertSame(
            1,
            (int) Db::name('learner_notifications')
                ->where('dispatch_id', $dispatchId)
                ->where('learner_id', $learnerA)
                ->count(),
        );
        self::assertSame(
            1,
            (int) Db::name('learner_notifications')
                ->where('dispatch_id', $dispatchId)
                ->where('learner_id', $learnerB)
                ->count(),
        );
    }

    public function testRetryRejectsCompletedOrRunningDispatch(): void
    {
        $courseId = $this->insertCourse();
        $this->insertLearner();
        (new CourseService())->publishCourse($courseId, $this->staffId);
        $dispatchId = (int) Db::name('notification_dispatches')
            ->where('type', 'course_published')
            ->where('resource_id', $courseId)
            ->value('id');

        $dispatchService = new NotificationDispatchService();
        $this->expectException(BusinessException::class);
        $this->expectExceptionMessage('DISPATCH_NOT_RETRYABLE');
        $dispatchService->retryFanOut($dispatchId);
    }

    public function testCoursePublishedDispatchExposesResourceFieldsInList(): void
    {
        $courseId = $this->insertCourse();
        $this->insertLearner();
        (new CourseService())->publishCourse($courseId, $this->staffId);

        $list = (new NotificationDispatchService())->list(['type' => 'course_published']);
        $items = array_values(array_filter(
            $list['items'],
            static fn (array $item): bool => (int) $item['resource_id'] === $courseId,
        ));
        self::assertCount(1, $items);
        $item = $items[0];
        self::assertSame('course_published', $item['type']);
        self::assertSame('course', $item['resource_type']);
        self::assertSame($courseId, $item['resource_id']);
        self::assertSame('全体在册学员', $item['recipient_summary']);
        self::assertArrayNotHasKey('body', $item);
    }

    // ─── helpers ─────────────────────────────────────────────────────────

    private function insertCourse(): int
    {
        $now = date('Y-m-d H:i:s');
        $deptId = (int) Db::name('departments')->insertGetId([
            'parent_id' => null,
            'name' => 'notify-dept-' . bin2hex(random_bytes(3)),
            'path' => '/',
            'depth' => 1,
            'sort' => 0,
            'status' => 'enabled',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $categoryId = (int) Db::name('categories')->insertGetId([
            'name' => 'notify-cat-' . bin2hex(random_bytes(3)),
            'parent_id' => 0,
            'path' => '/',
            'depth' => 1,
            'sort' => 0,
            'status' => 'enabled',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $courseId = (int) Db::name('courses')->insertGetId([
            'department_id' => $deptId,
            'category_id' => $categoryId,
            'title' => '发布通知测试课 ' . bin2hex(random_bytes(3)),
            'teacher_name' => '教师甲',
            'summary' => '课程发布通知验证摘要',
            'intro_rich_text' => '<p>课程简介</p>',
            'status' => 'draft',
            'price_mode' => 'paid',
            'list_price' => 100,
            'sale_price' => 0,
            'created_by_staff_id' => $this->staffId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $chapterId = (int) Db::name('chapters')->insertGetId([
            'course_id' => $courseId,
            'title' => '第一章',
            'sort' => 0,
            'status' => 'enabled',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        Db::name('lessons')->insert([
            'chapter_id' => $chapterId,
            'title' => '第一节',
            'sort' => 0,
            'status' => 'enabled',
            'content_type' => 'markdown',
            'body_markdown' => '# 内容',
            'is_preview' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        return $courseId;
    }

    private function insertStaff(): int
    {
        $now = date('Y-m-d H:i:s');
        $id = (int) Db::name('accounts')->insertGetId([
            'kind' => 'staff',
            'login' => 'publish-admin-' . bin2hex(random_bytes(4)),
            'password_hash' => 'hash',
            'must_change_password' => 0,
            'status' => 'active',
            'last_login_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        Db::name('staff_users')->insert([
            'account_id' => $id,
            'is_super_admin' => 1,
            'department_id' => null,
            'display_name' => 'Publish Admin',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        return $id;
    }

    private function insertLimitedStaff(): int
    {
        $now = date('Y-m-d H:i:s');
        $id = (int) Db::name('accounts')->insertGetId([
            'kind' => 'staff',
            'login' => 'publish-limited-' . bin2hex(random_bytes(4)),
            'password_hash' => 'hash',
            'must_change_password' => 0,
            'status' => 'active',
            'last_login_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        Db::name('staff_users')->insert([
            'account_id' => $id,
            'is_super_admin' => 0,
            'department_id' => null,
            'display_name' => 'Limited Publish Staff',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        return $id;
    }

    private function insertLearner(): int
    {
        $now = date('Y-m-d H:i:s');
        $id = (int) Db::name('accounts')->insertGetId([
            'kind' => 'learner',
            'login' => '13' . random_int(100000000, 999999999),
            'password_hash' => 'hash',
            'must_change_password' => 0,
            'status' => 'active',
            'last_login_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        Db::name('learners')->insert([
            'account_id' => $id,
            'nickname' => '学员' . random_int(1000, 9999),
            'avatar_url' => null,
            'show_on_course' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        return $id;
    }
}
