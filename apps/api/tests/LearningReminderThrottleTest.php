<?php

declare(strict_types=1);

namespace Tests;

use App\service\LearningReminderService;
use PHPUnit\Framework\TestCase;
use support\App;
use support\think\Db;
use Webman\ThinkOrm\ThinkOrm;

final class LearningReminderThrottleTest extends TestCase
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

    public function testSameFavoriteEventIsThrottledAndDoesNotIncreaseUnreadMessages(): void
    {
        $learnerId = LearningActionLoopFixtures::learner('13900000006');
        $staffId = LearningActionLoopFixtures::staff('fixture-throttle-staff');
        $courseId = LearningActionLoopFixtures::course($staffId);
        LearningActionLoopFixtures::favorite($learnerId, $courseId, '2026-09-02 01:00:00');
        $service = new LearningReminderService();

        $service->evaluateLearner($learnerId, $this->at('2026-09-04 10:00:00'));
        $second = $service->evaluateLearner($learnerId, $this->at('2026-09-04 10:01:00'));

        self::assertSame(1, Db::name('learner_notifications')->where('learner_id', $learnerId)->count());
        self::assertContains('throttled', array_column($second['items'], 'status'));
        self::assertSame(1, (int) Db::name('learner_reminder_evaluations')
            ->where('learner_id', $learnerId)
            ->where('rule_code', 'favorite_not_started')
            ->value('send_count'));
    }

    public function testDailyLimitKeepsTheFourRulesInPriorityOrder(): void
    {
        $learnerId = LearningActionLoopFixtures::learner('13900000007');
        $staffId = LearningActionLoopFixtures::staff('fixture-cap-staff');

        $favoriteCourse = LearningActionLoopFixtures::course($staffId, '收藏课程');
        LearningActionLoopFixtures::favorite($learnerId, $favoriteCourse, '2026-09-02 01:00:00');

        $couponCourse = LearningActionLoopFixtures::course($staffId, '优惠券课程');
        Db::name('courses')->where('id', $couponCourse)->update(['price_mode' => 'paid', 'list_price' => 100]);
        $categoryId = (int) Db::name('courses')->where('id', $couponCourse)->value('category_id');
        $campaignId = LearningActionLoopFixtures::couponCampaign($staffId, $categoryId);
        LearningActionLoopFixtures::coupon($learnerId, $campaignId);

        $continuedCourse = LearningActionLoopFixtures::course($staffId, '继续课程');
        $continuedLesson = LearningActionLoopFixtures::lesson($continuedCourse);
        LearningActionLoopFixtures::grant($learnerId, $continuedCourse);
        LearningActionLoopFixtures::progress($learnerId, $continuedLesson, '2026-08-20 01:00:00');

        $orderCourse = LearningActionLoopFixtures::course($staffId, '订单课程');
        LearningActionLoopFixtures::order($learnerId, $orderCourse);

        $result = (new LearningReminderService())->evaluateLearner($learnerId, $this->at('2026-09-04 10:00:00'));

        self::assertSame(3, $result['sent']);
        self::assertSame(3, Db::name('learner_notifications')
            ->where('learner_id', $learnerId)
            ->where('kind', 'learning_reminder')
            ->count());
        self::assertSame('daily_cap', Db::name('learner_reminder_evaluations')
            ->where('learner_id', $learnerId)
            ->where('rule_code', 'learning_inactive')
            ->where('candidate_key', 'like', 'inactive:%')
            ->value('evaluation_status'));
    }

    public function testQuietHoursDefersWithoutCreatingAnUnreadMessageAndRetriesAtEight(): void
    {
        $learnerId = LearningActionLoopFixtures::learner('13900000008');
        $staffId = LearningActionLoopFixtures::staff('fixture-quiet-staff');
        $courseId = LearningActionLoopFixtures::course($staffId);
        LearningActionLoopFixtures::favorite($learnerId, $courseId, '2026-09-03 14:00:00');
        $service = new LearningReminderService();

        $quiet = $service->evaluateLearner($learnerId, $this->at('2026-09-04 22:00:00'));
        self::assertSame(0, $quiet['sent']);
        self::assertSame(0, Db::name('learner_notifications')->where('learner_id', $learnerId)->count());

        $nextMorning = $service->evaluateLearner($learnerId, $this->at('2026-09-05 08:00:00'));
        self::assertSame(1, $nextMorning['sent']);
        self::assertSame(1, Db::name('learner_notifications')->where('learner_id', $learnerId)->count());
    }

    public function testInactiveReminderUsesASecondLocalWeekEventAfterSevenDays(): void
    {
        $learnerId = LearningActionLoopFixtures::learner('13900000009');
        $staffId = LearningActionLoopFixtures::staff('fixture-week-staff');
        $courseId = LearningActionLoopFixtures::course($staffId);
        $lessonId = LearningActionLoopFixtures::lesson($courseId);
        LearningActionLoopFixtures::grant($learnerId, $courseId);
        LearningActionLoopFixtures::progress($learnerId, $lessonId, '2026-08-20 01:00:00');
        $service = new LearningReminderService();

        $service->evaluateLearner($learnerId, $this->at('2026-09-04 10:00:00'));
        $nextWeek = $service->evaluateLearner($learnerId, $this->at('2026-09-10 10:00:00'));

        self::assertSame(1, $nextWeek['sent']);
        self::assertSame(2, Db::name('learner_notifications')
            ->where('learner_id', $learnerId)
            ->where('kind', 'learning_reminder')
            ->count());
        self::assertSame(2, Db::name('learner_reminder_evaluations')
            ->where('learner_id', $learnerId)
            ->where('rule_code', 'learning_inactive')
            ->count());
    }

    private function at(string $value): \DateTimeImmutable
    {
        return new \DateTimeImmutable($value, new \DateTimeZone('Asia/Shanghai'));
    }
}
