<?php

declare(strict_types=1);

namespace Tests;

use App\service\CourseStudentService;
use App\service\DataScopeService;
use App\service\EntitlementService;
use App\service\PublicCatalogService;
use PHPUnit\Framework\TestCase;
use support\App;
use support\think\Db;
use Throwable;
use Webman\ThinkOrm\ThinkOrm;

final class CourseStudentTest extends TestCase
{
    private int $staffId;
    private int $courseId;
    private int $learnerId;
    private int $lessonId;

    public static function setUpBeforeClass(): void
    {
        App::loadAllConfig(['route', 'container']);
        ThinkOrm::start(null);
    }

    protected function setUp(): void
    {
        // @phpstan-ignore-next-line ThinkORM exposes transaction methods through a facade.
        Db::startTrans();
        try {
            $this->seedFixture();
        } catch (Throwable $exception) {
            // @phpstan-ignore-next-line ThinkORM exposes transaction methods through a facade.
            Db::rollback();
            throw $exception;
        }
    }

    protected function tearDown(): void
    {
        // @phpstan-ignore-next-line ThinkORM exposes transaction methods through a facade.
        Db::rollback();
    }

    public function testListUsesLearnerProfileAndCourseProgress(): void
    {
        self::assertTrue(class_exists(CourseStudentService::class), 'CourseStudentService is required');
        $service = new CourseStudentService(new DataScopeService(), new EntitlementService());

        $result = $service->listForCourse($this->staffId, $this->courseId, [
            'status' => 'active',
            'page' => 1,
            'limit' => 20,
        ]);

        self::assertSame(1, $result['total']);
        self::assertSame('课程学员', $result['items'][0]['nickname']);
        self::assertSame(40, $result['items'][0]['progress_percent']);
        self::assertArrayNotHasKey('department_id', $result['items'][0]);
    }

    public function testResetProgressClearsCourseRowsAndEmitsOneNotification(): void
    {
        $service = new CourseStudentService(new DataScopeService(), new EntitlementService());
        self::assertTrue(method_exists($service, 'resetProgress'), 'CourseStudentService must reset progress');

        $result = $service->resetProgress($this->staffId, $this->courseId, $this->learnerId);

        self::assertSame(['reset' => true], $result);
        self::assertSame(0, Db::name('lesson_progresses')->where('learner_id', $this->learnerId)->count());
        self::assertSame(0, (int) Db::name('course_enrollments')
            ->where('learner_id', $this->learnerId)
            ->where('course_id', $this->courseId)
            ->value('progress_percent'));
        self::assertSame(1, Db::name('learner_notifications')
            ->where('learner_id', $this->learnerId)
            ->where('kind', 'progress_reset')
            ->where('resource_type', 'course')
            ->where('resource_id', $this->courseId)
            ->count());
    }

    public function testRevokeFreeEntitlementKeepsProgressAndLinksNotificationToCourse(): void
    {
        $service = new CourseStudentService(new DataScopeService(), new EntitlementService());

        self::assertSame(
            ['revoked' => true],
            $service->revokeFree($this->staffId, $this->courseId, $this->learnerId, '课程调整'),
        );
        self::assertSame('revoked', Db::name('course_entitlements')
            ->where('learner_id', $this->learnerId)
            ->where('course_id', $this->courseId)
            ->value('status'));
        self::assertSame(40, (int) Db::name('course_enrollments')
            ->where('learner_id', $this->learnerId)
            ->where('course_id', $this->courseId)
            ->value('progress_percent'));
        $notification = Db::name('learner_notifications')
            ->where('learner_id', $this->learnerId)
            ->where('kind', 'entitlement_revoked')
            ->find();
        self::assertIsArray($notification);
        self::assertSame('course', $notification['resource_type']);
        self::assertSame($this->courseId, (int) $notification['resource_id']);
        $entitlementId = (int) Db::name('course_entitlements')
            ->where('learner_id', $this->learnerId)
            ->where('course_id', $this->courseId)
            ->value('id');
        self::assertSame('entitlement_revoked:' . $entitlementId, $notification['idempotency_key']);
    }

    public function testFiltersBySourceAndLearningStatusAndReturnsLastLearningTime(): void
    {
        $paidLearnerId = $this->seedAdditionalLearner('purchase', 100);
        $service = new CourseStudentService(new DataScopeService(), new EntitlementService());

        $result = $service->listForCourse($this->staffId, $this->courseId, [
            'source' => 'purchase',
            'learning_status' => 'completed',
            'page' => 1,
            'limit' => 20,
        ]);

        self::assertSame(1, $result['total']);
        self::assertSame($paidLearnerId, $result['items'][0]['account_id']);
        self::assertSame('completed', $result['items'][0]['learning_status']);
        self::assertNotNull($result['items'][0]['last_learning_at']);
    }

