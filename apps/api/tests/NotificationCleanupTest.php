<?php

declare(strict_types=1);

namespace Tests;

use App\service\MessageService;
use App\service\NotificationCleanupService;
use PHPUnit\Framework\TestCase;
use support\App;
use support\think\Db;
use Webman\ThinkOrm\ThinkOrm;

final class NotificationCleanupTest extends TestCase
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
        $this->learnerId = $this->insertLearner();
    }

    protected function tearDown(): void
    {
        Db::rollback();
    }

    public function testPurgeExpiredDeletesOnlyRowsOlderThanTwoMonths(): void
    {
        $service = new MessageService();
        $recentId = $service->emit(
            MessageService::KIND_QUESTION_UPDATE,
            $this->learnerId,
            '近期消息',
            null,
            [],
            null,
            null,
            'recent:' . bin2hex(random_bytes(6)),
        );
        $oldId = $service->emit(
            MessageService::KIND_ANNOUNCEMENT,
            $this->learnerId,
            '过期消息',
            null,
            [],
            null,
            null,
            'old:' . bin2hex(random_bytes(6)),
        );
        Db::name('learner_notifications')
            ->where('id', $oldId)
            ->update(['created_at' => date('Y-m-d H:i:s', strtotime('-61 days'))]);

        $deleted = (new NotificationCleanupService())->purgeExpired(100);

        self::assertGreaterThanOrEqual(1, $deleted);
        self::assertNull(Db::name('learner_notifications')->where('id', $oldId)->value('id'));
        self::assertNotNull(Db::name('learner_notifications')->where('id', $recentId)->value('id'));
        self::assertSame(
            1,
            Db::name('audit_log')->where('action', 'notification.cleanup')->count(),
        );
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
