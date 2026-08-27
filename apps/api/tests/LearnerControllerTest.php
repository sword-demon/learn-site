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
    }
}
