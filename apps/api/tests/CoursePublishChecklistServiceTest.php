<?php

declare(strict_types=1);

namespace Tests;

use App\service\BusinessException;
use App\service\CoursePublishChecklistService;
use App\service\FakeAssetReachabilityProbe;
use App\service\CourseService;
use PHPUnit\Framework\TestCase;
use support\App;
use support\think\Db;
use Webman\ThinkOrm\ThinkOrm;

final class CoursePublishChecklistServiceTest extends TestCase
{
    private int $staffId;
    private int $courseId;
    private int $chapterId;
    private int $lessonId;
    private CoursePublishChecklistService $service;

    public static function setUpBeforeClass(): void
    {
        App::loadAllConfig(['route', 'container']);
        ThinkOrm::start(null);
    }

    protected function setUp(): void
    {
        Db::startTrans();
        $this->staffId = $this->insert('accounts', ['kind' => 'staff', 'login' => 'check-' . bin2hex(random_bytes(6)), 'password_hash' => 'hash', 'status' => 'active']);
        $this->insert('staff_users', ['account_id' => $this->staffId, 'is_super_admin' => 1, 'display_name' => 'Checklist']);
        $department = $this->insert('departments', ['name' => 'Checklist', 'path' => '/', 'depth' => 1, 'status' => 'enabled']);
        $category = $this->insert('categories', ['name' => 'Checklist', 'parent_id' => 0, 'path' => '/', 'depth' => 1, 'status' => 'enabled']);
        $this->courseId = $this->insert('courses', ['department_id' => $department, 'category_id' => $category, 'title' => 'Checklist', 'teacher_name' => 'Teacher', 'summary' => 'Summary', 'intro_rich_text' => '<p>Intro</p>', 'status' => 'draft', 'price_mode' => 'free', 'created_by_staff_id' => $this->staffId]);
        $this->chapterId = $this->insert('chapters', ['course_id' => $this->courseId, 'title' => 'Chapter', 'status' => 'enabled']);
        $this->lessonId = $this->insert('lessons', ['chapter_id' => $this->chapterId, 'title' => 'Lesson', 'status' => 'enabled', 'content_type' => 'markdown', 'body_markdown' => '# Content']);
        $this->service = new CoursePublishChecklistService(new FakeAssetReachabilityProbe());
    }

    protected function tearDown(): void
    {
        Db::rollback();
    }

    public function testBuildDoesNotChangeStatusOrDispatch(): void
    {
        $before = Db::name('notification_dispatches')->count();
        $dto = $this->build();
        self::assertTrue($dto['can_publish']);
        self::assertSame('draft', Db::name('courses')->where('id', $this->courseId)->value('status'));
        self::assertSame($before, Db::name('notification_dispatches')->count());
        self::assertSame((int) Db::name('accounts')->alias('a')->join('learners l', 'l.account_id = a.id')->where('a.status', 'active')->count(), $dto['impact']['notification']['recipient_count']);
    }

    public function testBuildFingerprintAndHardErrorsStable(): void
    {
        $a = $this->build();
        $b = $this->build();
        self::assertSame($a['content_fingerprint'], $b['content_fingerprint']);
        self::assertSame($a['findings'], $b['findings']);
    }

    public function testBuildListsHardErrorsWithLocations(): void
    {
        Db::name('categories')->where('id', Db::name('courses')->where('id', $this->courseId)->value('category_id'))->update(['status' => 'disabled']);
        Db::name('courses')->where('id', $this->courseId)->update(['intro_rich_text' => '']);
        Db::name('lessons')->where('id', $this->lessonId)->update(['body_markdown' => '']);
        $dto = $this->build();
        foreach (['CATEGORY_DISABLED', 'INTRO_REQUIRED', 'NO_PUBLISHABLE_LESSON'] as $code) {
            self::assertContains($code, array_column($dto['findings'], 'code'));
        }
        self::assertFalse($dto['can_publish']);
        self::assertSame('course', $dto['findings'][0]['scope']);
    }

    public function testBuildSaleWindowExpiredIsHard(): void
    {
        Db::name('courses')->where('id', $this->courseId)->update(['price_mode' => 'paid', 'list_price' => 100, 'sale_price' => 50, 'sale_start_at' => '2020-01-01 00:00:00', 'sale_end_at' => '2020-02-01 00:00:00']);
        self::assertContains('SALE_WINDOW_EXPIRED', array_column($this->build()['findings'], 'code'));
    }

    public function testBuildNoPublishableChapterIsHard(): void
    {
        Db::name('chapters')->where('id', $this->chapterId)->update(['status' => 'disabled']);
        self::assertContains('NO_PUBLISHABLE_CHAPTER', array_column($this->build()['findings'], 'code'));
    }

