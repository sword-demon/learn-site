<?php

declare(strict_types=1);

namespace Tests;

use App\controller\learner\LearnerController;
use PHPUnit\Framework\TestCase;
use support\App;
use support\Request;
use support\think\Db;
use Webman\ThinkOrm\ThinkOrm;

final class LearnerProfileTest extends TestCase
{
    private int $learnerId;

    public static function setUpBeforeClass(): void
    {
        App::loadAllConfig(['route', 'container']);
        ThinkOrm::start(null);
    }

    protected function setUp(): void
    {
        Db::startTrans();
        $this->learnerId = (int) Db::name('accounts')->insertGetId([
            'kind' => 'learner',
            'login' => '138' . random_int(10000000, 99999999),
            'password_hash' => 'not-used',
            'must_change_password' => 0,
            'status' => 'active',
            'last_login_at' => null,
            'created_at' => '2026-08-28 10:00:00',
            'updated_at' => '2026-08-28 10:00:00',
        ]);
        Db::name('learners')->insert([
            'account_id' => $this->learnerId,
            'nickname' => null,
            'avatar_url' => null,
            'show_on_course' => 0,
            'created_at' => '2026-08-28 10:00:00',
            'updated_at' => '2026-08-28 10:00:00',
        ]);
    }

    protected function tearDown(): void
    {
        Db::rollback();
    }

    public function testLearnerCanReadAndUpdateOnlyTheirOwnProfile(): void
    {
        $controller = new LearnerController();
        $request = new Request("GET /api/learner/v1/me HTTP/1.1\r\nHost: test\r\n\r\n");
        /** @phpstan-ignore-next-line */
        $request->account_id = $this->learnerId;
        $response = $controller->me($request);
        self::assertSame(200, $response->getStatusCode());
        $payload = json_decode((string) $response->rawBody(), true);
        self::assertSame($this->learnerId, $payload['data']['account_id']);
        self::assertFalse($payload['data']['show_on_course']);

        $update = new Request("PATCH /api/learner/v1/me HTTP/1.1\r\nHost: test\r\nContent-Type: application/json\r\n\r\n" . json_encode([
            'nickname' => '林间学员',
            'show_on_course' => true,
        ], JSON_THROW_ON_ERROR));
        /** @phpstan-ignore-next-line */
        $update->account_id = $this->learnerId;
        $updated = $controller->updateMe($update);
        self::assertSame(200, $updated->getStatusCode());
        $body = json_decode((string) $updated->rawBody(), true);
        self::assertSame('林间学员', $body['data']['nickname']);
        self::assertTrue($body['data']['show_on_course']);
    }

    public function testMissingLearnerContextCannotReadProfile(): void
    {
        $response = (new LearnerController())->me(new Request("GET /api/learner/v1/me HTTP/1.1\r\nHost: test\r\n\r\n"));
        self::assertSame(404, $response->getStatusCode());
    }
}
