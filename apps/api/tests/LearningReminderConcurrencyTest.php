<?php

declare(strict_types=1);

namespace Tests;

use App\scheduled\handler\LearningReminderHandler;
use App\service\MessageService;
use PHPUnit\Framework\TestCase;
use support\App;
use support\think\Db;
use Webman\ThinkOrm\ThinkOrm;

final class LearningReminderConcurrencyTest extends TestCase
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

    public function testMessageIdempotencyIsTheLastLineForConcurrentRetry(): void
    {
        $learnerId = LearningActionLoopFixtures::learner('13900000018');
        $service = new MessageService();
        $key = 'concurrent:learning-reminder:' . $learnerId;

        $first = $service->emit('learning_reminder', $learnerId, '提醒', null, [], 'course_list', null, $key);
        $second = $service->emit('learning_reminder', $learnerId, '提醒', null, [], 'course_list', null, $key);

        self::assertSame($first, $second);
        self::assertSame(1, Db::name('learner_notifications')->where('idempotency_key', $key)->count());
    }

    public function testScheduledHandlerClampsBatchSizeAndUsesTheRegisteredCode(): void
    {
        $handler = new LearningReminderHandler();

        self::assertSame('learner.reminder.evaluate', $handler->code());
        self::assertSame(['batch_size' => 200], $handler->normalizeParams(['batch_size' => 999]));
        self::assertSame(['batch_size' => 1], $handler->normalizeParams(['batch_size' => 0]));
    }
}
