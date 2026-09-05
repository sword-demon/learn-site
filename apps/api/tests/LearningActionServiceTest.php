<?php

declare(strict_types=1);

namespace Tests;

use App\service\LearningActionService;
use PHPUnit\Framework\TestCase;
use support\App;
use support\think\Db;
use Webman\ThinkOrm\ThinkOrm;

final class LearningActionServiceTest extends TestCase
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

    public function testReturnsOneStableContinueLessonActionFromServerFacts(): void
    {
        $learnerId = LearningActionLoopFixtures::learner();
        $staffId = LearningActionLoopFixtures::staff();
        $courseId = LearningActionLoopFixtures::course($staffId);
        $lessonId = LearningActionLoopFixtures::lesson($courseId);
        LearningActionLoopFixtures::grant($learnerId, $courseId);

        $service = new LearningActionService();
        $first = $service->nextAction($learnerId, new \DateTimeImmutable('2026-09-04 10:00:00', new \DateTimeZone('Asia/Shanghai')));
        $second = $service->nextAction($learnerId, new \DateTimeImmutable('2026-09-04 10:00:01', new \DateTimeZone('Asia/Shanghai')));

        self::assertSame('ready', $first['state']);
        self::assertSame('continue_lesson', $first['action']['type']);
        self::assertSame($lessonId, $first['action']['target']['resource_id']);
        self::assertSame($first['action']['target']['path'], $second['action']['target']['path']);
    }

    public function testReturnsBrowseFallbackWithoutInventingProgress(): void
    {
        $learnerId = LearningActionLoopFixtures::learner('13800000002');

        $result = (new LearningActionService())->nextAction($learnerId);

        self::assertSame('browse_courses', $result['action']['type']);
        self::assertSame('/', $result['action']['target']['path']);
        self::assertSame('NO_ACTIONABLE_CANDIDATE', $result['action']['reason_code']);
    }
}
