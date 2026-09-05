<?php

declare(strict_types=1);

namespace Tests;

use App\controller\learner\NotificationController;
use App\service\MessageService;
use PHPUnit\Framework\TestCase;
use support\App;
use support\Request;
use support\think\Db;
use Webman\ThinkOrm\ThinkOrm;

final class LearnerNotificationResourceTest extends TestCase
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

    public function testLessonResourceNeedsPreviewOrTheCurrentLearnersEntitlement(): void
    {
        $ownerId = LearningActionLoopFixtures::learner('13900000012');
        $otherId = LearningActionLoopFixtures::learner('13900000013');
        $staffId = LearningActionLoopFixtures::staff('fixture-resource-staff');
        $courseId = LearningActionLoopFixtures::course($staffId);
        $lessonId = LearningActionLoopFixtures::lesson($courseId);
        LearningActionLoopFixtures::grant($ownerId, $courseId);
        $service = new MessageService();
        $service->emit('learning_reminder', $ownerId, '可访问课节', null, [], 'lesson', $lessonId, 'resource:owner');
        $service->emit('learning_reminder', $otherId, '未授权课节', null, [], 'lesson', $lessonId, 'resource:other');

        $owner = $this->listFor($ownerId);
        $other = $this->listFor($otherId);

        self::assertTrue($owner[0]['resource_available']);
        self::assertSame('/learn/' . $courseId . '/' . $lessonId, $owner[0]['resource_path']);
        self::assertFalse($other[0]['resource_available']);
        self::assertSame('/', $other[0]['resource_path']);
        self::assertSame('课程或课节已不可学习', $other[0]['resource_unavailable_reason']);
    }

    public function testCouponResourceIsOwnedAndStillApplicable(): void
    {
        $learnerId = LearningActionLoopFixtures::learner('13900000014');
        $staffId = LearningActionLoopFixtures::staff('fixture-coupon-resource-staff');
        $courseId = LearningActionLoopFixtures::course($staffId);
        Db::name('courses')->where('id', $courseId)->update(['price_mode' => 'paid']);
        $categoryId = (int) Db::name('courses')->where('id', $courseId)->value('category_id');
        $campaignId = LearningActionLoopFixtures::couponCampaign($staffId, $categoryId);
        $couponId = LearningActionLoopFixtures::coupon($learnerId, $campaignId);
        (new MessageService())->emit('learning_reminder', $learnerId, '优惠券提醒', null, [], 'coupon', $couponId, 'resource:coupon');

        $item = $this->listFor($learnerId)[0];

        self::assertTrue($item['resource_available']);
        self::assertSame('/me/coupons', $item['resource_path']);
    }

    public function testQuestionResourceResolvesToTheOwnersPublishedCourse(): void
    {
        $learnerId = LearningActionLoopFixtures::learner('13900000020');
        $staffId = LearningActionLoopFixtures::staff('fixture-question-resource-staff');
        $courseId = LearningActionLoopFixtures::course($staffId);
        $lessonId = LearningActionLoopFixtures::lesson($courseId);
        $chapterId = (int) Db::name('lessons')->where('id', $lessonId)->value('chapter_id');
        $questionId = (int) Db::name('questions')->insertGetId([
            'course_id' => $courseId,
            'chapter_id' => $chapterId,
            'lesson_id' => $lessonId,
            'learner_id' => $learnerId,
            'title' => '资源跳转问题',
            'body' => '如何继续？',
            'status' => 'pending',
            'answered_at' => null,
            'answered_by_staff_id' => null,
            'created_at' => LearningActionLoopFixtures::now(),
            'updated_at' => LearningActionLoopFixtures::now(),
        ]);
        (new MessageService())->emit('question_update', $learnerId, '问题有新回复', null, [], 'question', $questionId, 'resource:question');

        $item = $this->listFor($learnerId)[0];

        self::assertTrue($item['resource_available']);
        self::assertSame('/courses/' . $courseId, $item['resource_path']);
    }

    public function testListResourceUsesItsServerConfirmedFallbackPathWithoutAnId(): void
    {
        $learnerId = LearningActionLoopFixtures::learner('13900000015');
        (new MessageService())->emit('learning_reminder', $learnerId, '课程入口', null, [], 'course_list', null, 'resource:course-list');

        $item = $this->listFor($learnerId)[0];

        self::assertTrue($item['resource_available']);
        self::assertSame('/', $item['resource_path']);
        self::assertNull($item['resource_unavailable_reason']);
    }

    /** @return list<array<string, mixed>> */
    private function listFor(int $learnerId): array
    {
        $request = new Request("GET /api/learner/v1/messages?page=1&limit=20 HTTP/1.1\r\nHost: test\r\n\r\n");
        /** @phpstan-ignore-next-line */
        $request->account_id = $learnerId;
        $payload = json_decode((string) (new NotificationController())->index($request)->rawBody(), true);
        return is_array($payload['data']['items'] ?? null) ? $payload['data']['items'] : [];
    }
}
