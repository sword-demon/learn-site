<?php

declare(strict_types=1);

namespace Tests;

use App\controller\admin\CourseStudentController;
use App\middleware\Authorize;
use App\model\CourseEntitlement;
use App\service\BusinessException;
use App\service\CourseStudentService;
use App\service\DataScopeService;
use App\service\EntitlementService;
use App\service\ProgressService;
use App\service\PublicLessonService;
use PHPUnit\Framework\TestCase;
use support\App;
use support\Request;
use support\think\Db;
use Throwable;
use Webman\ThinkOrm\ThinkOrm;

final class EntitlementRevokeTest extends TestCase
{
    private int $staffId;
    private int $learnerId;
    private int $courseId;
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

    public function testCourseEntitlementDefinesTheOnlyRevocableState(): void
    {
        $free = new CourseEntitlement();
        $free->source = 'free';
        $free->status = 'active';
        self::assertTrue($free->isRevocable());

        $paid = new CourseEntitlement();
        $paid->source = 'purchase';
        $paid->status = 'active';
        self::assertFalse($paid->isRevocable());

        $revoked = new CourseEntitlement();
        $revoked->source = 'free';
        $revoked->status = 'revoked';
        self::assertFalse($revoked->isRevocable());
    }

    public function testFreeRevokeKeepsProgressAndRealGrantRestoresAccess(): void
    {
        $service = new EntitlementService();
        $original = $service->grant($this->learnerId, $this->courseId, 'free');
        $enrollmentId = $this->seedProgress(40);

        $service->revoke($this->learnerId, $this->courseId, '误加入测试课程', $this->staffId);

        $revoked = Db::name('course_entitlements')->where('id', $original['id'])->find();
        self::assertSame('revoked', $revoked['status']);
        self::assertSame('误加入测试课程', $revoked['revoked_reason']);
        self::assertSame($this->staffId, (int) $revoked['revoked_by_staff_id']);
        self::assertNotEmpty($revoked['revoked_at']);
        self::assertFalse($service->viewerAuthorized($this->courseId, $this->learnerId));
        self::assertSame(40, (int) Db::name('course_enrollments')->where('id', $enrollmentId)->value('progress_percent'));
        self::assertSame(1, (int) Db::name('lesson_progresses')
            ->where('learner_id', $this->learnerId)
            ->where('lesson_id', $this->lessonId)
            ->count());

        $notification = Db::name('learner_notifications')
            ->where('learner_id', $this->learnerId)
            ->where('kind', 'entitlement_revoked')
            ->find();
        self::assertIsArray($notification);
        self::assertSame('原因：误加入测试课程', $notification['body']);

        $learning = (new ProgressService($service))->listLearning($this->learnerId);
        self::assertSame('revoked', $learning['items'][0]['entitlement_status']);
        self::assertSame('free', $learning['items'][0]['entitlement_source']);
        self::assertSame('误加入测试课程', $learning['items'][0]['revoked_reason']);
        self::assertTrue($learning['items'][0]['can_rejoin']);

        $restored = $service->grant($this->learnerId, $this->courseId, 'free');

        self::assertNotSame($original['id'], $restored['id']);
        self::assertTrue($service->viewerAuthorized($this->courseId, $this->learnerId));
        self::assertSame(2, (int) Db::name('course_entitlements')
            ->where('learner_id', $this->learnerId)
            ->where('course_id', $this->courseId)
            ->count());
        self::assertSame(1, (int) Db::name('course_enrollments')
            ->where('learner_id', $this->learnerId)
            ->where('course_id', $this->courseId)
            ->count());
        self::assertSame(40, (int) Db::name('course_enrollments')->where('id', $enrollmentId)->value('progress_percent'));
    }

    public function testPaidEntitlementCannotBeRevoked(): void
    {
        $orderId = $this->insertSucceededOrder();
        (new EntitlementService())->grant($this->learnerId, $this->courseId, 'purchase', $orderId);

        try {
            (new EntitlementService())->revoke(
                $this->learnerId,
                $this->courseId,
                '不应生效',
                $this->staffId,
            );
            self::fail('Expected paid entitlement revocation to be rejected.');
        } catch (BusinessException $exception) {
            self::assertSame('FORBIDDEN', $exception->apiCode);
            self::assertSame('PAID_NOT_REVOCABLE', $exception->getMessage());
        }

        self::assertTrue((new EntitlementService())->viewerAuthorized($this->courseId, $this->learnerId));
    }

    public function testAdminEndpointMapsPaidRevokeToForbidden(): void
    {
        (new EntitlementService())->grant(
            $this->learnerId,
            $this->courseId,
            'purchase',
            $this->insertSucceededOrder(),
        );
        $request = $this->adminRevokeRequest('不应生效');

        $response = $this->courseStudentController()->revoke(
            $request,
            (string) $this->courseId,
            (string) $this->learnerId,
        );
        $payload = json_decode((string) $response->rawBody(), true);

        self::assertSame(403, $response->getStatusCode());
        self::assertSame('FORBIDDEN', $payload['error']['code'] ?? null);
        self::assertSame('PAID_NOT_REVOCABLE', $payload['error']['message'] ?? null);
    }

