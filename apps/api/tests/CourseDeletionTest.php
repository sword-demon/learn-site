<?php

declare(strict_types=1);

namespace Tests;

use App\controller\admin\CourseController;
use App\service\BusinessException;
use App\service\CourseService;
use App\service\EntitlementService;
use App\service\PublicCatalogService;
use ArrayObject;
use PHPUnit\Framework\TestCase;
use support\App;
use support\Context;
use support\Request;
use support\think\Db;
use Webman\ThinkOrm\ThinkOrm;

final class CourseDeletionTest extends TestCase
{
    private int $staffId;
    private int $departmentId;
    private int $categoryId;

    public static function setUpBeforeClass(): void
    {
        App::loadAllConfig(['route', 'container']);
        ThinkOrm::start(null);
    }

    protected function setUp(): void
    {
        // @phpstan-ignore-next-line ThinkORM exposes transaction methods through a facade.
        Db::startTrans();
        $this->seedCatalogOwner();
    }

    protected function tearDown(): void
    {
        // @phpstan-ignore-next-line ThinkORM exposes transaction methods through a facade.
        Db::rollback();
        Context::reset();
    }

    public function testPublishedCourseMustBeUnpublishedBeforeDeletion(): void
    {
        $courseId = $this->insertCourse('published');

        try {
            (new CourseService())->deleteCourse($courseId, $this->staffId);
            self::fail('Expected a published course to be rejected.');
        } catch (BusinessException $exception) {
            self::assertSame('CONFLICT', $exception->apiCode);
            self::assertSame('COURSE_DELETE_REQUIRES_UNPUBLISHED', $exception->getMessage());
        }

        self::assertSame(1, (int) Db::name('courses')->where('id', $courseId)->count());
    }

