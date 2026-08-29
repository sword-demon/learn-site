<?php

declare(strict_types=1);

namespace Tests;

use App\service\PushNotificationService;
use PHPUnit\Framework\TestCase;
use support\App;
use Webman\ThinkOrm\ThinkOrm;

final class NotificationPushAuthTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        App::loadAllConfig(['route', 'container', 'plugin']);
        ThinkOrm::start(null);
    }

    public function testLearnerIdFromPrivateChannel(): void
    {
        $push = new PushNotificationService();
        self::assertSame(42, $push->learnerIdFromChannel('private-learner-42'));
        self::assertNull($push->learnerIdFromChannel('private-learner-'));
        self::assertNull($push->learnerIdFromChannel('public-learner-42'));
    }

    public function testChannelNameMustMatchTokenAccountId(): void
    {
        $learnerId = 1001;
        $otherId = 1002;
        $push = new PushNotificationService();
        self::assertSame($learnerId, $push->learnerIdFromChannel($push->channelForLearner($learnerId)));
        self::assertNotSame($otherId, $push->learnerIdFromChannel($push->channelForLearner($learnerId)));
    }
}
