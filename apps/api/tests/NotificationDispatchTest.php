<?php

declare(strict_types=1);

namespace Tests;

use App\controller\learner\NotificationController;
use App\middleware\Authorize;
use App\service\NotificationDispatchService;
use App\service\PushNotificationService;
use PHPUnit\Framework\TestCase;
use support\App;
use support\Request;
use support\think\Db;
use Webman\ThinkOrm\ThinkOrm;

final class NotificationDispatchTest extends TestCase
{
    private int $staffId;
    private int $learnerA;
    private int $learnerB;
    private int $learnerC;

    public static function setUpBeforeClass(): void
    {
        App::loadAllConfig(['route', 'container']);
        ThinkOrm::start(null);
    }

    protected function setUp(): void
    {
        Db::startTrans();
        $this->staffId = $this->insertStaff();
        $this->learnerA = $this->insertLearner();
        $this->learnerB = $this->insertLearner();
        $this->learnerC = $this->insertLearner();
    }

    protected function tearDown(): void
    {
        Db::rollback();
    }

    public function testAnnouncementFanOutCreatesDispatchAndInboxRows(): void
    {
        $service = new NotificationDispatchService(new PushNotificationService());
        $result = $service->sendAnnouncement($this->staffId, '维护通知', '今晚维护');

        self::assertSame('announcement', $result['type']);
        self::assertSame(3, $result['recipient_count']);
        self::assertSame('全体学员', $result['recipient_summary']);
        self::assertSame(
            3,
            Db::name('learner_notifications')->where('kind', 'announcement')->count(),
        );
        self::assertSame(
            1,
            Db::name('audit_log')->where('action', 'notification.send')->count(),
        );
    }

    public function testInternalMessageOnlyReachesSelectedLearners(): void
    {
        $service = new NotificationDispatchService(new PushNotificationService());
        $service->sendInternalMessage(
            $this->staffId,
            '学习提醒',
            '请继续学习',
            [$this->learnerA, $this->learnerB],
        );

        self::assertSame(
            2,
            Db::name('learner_notifications')->where('kind', 'internal_message')->count(),
        );
        self::assertSame(
            1,
            Db::name('learner_notifications')
                ->where('learner_id', $this->learnerA)
                ->where('kind', 'internal_message')
                ->count(),
        );
        self::assertSame(
            0,
            Db::name('learner_notifications')
                ->where('learner_id', $this->learnerC)
                ->where('kind', 'internal_message')
                ->count(),
        );
    }

    public function testInternalMessageRejectsEmptyRecipients(): void
    {
        $service = new NotificationDispatchService(new PushNotificationService());
        $this->expectException(\App\service\BusinessException::class);
        $this->expectExceptionMessage('INVALID_RECIPIENTS');
        $service->sendInternalMessage($this->staffId, '提醒', '正文', []);
    }

    public function testListAndShowReturnDispatchRecords(): void
    {
        $service = new NotificationDispatchService(new PushNotificationService());
        $created = $service->sendAnnouncement($this->staffId, '公告一', '正文一');
        $service->sendInternalMessage(
            $this->staffId,
            '站内信一',
            '正文二',
            [$this->learnerA],
        );

        $list = $service->list(['type' => 'announcement', 'page' => 1, 'limit' => 20]);
        self::assertSame(1, $list['total']);
        self::assertSame('announcement', $list['items'][0]['type']);
        self::assertArrayNotHasKey('body', $list['items'][0]);

        $detail = $service->show((int) $created['id']);
        self::assertSame('正文一', $detail['body']);
    }

    public function testNotificationRoutesRequireManagePermission(): void
    {
        self::assertSame('notification.manage', Authorize::permissionFor('/api/admin/v1/notifications', 'GET'));
        self::assertSame(
            'notification.manage',
            Authorize::permissionFor('/api/admin/v1/notifications/announcements', 'POST'),
        );
    }

    public function testUnreadCountReturnsOnlyOwnerUnreadRows(): void
    {
        $service = new NotificationDispatchService(new PushNotificationService());
        $service->sendInternalMessage($this->staffId, '仅甲', '正文', [$this->learnerA]);

        $request = new Request("GET /api/learner/v1/messages/unread-count HTTP/1.1\r\nHost: test\r\n\r\n");
        /** @phpstan-ignore-next-line */
        $request->account_id = $this->learnerA;
        $response = (new NotificationController())->unreadCount($request);
        $payload = json_decode((string) $response->rawBody(), true);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(1, $payload['data']['count'] ?? null);

        /** @phpstan-ignore-next-line */
        $request->account_id = $this->learnerC;
        $other = json_decode((string) (new NotificationController())->unreadCount($request)->rawBody(), true);
        self::assertSame(0, $other['data']['count'] ?? null);
    }

    private function insertStaff(): int
    {
        $now = date('Y-m-d H:i:s');
        $id = (int) Db::name('accounts')->insertGetId([
            'kind' => 'staff',
            'login' => 'notify-admin-' . bin2hex(random_bytes(4)),
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
            'display_name' => 'Notify Admin',
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
            'nickname' => null,
            'avatar_url' => null,
            'show_on_course' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        return $id;
    }
}