    public function testCourseWithAnOrderCannotBeDeleted(): void
    {
        $courseId = $this->insertCourse();
        $learnerId = $this->insertLearner();
        $now = date('Y-m-d H:i:s');
        Db::name('orders')->insert([
            'learner_id' => $learnerId,
            'course_id' => $courseId,
            'list_price_snapshot' => 99,
            'sale_price_snapshot' => 0,
            'paid_amount' => 0,
            'currency' => 'CNY',
            'status' => 'pending',
            'provider' => 'fake',
            'provider_ref' => 'delete-order-' . bin2hex(random_bytes(6)),
            'succeeded_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        try {
            (new CourseService())->deleteCourse($courseId, $this->staffId);
            self::fail('Expected an order reference to block course deletion.');
        } catch (BusinessException $exception) {
            self::assertSame('CONFLICT', $exception->apiCode);
            self::assertSame('COURSE_HAS_ORDERS', $exception->getMessage());
        }

        self::assertSame(1, (int) Db::name('courses')->where('id', $courseId)->count());
    }

    public function testCourseWithEntitlementHistoryCannotBeDeleted(): void
    {
        $courseId = $this->insertCourse();
        $learnerId = $this->insertLearner();
        $now = date('Y-m-d H:i:s');
        Db::name('course_entitlements')->insert([
            'learner_id' => $learnerId,
            'course_id' => $courseId,
            'source' => 'free',
            'order_id' => null,
            'status' => 'revoked',
            'revoked_at' => $now,
            'revoked_reason' => 'Deletion fixture',
            'revoked_by_staff_id' => $this->staffId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        try {
            (new CourseService())->deleteCourse($courseId, $this->staffId);
            self::fail('Expected entitlement history to block course deletion.');
        } catch (\Throwable $exception) {
            self::assertInstanceOf(BusinessException::class, $exception);
            self::assertSame('CONFLICT', $exception->apiCode);
            self::assertSame('COURSE_HAS_ENTITLEMENTS', $exception->getMessage());
        }

        self::assertSame(1, (int) Db::name('courses')->where('id', $courseId)->count());
    }

    public function testCourseWithEnrollmentCannotBeDeleted(): void
    {
        $courseId = $this->insertCourse();
        $learnerId = $this->insertLearner();
        $now = date('Y-m-d H:i:s');
        Db::name('course_enrollments')->insert([
            'learner_id' => $learnerId,
            'course_id' => $courseId,
            'progress_percent' => 0,
            'last_lesson_id' => null,
            'last_position' => 0,
            'completed_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        try {
            (new CourseService())->deleteCourse($courseId, $this->staffId);
            self::fail('Expected an enrollment to block course deletion.');
        } catch (BusinessException $exception) {
            self::assertSame('CONFLICT', $exception->apiCode);
            self::assertSame('COURSE_HAS_LEARNING_RECORDS', $exception->getMessage());
        }

        self::assertSame(1, (int) Db::name('courses')->where('id', $courseId)->count());
    }

    public function testCourseWithLessonProgressCannotBeDeletedWithoutEnrollment(): void
    {
        $courseId = $this->insertCourse();
        $lessonId = $this->insertMarkdownLesson($courseId);
        $learnerId = $this->insertLearner();
        $now = date('Y-m-d H:i:s');
        Db::name('lesson_progresses')->insert([
            'learner_id' => $learnerId,
            'lesson_id' => $lessonId,
            'position_seconds' => 1,
            'completed' => 0,
            'completed_at' => null,
            'opened_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        try {
            (new CourseService())->deleteCourse($courseId, $this->staffId);
            self::fail('Expected lesson progress to block course deletion.');
        } catch (\Throwable $exception) {
            self::assertInstanceOf(BusinessException::class, $exception);
            self::assertSame('CONFLICT', $exception->apiCode);
            self::assertSame('COURSE_HAS_LEARNING_RECORDS', $exception->getMessage());
        }

        self::assertSame(1, (int) Db::name('courses')->where('id', $courseId)->count());
    }

    public function testOutOfScopeStaffCannotDeleteCourse(): void
    {
        $courseId = $this->insertCourse();
        $outsideStaffId = $this->insertOutsideStaff();

        try {
            (new CourseService())->deleteCourse($courseId, $outsideStaffId);
            self::fail('Expected an out-of-scope actor to be rejected.');
        } catch (BusinessException $exception) {
            self::assertSame('FORBIDDEN', $exception->apiCode);
            self::assertSame('DEPARTMENT_OUT_OF_SCOPE', $exception->getMessage());
        }

        self::assertSame(1, (int) Db::name('courses')->where('id', $courseId)->count());
    }

    public function testCourseReferencedByLearningMapCannotBeDeleted(): void
    {
        $courseId = $this->insertCourse();
        $now = date('Y-m-d H:i:s');
        $mapId = (int) Db::name('learning_maps')->insertGetId([
            'department_id' => $this->departmentId,
            'title' => 'Delete Map',
            'summary' => 'Course deletion fixture',
            'cover_url' => null,
            'objective' => null,
            'audience' => null,
            'status' => 'draft',
            'created_by_staff_id' => $this->staffId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $stageId = (int) Db::name('map_stages')->insertGetId([
            'map_id' => $mapId,
            'title' => 'Delete Stage',
            'summary' => null,
            'sort_order' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        Db::name('map_stage_courses')->insert([
            'stage_id' => $stageId,
            'map_id' => $mapId,
            'course_id' => $courseId,
            'sort_order' => 0,
            'created_at' => $now,
        ]);

        try {
            (new CourseService())->deleteCourse($courseId, $this->staffId);
            self::fail('Expected a learning map reference to block course deletion.');
        } catch (\Throwable $exception) {
            self::assertInstanceOf(BusinessException::class, $exception);
            self::assertSame('CONFLICT', $exception->apiCode);
            self::assertSame('COURSE_IN_LEARNING_MAP', $exception->getMessage());
        }

        self::assertSame(1, (int) Db::name('courses')->where('id', $courseId)->count());
    }

    public function testBlankDraftDeletionReturnsDeletedEnvelope(): void
    {
        $courseId = $this->insertCourse();
        $request = new Request(
            "DELETE /api/admin/v1/courses/$courseId HTTP/1.1\r\nHost: test\r\n\r\n",
        );
        $request->account_id = $this->staffId;
        Context::reset(new ArrayObject([\Webman\Http\Request::class => $request]));

        $response = (new CourseController(new CourseService()))->destroy($request, (string) $courseId);
        $payload = json_decode((string) $response->rawBody(), true);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['deleted' => true], $payload['data'] ?? null);
        self::assertSame(0, (int) Db::name('courses')->where('id', $courseId)->count());
    }

    public function testCourseDeletionRetainsDetachedAssetRecord(): void
    {
        $courseId = $this->insertCourse();
        $assetId = $this->insertAssetLesson($courseId);

        (new CourseService())->deleteCourse($courseId, $this->staffId);

        self::assertSame(0, (int) Db::name('courses')->where('id', $courseId)->count());
        self::assertSame(0, (int) Db::name('lessons')->where('asset_id', $assetId)->count());
        self::assertSame(1, (int) Db::name('assets')->where('id', $assetId)->count());
    }

    public function testDeletedCourseIsNotAvailableFromPublicCatalog(): void
    {
        $courseId = $this->insertCourse('unpublished');
        (new CourseService())->deleteCourse($courseId, $this->staffId);

        $this->expectException(BusinessException::class);
        $this->expectExceptionMessage('COURSE_NOT_FOUND');
        (new PublicCatalogService(new EntitlementService()))->courseDetail($courseId, null);
    }

    private function seedCatalogOwner(): void
    {
        $suffix = bin2hex(random_bytes(6));
        $now = date('Y-m-d H:i:s');
        $this->departmentId = (int) Db::name('departments')->insertGetId([
            'parent_id' => null,
            'name' => "Delete department $suffix",
            'path' => '/',
            'depth' => 1,
            'sort' => 0,
            'status' => 'enabled',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        Db::name('departments')->where('id', $this->departmentId)->update([
            'path' => "/{$this->departmentId}",
        ]);
        $this->staffId = (int) Db::name('accounts')->insertGetId([
            'kind' => 'staff',
            'login' => "delete-admin-$suffix",
            'password_hash' => 'not-used-by-test',
            'must_change_password' => 0,
            'status' => 'active',
            'last_login_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        Db::name('staff_users')->insert([
            'account_id' => $this->staffId,
            'is_super_admin' => 1,
            'department_id' => null,
            'display_name' => 'Delete Admin',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $this->categoryId = (int) Db::name('categories')->insertGetId([
            'parent_id' => 0,
            'name' => "Delete category $suffix",
            'path' => '/',
            'depth' => 1,
            'sort' => 0,
            'status' => 'enabled',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function insertCourse(string $status = 'draft'): int
    {
        $now = date('Y-m-d H:i:s');

        return (int) Db::name('courses')->insertGetId([
            'department_id' => $this->departmentId,
            'category_id' => $this->categoryId,
            'title' => "Delete course $status " . bin2hex(random_bytes(3)),
            'cover_url' => null,
            'teacher_name' => 'Delete Teacher',
            'summary' => 'Course deletion fixture',
            'intro_rich_text' => '<p>Course deletion fixture</p>',
            'status' => $status,
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

    private function insertLearner(): int
    {
        $now = date('Y-m-d H:i:s');
        $learnerId = (int) Db::name('accounts')->insertGetId([
            'kind' => 'learner',
            'login' => '139' . str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT),
            'password_hash' => 'not-used-by-test',
            'must_change_password' => 0,
            'status' => 'active',
            'last_login_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        Db::name('learners')->insert([
            'account_id' => $learnerId,
            'nickname' => 'Delete Learner',
            'avatar_url' => null,
            'show_on_course' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $learnerId;
    }

    private function insertMarkdownLesson(int $courseId): int
    {
        $now = date('Y-m-d H:i:s');
        $chapterId = (int) Db::name('chapters')->insertGetId([
            'course_id' => $courseId,
            'title' => 'Delete Chapter',
            'sort' => 0,
            'status' => 'enabled',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return (int) Db::name('lessons')->insertGetId([
            'chapter_id' => $chapterId,
            'title' => 'Delete Lesson',
            'sort' => 0,
            'status' => 'enabled',
            'content_type' => 'markdown',
            'body_markdown' => '# Delete fixture',
            'asset_id' => null,
            'is_preview' => 0,
            'duration_seconds' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function insertOutsideStaff(): int
    {
        $suffix = bin2hex(random_bytes(6));
        $now = date('Y-m-d H:i:s');
        $departmentId = (int) Db::name('departments')->insertGetId([
            'parent_id' => null,
            'name' => "Outside department $suffix",
            'path' => '/',
            'depth' => 1,
            'sort' => 0,
            'status' => 'enabled',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        Db::name('departments')->where('id', $departmentId)->update(['path' => "/$departmentId"]);
        $staffId = (int) Db::name('accounts')->insertGetId([
            'kind' => 'staff',
            'login' => "outside-delete-$suffix",
            'password_hash' => 'not-used-by-test',
            'must_change_password' => 0,
            'status' => 'active',
            'last_login_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        Db::name('staff_users')->insert([
            'account_id' => $staffId,
            'is_super_admin' => 0,
            'department_id' => $departmentId,
            'display_name' => 'Outside Delete Staff',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $staffId;
    }

    private function insertAssetLesson(int $courseId): int
    {
        $now = date('Y-m-d H:i:s');
        $assetId = (int) Db::name('assets')->insertGetId([
            'kind' => 'video',
            'storage_path' => 'videos/delete-fixture.mp4',
            'mime_type' => 'video/mp4',
            'size_bytes' => 1024,
            'status' => 'ready',
            'created_by_staff_id' => $this->staffId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $chapterId = (int) Db::name('chapters')->insertGetId([
            'course_id' => $courseId,
            'title' => 'Asset Chapter',
            'sort' => 0,
            'status' => 'enabled',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        Db::name('lessons')->insert([
            'chapter_id' => $chapterId,
            'title' => 'Asset Lesson',
            'sort' => 0,
            'status' => 'enabled',
            'content_type' => 'video',
            'body_markdown' => null,
            'asset_id' => $assetId,
            'is_preview' => 0,
            'duration_seconds' => 60,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $assetId;
    }
}