    public function testAdminEndpointRequiresARevokeReasonAndUsesOnlyTheRealRoute(): void
    {
        (new EntitlementService())->grant($this->learnerId, $this->courseId, 'free');
        $response = $this->courseStudentController()->revoke(
            $this->adminRevokeRequest('   '),
            (string) $this->courseId,
            (string) $this->learnerId,
        );
        $payload = json_decode((string) $response->rawBody(), true);

        self::assertSame(400, $response->getStatusCode());
        self::assertSame('VALIDATION_FAILED', $payload['error']['code'] ?? null);
        self::assertSame('REVOKE_REASON_REQUIRED', $payload['error']['message'] ?? null);
        self::assertSame(
            'course_student.revoke_free',
            Authorize::permissionFor(
                "/api/admin/v1/courses/{$this->courseId}/students/{$this->learnerId}/revoke",
                'POST',
            ),
        );
        self::assertNull(Authorize::permissionFor('/api/admin/v1/entitlements/1/revoke', 'POST'));
    }

    public function testUnpublishedCourseRemainsReadableWithAnActiveEntitlement(): void
    {
        (new EntitlementService())->grant(
            $this->learnerId,
            $this->courseId,
            'purchase',
            $this->insertSucceededOrder(),
        );
        Db::name('courses')->where('id', $this->courseId)->update(['status' => 'unpublished']);

        $delivery = (new PublicLessonService(new EntitlementService()))
            ->deliver($this->courseId, $this->lessonId, $this->learnerId);

        self::assertSame('markdown', $delivery['kind']);
        self::assertStringContainsString('保留访问', $delivery['html']);
    }

    private function seedProgress(int $percent): int
    {
        $now = date('Y-m-d H:i:s');
        Db::name('lesson_progresses')->insert([
            'learner_id' => $this->learnerId,
            'lesson_id' => $this->lessonId,
            'position_seconds' => 1,
            'completed' => 0,
            'completed_at' => null,
            'opened_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        Db::name('course_enrollments')
            ->where('learner_id', $this->learnerId)
            ->where('course_id', $this->courseId)
            ->update([
                'progress_percent' => $percent,
                'last_lesson_id' => $this->lessonId,
                'last_position' => 1,
                'updated_at' => $now,
            ]);
        return (int) Db::name('course_enrollments')
            ->where('learner_id', $this->learnerId)
            ->where('course_id', $this->courseId)
            ->value('id');
    }

    private function courseStudentController(): CourseStudentController
    {
        return new CourseStudentController(new CourseStudentService(
            new DataScopeService(),
            new EntitlementService(),
        ));
    }

    private function adminRevokeRequest(string $reason): Request
    {
        $body = json_encode(['reason' => $reason], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $request = new Request(
            "POST /api/admin/v1/courses/{$this->courseId}/students/{$this->learnerId}/revoke HTTP/1.1\r\n"
            . "Host: test\r\nContent-Type: application/json\r\nContent-Length: " . strlen($body) . "\r\n\r\n"
            . $body,
        );
        /** @phpstan-ignore-next-line Webman request attributes are assigned by AdminAuth in production. */
        $request->account_id = $this->staffId;
        return $request;
    }

    private function insertSucceededOrder(): int
    {
        $now = date('Y-m-d H:i:s');
        return (int) Db::name('orders')->insertGetId([
            'learner_id' => $this->learnerId,
            'course_id' => $this->courseId,
            'list_price_snapshot' => 99,
            'sale_price_snapshot' => 99,
            'paid_amount' => 99,
            'currency' => 'CNY',
            'status' => 'succeeded',
            'provider' => 'fake',
            'provider_ref' => 'revoke-test-' . bin2hex(random_bytes(5)),
            'succeeded_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function seedFixture(): void
    {
        $now = date('Y-m-d H:i:s');
        $suffix = bin2hex(random_bytes(5));
        $this->staffId = (int) Db::name('accounts')->insertGetId([
            'kind' => 'staff',
            'login' => "revoke-staff-$suffix",
            'password_hash' => 'not-used',
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
            'display_name' => '撤销测试管理员',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $departmentId = (int) Db::name('departments')->insertGetId([
            'parent_id' => null,
            'name' => "撤销测试部门 $suffix",
            'path' => '/',
            'depth' => 1,
            'sort' => 0,
            'status' => 'enabled',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        Db::name('departments')->where('id', $departmentId)->update(['path' => "/$departmentId"]);
        $categoryId = (int) Db::name('categories')->insertGetId([
            'parent_id' => 0,
            'name' => "撤销测试分类 $suffix",
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
            'title' => "撤销测试课程 $suffix",
            'cover_url' => null,
            'teacher_name' => '测试教师',
            'summary' => '撤销测试',
            'intro_rich_text' => '<p>撤销测试</p>',
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
            'title' => '撤销测试章节',
            'sort' => 0,
            'status' => 'enabled',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $this->lessonId = (int) Db::name('lessons')->insertGetId([
            'chapter_id' => $chapterId,
            'title' => '撤销测试课节',
            'sort' => 0,
            'status' => 'enabled',
            'content_type' => 'markdown',
            'body_markdown' => '# 保留访问',
            'asset_id' => null,
            'is_preview' => 0,
            'duration_seconds' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $this->learnerId = (int) Db::name('accounts')->insertGetId([
            'kind' => 'learner',
            'login' => '138' . random_int(10000000, 99999999),
            'password_hash' => 'not-used',
            'must_change_password' => 0,
            'status' => 'active',
            'last_login_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        Db::name('learners')->insert([
            'account_id' => $this->learnerId,
            'nickname' => '撤销测试学员',
            'avatar_url' => null,
            'show_on_course' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
}
