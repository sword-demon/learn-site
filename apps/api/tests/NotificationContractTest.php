<?php

declare(strict_types=1);

namespace Tests;

use App\controller\learner\NotificationController;
use App\middleware\LearnerAuth;
use App\service\MessageService;
use App\service\NotificationDispatchService;
use App\service\PushNotificationService;
use PHPUnit\Framework\TestCase;
use support\App;
use support\Request;
use support\think\Db;
use Webman\Route;
use Webman\ThinkOrm\ThinkOrm;

final class NotificationContractTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        App::loadAllConfig(['route', 'container']);
        ThinkOrm::start(null);
    }

    protected function setUp(): void
    {
        Db::startTrans();
    }

    protected function tearDown(): void
    {
        Db::rollback();
    }

    public function testEmitIsIdempotentAndKeepsItsRelatedResource(): void
    {
        $service = new MessageService();
        $learnerId = $this->insertLearner();
        $key = 'question-update:test:' . bin2hex(random_bytes(8));

        $first = $service->emit(
            'question_update',
            $learnerId,
            '问题有新回复',
            '管理员回复了你的问题',
            ['question_id' => 12],
            'question',
            12,
            $key,
        );
        $second = $service->emit(
            'question_update',
            $learnerId,
            '问题有新回复',
            '管理员回复了你的问题',
            ['question_id' => 12],
            'question',
            12,
            $key,
        );

        self::assertSame($first, $second);
        self::assertSame(1, Db::name('learner_notifications')->where('idempotency_key', $key)->count());
        self::assertSame('question', Db::name('learner_notifications')->where('id', $first)->value('resource_type'));
        self::assertSame(12, (int) Db::name('learner_notifications')->where('id', $first)->value('resource_id'));
    }

    public function testInboxReturnsThreeKindsForTheOwnerAndCannotReadAnotherLearnersMessage(): void
    {
        $ownerId = $this->insertLearner();
        $otherId = $this->insertLearner();
        $service = new MessageService();
        foreach (
            [
                ['question_update', '问答更新', 'question', 991],
                ['progress_reset', '进度重置', 'course', 992],
                ['entitlement_revoked', '授权撤销', 'course', 993],
            ] as [$kind, $title, $resourceType, $resourceId]
        ) {
            $service->emit(
                $kind,
                $ownerId,
                $title,
                null,
                [],
                $resourceType,
                $resourceId,
                "$kind:test:" . bin2hex(random_bytes(6)),
            );
        }
        $otherMessageId = $service->emit(
            MessageService::KIND_QUESTION_UPDATE,
            $otherId,
            '他人的消息',
            null,
            [],
            'question',
            994,
            'other:test:' . bin2hex(random_bytes(6)),
        );

        $listRequest = new Request(
            "GET /api/learner/v1/messages?page=1&limit=20 HTTP/1.1\r\nHost: test\r\n\r\n",
        );
        /** @phpstan-ignore-next-line */
        $listRequest->account_id = $ownerId;
        $response = (new NotificationController())->index($listRequest);
        $payload = json_decode((string) $response->rawBody(), true);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(3, $payload['data']['total'] ?? null);
        self::assertSame(
            ['entitlement_revoked', 'progress_reset', 'question_update'],
            array_column($payload['data']['items'] ?? [], 'kind'),
        );
        self::assertSame([false, false, false], array_column($payload['data']['items'] ?? [], 'resource_available'));

        $readRequest = new Request(
            "POST /api/learner/v1/messages/$otherMessageId/read HTTP/1.1\r\nHost: test\r\n\r\n",
        );
        /** @phpstan-ignore-next-line */
        $readRequest->account_id = $ownerId;
        $readResponse = (new NotificationController())->read($readRequest, (string) $otherMessageId);

        self::assertSame(404, $readResponse->getStatusCode());
        self::assertNull(Db::name('learner_notifications')->where('id', $otherMessageId)->value('read_at'));
    }

    public function testInboxRoutesRequireLearnerAuthentication(): void
    {
        if (Route::getRoutes() === []) {
            Route::load([app_path()]);
        }
        $routes = [];
        foreach (Route::getRoutes() as $route) {
            foreach ($route->getMethods() as $method) {
                $routes["$method {$route->getPath()}"] = $route->getMiddleware();
            }
        }

        self::assertContains(LearnerAuth::class, $routes['GET /api/learner/v1/messages'] ?? []);
        self::assertContains(LearnerAuth::class, $routes['POST /api/learner/v1/messages/{id}/read'] ?? []);
        self::assertContains(LearnerAuth::class, $routes['GET /api/learner/v1/messages/unread-count'] ?? []);
    }

    public function testInboxIncludesAnnouncementAndInternalMessageKinds(): void
    {
        $ownerId = $this->insertLearner();
        $dispatch = new NotificationDispatchService();
        $staffId = $this->insertStaff();
        $dispatch->sendAnnouncement($staffId, '公告标题', '公告正文');
        $dispatch->sendInternalMessage($staffId, '私信标题', '私信正文', [$ownerId]);

        $listRequest = new Request(
            "GET /api/learner/v1/messages?page=1&limit=20 HTTP/1.1\r\nHost: test\r\n\r\n",
        );
        /** @phpstan-ignore-next-line */
        $listRequest->account_id = $ownerId;
        $payload = json_decode((string) (new NotificationController())->index($listRequest)->rawBody(), true);

        self::assertSame(2, $payload['data']['total'] ?? null);
        self::assertContains('announcement', array_column($payload['data']['items'] ?? [], 'kind'));
        self::assertContains('internal_message', array_column($payload['data']['items'] ?? [], 'kind'));
        self::assertNotNull($payload['data']['items'][0]['dispatch_id'] ?? null);
    }

    public function testUnreadCountAndMarkReadAreIdempotent(): void
    {
        $ownerId = $this->insertLearner();
        $service = new MessageService();
        $messageId = $service->emit(
            MessageService::KIND_QUESTION_UPDATE,
            $ownerId,
            '未读消息',
            null,
            [],
            'question',
            100,
            'unread:test:' . bin2hex(random_bytes(6)),
        );

        $countRequest = new Request(
            "GET /api/learner/v1/messages/unread-count HTTP/1.1\r\nHost: test\r\n\r\n",
        );
        /** @phpstan-ignore-next-line */
        $countRequest->account_id = $ownerId;
        $countPayload = json_decode(
            (string) (new NotificationController())->unreadCount($countRequest)->rawBody(),
            true,
        );
        self::assertSame(1, $countPayload['data']['count'] ?? null);

        $readRequest = new Request(
            "POST /api/learner/v1/messages/$messageId/read HTTP/1.1\r\nHost: test\r\n\r\n",
        );
        /** @phpstan-ignore-next-line */
        $readRequest->account_id = $ownerId;
        $firstRead = (new NotificationController())->read($readRequest, (string) $messageId);
        $secondRead = (new NotificationController())->read($readRequest, (string) $messageId);
        self::assertSame(200, $firstRead->getStatusCode());
        self::assertSame(200, $secondRead->getStatusCode());

        $afterCount = json_decode(
            (string) (new NotificationController())->unreadCount($countRequest)->rawBody(),
            true,
        );
        self::assertSame(0, $afterCount['data']['count'] ?? null);
    }

    private function insertStaff(): int
    {
        $now = date('Y-m-d H:i:s');
        return (int) Db::name('accounts')->insertGetId([
            'kind' => 'staff',
            'login' => 'staff' . random_int(100000, 999999),
            'password_hash' => 'not-used-by-test',
            'must_change_password' => 0,
            'status' => 'active',
            'last_login_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function insertLearner(): int
    {
        $now = date('Y-m-d H:i:s');
        $id = (int) Db::name('accounts')->insertGetId([
            'kind' => 'learner',
            'login' => '13' . random_int(100000000, 999999999),
            'password_hash' => 'not-used-by-test',
            'must_change_password' => 0,
            'status' => 'active',
            'last_login_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        Db::name('learners')->insert([
            'account_id' => $id,
            'nickname' => null,
            'avatar_url' => null,
            'show_on_course' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        return $id;
    }
}