    public function testBuildIncompleteExtraLessonIsWarning(): void
    {
        $asset = $this->insert('assets', ['kind' => 'video', 'storage_path' => 'uploads/2026/09/1234567890abcdef.mp4', 'mime_type' => 'video/mp4', 'status' => 'processing', 'created_by_staff_id' => $this->staffId]);
        $this->insert('lessons', ['chapter_id' => $this->chapterId, 'title' => 'Processing', 'status' => 'enabled', 'content_type' => 'video', 'asset_id' => $asset]);
        $dto = $this->build();
        self::assertSame(0, $dto['hard_error_count']);
        self::assertContains('ASSET_PROCESSING', array_column($dto['findings'], 'code'));
        self::assertCount(2, $dto['catalog']['chapters'][0]['lessons']);
    }

    public function testBuildEnumeratesArchivedAndIncomplete(): void
    {
        Db::name('chapters')->where('id', $this->chapterId)->update(['status' => 'disabled']);
        $dto = $this->build();
        self::assertCount(1, $dto['catalog']['chapters']);
        self::assertFalse($dto['catalog']['chapters'][0]['lessons'][0]['is_effective']);
    }

    public function testBuildRejectsOutOfScope(): void
    {
        Db::name('staff_users')->where('account_id', $this->staffId)->update(['is_super_admin' => 0]);
        $this->expectException(BusinessException::class);
        $this->build();
    }

    public function testPublishStalePreviewRerunsBuild(): void
    {
        self::assertTrue($this->build()['can_publish']);
        Db::name('lessons')->where('id', $this->lessonId)->update(['status' => 'disabled']);
        try {
            (new CourseService($this->service))->publishCourse($this->courseId, $this->staffId, true);
            self::fail('Hard errors must block publication');
        } catch (BusinessException $e) {
            self::assertSame('PUBLISH_CHECK_FAILED', $e->getMessage());
            self::assertGreaterThan(0, $e->details['checklist']['hard_error_count']);
        }
        self::assertSame('draft', Db::name('courses')->where('id', $this->courseId)->value('status'));
        self::assertSame(0, Db::name('notification_dispatches')->where('resource_id', $this->courseId)->count());
    }

    public function testWarningsRequireAcknowledge(): void
    {
        $this->insert('lessons', ['chapter_id' => $this->chapterId, 'title' => 'Incomplete', 'status' => 'enabled', 'content_type' => 'markdown', 'body_markdown' => '']);
        $service = new CourseService($this->service);
        try {
            $service->publishCourse($this->courseId, $this->staffId);
            self::fail('Warnings need acknowledgment');
        } catch (BusinessException $e) {
            self::assertSame('WARNINGS_NOT_ACKNOWLEDGED', $e->getMessage());
        }
        self::assertSame(0, Db::name('notification_dispatches')->where('resource_id', $this->courseId)->count());
        $service->publishCourse($this->courseId, $this->staffId, true);
        $service->publishCourse($this->courseId, $this->staffId);
        self::assertSame(1, Db::name('notification_dispatches')->where('resource_id', $this->courseId)->count());
        self::assertSame(1, Db::name('audit_log')->where('target_id', $this->courseId)->where('action', 'course.publish')->count());
    }

    public function testProbeFailureIsWarningNotHard(): void
    {
        $asset = $this->insert('assets', ['kind' => 'video', 'storage_path' => 'uploads/2026/09/1234567890abcdef.mp4', 'mime_type' => 'video/mp4', 'status' => 'ready', 'created_by_staff_id' => $this->staffId]);
        Db::name('lessons')->where('id', $this->lessonId)->update(['content_type' => 'video', 'asset_id' => $asset]);
        $a = $this->build();
        $this->service = new CoursePublishChecklistService(new FakeAssetReachabilityProbe(false));
        $b = $this->build();
        self::assertSame($a['content_fingerprint'], $b['content_fingerprint']);
        self::assertSame(0, $b['hard_error_count']);
        self::assertContains('ASSET_UNREACHABLE', array_column($b['findings'], 'code'));
        self::assertTrue($b['catalog']['chapters'][0]['lessons'][0]['is_effective']);
    }

