<?php

declare(strict_types=1);

namespace Tests;

use App\service\MessageService;
use App\service\UnreadCounterService;
use PHPUnit\Framework\TestCase;
use support\App;
use support\think\Db;
use Webman\ThinkOrm\ThinkOrm;

final class UnreadCounterTest extends TestCase
{
    private int $learnerId;

    public static function setUpBeforeClass(): void
    {
        App::loadAllConfig(['route', 'container']);
        ThinkOrm::start(null);
        RedisStub::install(new InMemoryRedis());
    }

    protected function setUp(): void
    {
        Db::startTrans();
        $this->learnerId = (int) Db::name('accounts')->insertGetId([
            'login' => 'unread-' . bin2hex(random_bytes(4)),
            'password_hash' => 'hash',
            'status' => 'active',
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        Db::name('learners')->insert([
            'account_id' => $this->learnerId,
            'nickname' => 'Unread',
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }

    protected function tearDown(): void
    {
        Db::rollback();
    }

    public function testEmitIncrementsUnreadCounter(): void
    {
        $messages = new MessageService(push: null);
        $messages->emit(MessageService::KIND_QUESTION_UPDATE, $this->learnerId, '标题', '正文');
        $counter = new UnreadCounterService();
        self::assertSame(1, $counter->get($this->learnerId));
    }

    public function testGetHealsInflatedRedisCounterFromDb(): void
    {
        $counter = new UnreadCounterService();
        $redis = RedisStub::$instance;
        self::assertNotNull($redis);
        $redis->set('unread:' . $this->learnerId, '165');

        self::assertSame(0, $counter->get($this->learnerId));
        self::assertSame('0', $redis->get('unread:' . $this->learnerId));

        $messages = new MessageService(push: null);
        $messages->emit(MessageService::KIND_ANNOUNCEMENT, $this->learnerId, '公告', '正文');
        $redis->set('unread:' . $this->learnerId, '165');
        self::assertSame(1, $counter->get($this->learnerId));
        self::assertSame('1', $redis->get('unread:' . $this->learnerId));
    }
}
