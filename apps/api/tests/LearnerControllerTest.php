<?php

declare(strict_types=1);

namespace Tests;

use App\controller\admin\LearnerController;
use App\service\DataScopeService;
use App\service\TokenService;
use PHPUnit\Framework\TestCase;
use support\App;
use support\Request;
use support\think\Db;
use Throwable;
use Webman\ThinkOrm\ThinkOrm;

final class LearnerControllerTest extends TestCase
{
    private int $actorId;
    private int $learnerId;

    public static function setUpBeforeClass(): void
    {
        App::loadAllConfig(['route', 'container']);
        ThinkOrm::start(null);
    }

    protected function setUp(): void
    {
        Db::startTrans();
        try {
            $this->seedFixture();
        } catch (Throwable $exception) {
            Db::rollback();
            throw $exception;
        }
    }

    protected function tearDown(): void
    {
        Db::rollback();
    }

    public function testIndexMapsTheLearnerSchemaToTheAdminContract(): void
    {
        $request = new Request(
            "GET /api/admin/v1/learners?page=1&limit=20 HTTP/1.1\r\nHost: test\r\n\r\n",
        );
        /** @phpstan-ignore-next-line */
        $request->account_id = $this->actorId;

        $response = (new LearnerController(new TokenService(), new DataScopeService()))->index($request);
        $payload = json_decode((string) $response->rawBody(), true);

        self::assertSame(200, $response->getStatusCode());
        self::assertIsArray($payload);
        self::assertTrue($payload['ok'] ?? false);
        self::assertSame([
            'account_id' => $this->learnerId,
            'login' => '13800000001',
            'display_name' => '测试学员',
            'department_id' => null,
            'department_name' => '',
            'status' => 'active',
            'must_change_password' => false,
            'last_login_at' => null,
            'created_at' => '2026-08-27 10:00:00',
            'course_count' => 1,
            'completed_course_count' => 1,
            'successful_order_count' => 1,
            'total_paid_amount' => 89,
        ], $payload['data']['items'][0] ?? null);
    }

    private function seedFixture(): void
    {
        $this->actorId = (int) Db::name('accounts')->insertGetId([
            'kind' => 'staff',
            'login' => 't082-actor-' . bin2hex(random_bytes(4)),
            'password_hash' => 'not-used',
            'must_change_password' => 0,
            'status' => 'active',
            'last_login_at' => null,
            'created_at' => '2026-08-27 10:00:00',
            'updated_at' => '2026-08-27 10:00:00',
        ]);
        Db::name('staff_users')->insert([
            'account_id' => $this->actorId,
            'is_super_admin' => 1,
            'department_id' => null,
            'display_name' => 'T082 actor',
            'created_at' => '2026-08-27 10:00:00',
            'updated_at' => '2026-08-27 10:00:00',
        ]);

        $this->learnerId = (int) Db::name('accounts')->insertGetId([
            'kind' => 'learner',
            'login' => '13800000001',
            'password_hash' => 'not-used',
            'must_change_password' => 0,
            'status' => 'active',
            'last_login_at' => null,
            'created_at' => '2026-08-27 10:00:00',
            'updated_at' => '2026-08-27 10:00:00',
        ]);
        Db::name('learners')->insert([
            'account_id' => $this->learnerId,
            'nickname' => '测试学员',
            'avatar_url' => null,
            'show_on_course' => 0,
            'created_at' => '2026-08-27 10:00:00',
            'updated_at' => '2026-08-27 10:00:00',
        ]);

        $departmentId = (int) Db::name('departments')->insertGetId([
            'parent_id' => null,
            'name' => '学员摘要部门 ' . bin2hex(random_bytes(3)),
            'path' => '/',
            'depth' => 1,
            'sort' => 0,
            'status' => 'enabled',
            'created_at' => '2026-08-27 10:00:00',
            'updated_at' => '2026-08-27 10:00:00',
        ]);
        Db::name('departments')->where('id', $departmentId)->update(['path' => "/{$departmentId}"]);
        $categoryId = (int) Db::name('categories')->insertGetId([
            'parent_id' => 0,
            'name' => '学员摘要分类 ' . bin2hex(random_bytes(3)),
            'path' => '/',
            'depth' => 1,
            'sort' => 0,
            'status' => 'enabled',
            'created_at' => '2026-08-27 10:00:00',
            'updated_at' => '2026-08-27 10:00:00',
        ]);
        $courseId = (int) Db::name('courses')->insertGetId([
            'department_id' => $departmentId,
            'category_id' => $categoryId,
            'title' => '摘要课程',
            'cover_url' => null,
            'teacher_name' => '教师',
            'summary' => '摘要',
            'intro_rich_text' => null,
            'status' => 'published',
            'price_mode' => 'paid',
            'list_price' => 99,
            'sale_price' => 89,
            'sale_start_at' => null,
            'sale_end_at' => null,
            'created_by_staff_id' => $this->actorId,
            'created_at' => '2026-08-27 10:00:00',
            'updated_at' => '2026-08-27 10:00:00',
        ]);
        Db::name('course_enrollments')->insert([
            'learner_id' => $this->learnerId,
            'course_id' => $courseId,
            'progress_percent' => 100,
            'last_lesson_id' => null,
            'last_position' => 0,
            'completed_at' => '2026-08-27 11:00:00',
            'created_at' => '2026-08-27 10:00:00',
            'updated_at' => '2026-08-27 11:00:00',
        ]);
        Db::name('orders')->insert([
            'learner_id' => $this->learnerId,
            'course_id' => $courseId,
            'list_price_snapshot' => 99,
            'sale_price_snapshot' => 89,
            'paid_amount' => 89,
            'currency' => 'CNY',
            'status' => 'succeeded',
            'provider' => 'fake',
            'provider_ref' => 'summary-order',
            'succeeded_at' => '2026-08-27 10:00:00',
            'created_at' => '2026-08-27 10:00:00',
            'updated_at' => '2026-08-27 10:00:00',
        ]);
    }
}