    public function testRejoinedLearnerAppearsOnceUsingTheNewestEntitlement(): void
    {
        $now = date('Y-m-d H:i:s');
        Db::name('course_entitlements')
            ->where('learner_id', $this->learnerId)
            ->where('course_id', $this->courseId)
            ->update([
                'status' => 'revoked',
                'revoked_at' => $now,
                'revoked_reason' => 'temporary',
                'revoked_by_staff_id' => $this->staffId,
                'updated_at' => $now,
            ]);
        Db::name('course_entitlements')->insert([
            'learner_id' => $this->learnerId,
            'course_id' => $this->courseId,
            'source' => 'free',
            'order_id' => null,
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $result = (new CourseStudentService(new DataScopeService(), new EntitlementService()))
            ->listForCourse($this->staffId, $this->courseId, ['page' => 1, 'limit' => 20]);

        self::assertSame(1, $result['total']);
        self::assertSame('active', $result['items'][0]['entitlement_status']);
    }

    public function testPublicCourseDetailExposesOnlyAggregateLearnerCount(): void
    {
        $detail = (new PublicCatalogService(new EntitlementService()))
            ->courseDetail($this->courseId, null);

        self::assertSame(1, $detail['course']['learner_count']);
        self::assertArrayNotHasKey('learners', $detail['course']);
        self::assertArrayNotHasKey('login', $detail['course']);
        self::assertArrayNotHasKey('progress_percent', $detail['course']);
    }

    private function seedAdditionalLearner(string $source, int $progress): int
    {
        $now = date('Y-m-d H:i:s');
        $learnerId = (int) Db::name('accounts')->insertGetId([
            'kind' => 'learner',
            'login' => '137' . str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT),
            'password_hash' => password_hash('test-password', PASSWORD_DEFAULT),
            'must_change_password' => 0,
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        Db::name('learners')->insert([
            'account_id' => $learnerId,
            'nickname' => '付费学员',
            'avatar_url' => null,
            'show_on_course' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        Db::name('course_entitlements')->insert([
            'learner_id' => $learnerId,
            'course_id' => $this->courseId,
            'source' => $source,
            'order_id' => null,
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        Db::name('course_enrollments')->insert([
            'learner_id' => $learnerId,
            'course_id' => $this->courseId,
            'progress_percent' => $progress,
            'last_lesson_id' => $this->lessonId,
            'last_position' => 1,
            'completed_at' => $progress === 100 ? $now : null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        return $learnerId;
    }

    private function seedFixture(): void
    {
        $now = date('Y-m-d H:i:s');
        $suffix = bin2hex(random_bytes(5));
        $this->staffId = (int) Db::name('accounts')->insertGetId([
            'kind' => 'staff',
            'login' => "student-admin-{$suffix}",
            'password_hash' => password_hash('test-password', PASSWORD_DEFAULT),
            'must_change_password' => 0,
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        Db::name('staff_users')->insert([
            'account_id' => $this->staffId,
            'is_super_admin' => 1,
            'department_id' => null,
            'display_name' => '课程管理员',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $departmentId = (int) Db::name('departments')->insertGetId([
            'parent_id' => null,
            'name' => "课程学员部门 {$suffix}",
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
            'name' => "课程学员分类 {$suffix}",
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
            'title' => "课程学员测试 {$suffix}",
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
        $this->learnerId = (int) Db::name('accounts')->insertGetId([
            'kind' => 'learner',
            'login' => '139' . str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT),
            'password_hash' => password_hash('test-password', PASSWORD_DEFAULT),
            'must_change_password' => 0,
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        Db::name('learners')->insert([
            'account_id' => $this->learnerId,
            'nickname' => '课程学员',
            'avatar_url' => null,
            'show_on_course' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        Db::name('course_entitlements')->insert([
            'learner_id' => $this->learnerId,
            'course_id' => $this->courseId,
            'source' => 'free',
            'order_id' => null,
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        Db::name('course_enrollments')->insert([
            'learner_id' => $this->learnerId,
            'course_id' => $this->courseId,
            'progress_percent' => 40,
            'last_lesson_id' => null,
            'last_position' => 0,
            'completed_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $chapterId = (int) Db::name('chapters')->insertGetId([
            'course_id' => $this->courseId,
            'title' => '进度章节',
            'sort' => 0,
            'status' => 'enabled',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $this->lessonId = (int) Db::name('lessons')->insertGetId([
            'chapter_id' => $chapterId,
            'title' => '进度课节',
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
        Db::name('lesson_progresses')->insert([
            'learner_id' => $this->learnerId,
            'lesson_id' => $this->lessonId,
            'position_seconds' => 1,
            'completed' => 1,
            'completed_at' => $now,
            'opened_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
}
