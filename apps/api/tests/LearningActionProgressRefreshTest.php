<?php

declare(strict_types=1);

namespace Tests;

use App\service\EntitlementService;
use App\service\LearningActionService;
use App\service\ProgressService;
use PHPUnit\Framework\TestCase;
use support\App;
use support\think\Db;
use Webman\ThinkOrm\ThinkOrm;

final class LearningActionProgressRefreshTest extends TestCase
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

    public function testCompletingTheCurrentLessonMakesTheNextLessonTheServerAction(): void
    {
        $learnerId = LearningActionLoopFixtures::learner('13900000010');
        $staffId = LearningActionLoopFixtures::staff('fixture-progress-staff');
        $courseId = LearningActionLoopFixtures::course($staffId);
        $firstLessonId = LearningActionLoopFixtures::lesson($courseId, '第一节', 0);
        $secondLessonId = LearningActionLoopFixtures::lesson($courseId, '第二节', 1);
        LearningActionLoopFixtures::grant($learnerId, $courseId);
        $progress = new ProgressService(new EntitlementService());

        $progress->reportProgress($learnerId, $firstLessonId, 'markdown', 0, 1, false);
        $completed = $progress->reportProgress($learnerId, $firstLessonId, 'markdown', 0, 1, true);
        $action = (new LearningActionService())->nextAction($learnerId, $this->at('2026-09-04 10:00:00'));

        self::assertTrue($completed['completed']);
        self::assertSame('continue_lesson', $action['action']['type']);
        self::assertSame($secondLessonId, $action['action']['target']['resource_id']);
        self::assertNotSame($firstLessonId, $action['action']['target']['resource_id']);
    }

    public function testARevokedEntitlementIsSkippedAfterProgressRefresh(): void
    {
        $learnerId = LearningActionLoopFixtures::learner('13900000011');
        $staffId = LearningActionLoopFixtures::staff('fixture-revoke-refresh-staff');
        $courseId = LearningActionLoopFixtures::course($staffId);
        LearningActionLoopFixtures::lesson($courseId);
        LearningActionLoopFixtures::grant($learnerId, $courseId);
        Db::name('course_entitlements')
            ->where('learner_id', $learnerId)
            ->where('course_id', $courseId)
            ->update(['status' => 'revoked']);

        $action = (new LearningActionService())->nextAction($learnerId, $this->at('2026-09-04 10:00:00'));

        self::assertSame('browse_courses', $action['action']['type']);
    }

    public function testMapActionPointsToCourseDetailsWhenTheNextCourseNeedsAccess(): void
    {
        $learnerId = LearningActionLoopFixtures::learner('13900000019');
        $staffId = LearningActionLoopFixtures::staff('fixture-map-access-staff');
        $completedCourseId = LearningActionLoopFixtures::course($staffId, '已完成课程');
        $nextCourseId = LearningActionLoopFixtures::course($staffId, '待取得访问权课程');
        LearningActionLoopFixtures::grant($learnerId, $completedCourseId);
        LearningActionLoopFixtures::enroll($learnerId, $completedCourseId);
        Db::name('course_enrollments')
            ->where('learner_id', $learnerId)
            ->where('course_id', $completedCourseId)
            ->update(['completed_at' => '2026-09-04 09:00:00']);
        LearningActionLoopFixtures::map($staffId, $learnerId, [$completedCourseId, $nextCourseId]);

        $action = (new LearningActionService())->nextAction($learnerId, $this->at('2026-09-04 10:00:00'));

        self::assertSame('continue_map', $action['action']['type']);
        self::assertSame('requires_access', $action['action']['availability']);
        self::assertSame('course', $action['action']['target']['resource_type']);
        self::assertSame($nextCourseId, $action['action']['target']['resource_id']);
        self::assertSame('/courses/' . $nextCourseId, $action['action']['target']['path']);
    }

    private function at(string $value): \DateTimeImmutable
    {
        return new \DateTimeImmutable($value, new \DateTimeZone('Asia/Shanghai'));
    }
}