    public function testPublishPreservesProgressEntitlementsAndOrderSnapshots(): void
    {
        $learner = $this->insert('accounts', ['kind' => 'learner', 'login' => '13' . random_int(100000000, 999999999), 'password_hash' => 'hash', 'status' => 'active']);
        $this->insert('learners', ['account_id' => $learner, 'nickname' => 'Learner']);
        $entitlement = $this->insert('course_entitlements', ['learner_id' => $learner, 'course_id' => $this->courseId, 'source' => 'free', 'status' => 'active']);
        $enrollment = $this->insert('course_enrollments', ['learner_id' => $learner, 'course_id' => $this->courseId, 'progress_percent' => 100, 'completed_at' => '2026-01-01 00:00:00']);
        $progress = $this->insert('lesson_progresses', ['learner_id' => $learner, 'lesson_id' => $this->lessonId, 'completed' => 1]);
        $order = $this->insert('orders', ['learner_id' => $learner, 'course_id' => $this->courseId, 'provider' => 'fake', 'status' => 'succeeded', 'list_price_snapshot' => 100, 'sale_price_snapshot' => 80, 'paid_amount' => 80]);
        $orderBefore = Db::name('orders')->where('id', $order)->find();
        $entitlementBefore = Db::name('course_entitlements')->where('id', $entitlement)->find();
        $enrollmentBefore = Db::name('course_enrollments')->where('id', $enrollment)->find();
        $this->insert('lessons', ['chapter_id' => $this->chapterId, 'title' => 'New', 'status' => 'enabled', 'content_type' => 'markdown', 'body_markdown' => 'New']);
        $this->insert('lessons', ['chapter_id' => $this->chapterId, 'title' => 'Incomplete', 'status' => 'enabled', 'content_type' => 'markdown', 'body_markdown' => '']);
        $dto = $this->build();
        self::assertSame(1, $dto['impact']['entitlements']['active_count']);
        self::assertTrue($dto['impact']['progress']['will_recalculate']);
        self::assertSame($enrollmentBefore, Db::name('course_enrollments')->where('id', $enrollment)->find());
        (new CourseService($this->service))->publishCourse($this->courseId, $this->staffId, true);
        self::assertSame($orderBefore, Db::name('orders')->where('id', $order)->find());
        self::assertSame($entitlementBefore, Db::name('course_entitlements')->where('id', $entitlement)->find());
        self::assertSame(1, (int) Db::name('lesson_progresses')->where('id', $progress)->value('completed'));
        self::assertSame(50, (int) Db::name('course_enrollments')->where('id', $enrollment)->value('progress_percent'));
        self::assertSame('2026-01-01 00:00:00', Db::name('course_enrollments')->where('id', $enrollment)->value('completed_at'));
        self::assertSame(1, Db::name('learner_notifications')->where('kind', 'progress_catalog_changed')->where('resource_id', $this->courseId)->where('learner_id', $learner)->count());
    }

    public function testNativeProbeRejectsMissingFilesAndTraversal(): void
    {
        $probe = new \App\service\NativeAssetReachabilityProbe();
        self::assertFalse($probe->probe('../composer.json')['reachable']);
        self::assertFalse($probe->probe('uploads/2026/09/0000000000000000.mp4')['reachable']);
    }

    public function testImpactRecipientUnavailableIsHard(): void
    {
        $this->service = new CoursePublishChecklistService(new FakeAssetReachabilityProbe(), static function (): array {
            throw new \RuntimeException('unavailable');
        });
        $dto = $this->build();
        self::assertNull($dto['impact']['notification']['recipient_count']);
        self::assertFalse($dto['can_publish']);
        try {
            (new CourseService($this->service))->publishCourse($this->courseId, $this->staffId, true);
            self::fail('Unavailable audience must block publish');
        } catch (BusinessException $e) {
            self::assertSame('NOTIFICATION_IMPACT_UNAVAILABLE', $e->getMessage());
        }
        self::assertSame('draft', Db::name('courses')->where('id', $this->courseId)->value('status'));
        self::assertSame(0, Db::name('notification_dispatches')->where('resource_id', $this->courseId)->count());
    }

    public function testImpactPublishedMapsRecover(): void
    {
        self::assertSame([], $this->build()['impact']['maps']['items']);
        foreach (['draft', 'published'] as $status) {
            $map = $this->insert('learning_maps', ['department_id' => Db::name('courses')->where('id', $this->courseId)->value('department_id'), 'title' => $status, 'status' => $status, 'created_by_staff_id' => $this->staffId]);
            $stage = $this->insert('map_stages', ['map_id' => $map, 'title' => 'Stage']);
            Db::name('map_stage_courses')->insert(['map_id' => $map, 'stage_id' => $stage, 'course_id' => $this->courseId, 'sort_order' => 0, 'created_at' => date('Y-m-d H:i:s')]);
        }
        $maps = $this->build()['impact']['maps'];
        self::assertSame(1, $maps['published_count']);
        self::assertSame(1, $maps['draft_count']);
        self::assertSame('published', $maps['items'][0]['title']);
        self::assertTrue($maps['items'][0]['will_recover_abnormal_step']);
    }

    /** @return array<string, mixed> */
    private function build(): array
    {
        return $this->service->build($this->courseId, $this->staffId);
    }

    /** @param array<string, mixed> $row */
    private function insert(string $table, array $row): int
    {
        $now = date('Y-m-d H:i:s');
        return (int) Db::name($table)->insertGetId($row + ['created_at' => $now, 'updated_at' => $now]);
    }
}
