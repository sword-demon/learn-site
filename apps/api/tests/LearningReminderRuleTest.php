<?php

declare(strict_types=1);

namespace Tests;

use App\service\LearningReminderService;
use PHPUnit\Framework\TestCase;
use support\App;
use support\think\Db;
use Webman\ThinkOrm\ThinkOrm;

final class LearningReminderRuleTest extends TestCase
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

    public function testFavoriteRuleSendsOneOwnedCourseReminderAfterOneDay(): void
    {
        $learnerId = LearningActionLoopFixtures::learner();
        $staffId = LearningActionLoopFixtures::staff();
        $courseId = LearningActionLoopFixtures::course($staffId);
        LearningActionLoopFixtures::favorite($learnerId, $courseId, '2026-09-03 01:00:00');

        (new LearningReminderService())->evaluateLearner($learnerId, $this->at('2026-09-04 10:00:00'));

        $message = Db::name('learner_notifications')
            ->where('learner_id', $learnerId)
            ->where('kind', 'learning_reminder')
            ->find();
        self::assertNotFalse($message);
        self::assertSame('course', $message['resource_type']);
        self::assertSame($courseId, (int) $message['resource_id']);
        self::assertSame('favorite_not_started', $this->payload($message)['rule_code'] ?? null);
    }

    public function testOrderRuleUsesTheSharedFifteenMinuteDeadline(): void
    {
        $learnerId = LearningActionLoopFixtures::learner('13900000002');
        $staffId = LearningActionLoopFixtures::staff('fixture-order-staff');
        $courseId = LearningActionLoopFixtures::course($staffId);
        $orderId = LearningActionLoopFixtures::order($learnerId, $courseId);

        (new LearningReminderService())->evaluateLearner($learnerId, $this->at('2026-09-04 10:00:00'));

        self::assertSame(1, Db::name('learner_notifications')
            ->where('learner_id', $learnerId)
            ->where('resource_type', 'order')
            ->where('resource_id', $orderId)
            ->count());
    }

    public function testCouponRuleRequiresAnApplicablePublishedPaidCourse(): void
    {
        $learnerId = LearningActionLoopFixtures::learner('13900000003');
        $staffId = LearningActionLoopFixtures::staff('fixture-coupon-staff');
        $courseId = LearningActionLoopFixtures::course($staffId);
        Db::name('courses')->where('id', $courseId)->update(['price_mode' => 'paid', 'list_price' => 100]);
        $categoryId = (int) Db::name('courses')->where('id', $courseId)->value('category_id');
        $campaignId = LearningActionLoopFixtures::couponCampaign($staffId, $categoryId);
        $couponId = LearningActionLoopFixtures::coupon($learnerId, $campaignId);

        (new LearningReminderService())->evaluateLearner($learnerId, $this->at('2026-09-04 10:00:00'));

        self::assertSame(1, Db::name('learner_notifications')
            ->where('learner_id', $learnerId)
            ->where('resource_type', 'coupon')
            ->where('resource_id', $couponId)
            ->count());
    }

    public function testInactiveRuleUsesOldServerProgressAndAnAuthorizedLesson(): void
    {
        $learnerId = LearningActionLoopFixtures::learner('13900000004');
        $staffId = LearningActionLoopFixtures::staff('fixture-inactive-staff');
        $courseId = LearningActionLoopFixtures::course($staffId);
        $lessonId = LearningActionLoopFixtures::lesson($courseId);
        LearningActionLoopFixtures::grant($learnerId, $courseId);
        LearningActionLoopFixtures::progress($learnerId, $lessonId, '2026-08-27 01:00:00');

        (new LearningReminderService())->evaluateLearner($learnerId, $this->at('2026-09-04 10:00:00'));

        self::assertSame(1, Db::name('learner_notifications')
            ->where('learner_id', $learnerId)
            ->where('resource_type', 'lesson')
            ->where('resource_id', $lessonId)
            ->count());
    }

    public function testNoCandidateEvaluationIsRecordedWithoutCreatingAMessage(): void
    {
        $learnerId = LearningActionLoopFixtures::learner('13900000005');
        $now = $this->at('2026-09-04 10:00:00');

        $result = (new LearningReminderService())->evaluateLearner($learnerId, $now);

        self::assertSame(0, $result['sent']);
        self::assertSame(4, Db::name('learner_reminder_evaluations')
            ->where('learner_id', $learnerId)
            ->where('candidate_key', 'none:2026-09-04')
            ->where('evaluation_status', 'not_eligible')
            ->count());
        self::assertSame(0, Db::name('learner_notifications')->where('learner_id', $learnerId)->count());
    }

    private function at(string $value): \DateTimeImmutable
    {
        return new \DateTimeImmutable($value, new \DateTimeZone('Asia/Shanghai'));
    }

    /** @param array<string, mixed> $message @return array<string, mixed> */
    private function payload(array $message): array
    {
        $payload = json_decode((string) $message['payload_json'], true);
        return is_array($payload) ? $payload : [];
    }
}
