<?php

declare(strict_types=1);

namespace Tests;

use App\controller\learner\NotificationController;
use App\service\LearningActionService;
use App\service\MessageService;
use PHPUnit\Framework\TestCase;
use support\App;
use support\Request;
use support\think\Db;
use Webman\ThinkOrm\ThinkOrm;

final class LearningActionLoopIntegrationTest extends TestCase
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

    public function testReminderResourceAndNextActionRemainLearnerIsolated(): void
    {
        $ownerId = LearningActionLoopFixtures::learner('13900000015');
        $otherId = LearningActionLoopFixtures::learner('13900000016');
        $staffId = LearningActionLoopFixtures::staff('fixture-integration-staff');
        $courseId = LearningActionLoopFixtures::course($staffId);
        $lessonId = LearningActionLoopFixtures::lesson($courseId);
        LearningActionLoopFixtures::grant($ownerId, $courseId);
        LearningActionLoopFixtures::favorite($otherId, $courseId, '2026-09-02 01:00:00');

        (new MessageService())->emit(
            MessageService::KIND_LEARNING_REMINDER,
            $ownerId,
            '继续学习',
            '继续上次未完成的课节',
            ['rule_code' => 'continue_authorized_lesson'],
            'lesson',
            $lessonId,
            'integration:lesson',
        );

        $action = (new LearningActionService())->nextAction($ownerId, $this->at('2026-09-04 10:00:00'));
        $messages = $this->messagesFor($ownerId);
        $otherMessages = $this->messagesFor($otherId);

        self::assertSame($lessonId, $action['action']['target']['resource_id']);
        self::assertCount(1, $messages);
        self::assertCount(0, $otherMessages);
        self::assertSame('/learn/' . $courseId . '/' . $lessonId, $messages[0]['resource_path']);
    }

    public function testAnInvalidNextActionOnlyReturnsTheServerFallback(): void
    {
        $learnerId = LearningActionLoopFixtures::learner('13900000017');
        $result = (new LearningActionService())->nextAction($learnerId, $this->at('2026-09-04 10:00:00'));

        self::assertSame('ready', $result['state']);
        self::assertNull($result['fallback']);
        self::assertSame('browse_courses', $result['action']['type']);
        self::assertSame([], $result['degraded_dependencies']);
    }

    /** @return list<array<string, mixed>> */
    private function messagesFor(int $learnerId): array
    {
        $request = new Request("GET /api/learner/v1/messages?page=1&limit=20 HTTP/1.1\r\nHost: test\r\n\r\n");
        /** @phpstan-ignore-next-line */
        $request->account_id = $learnerId;
        $payload = json_decode((string) (new NotificationController())->index($request)->rawBody(), true);
        return is_array($payload['data']['items'] ?? null) ? $payload['data']['items'] : [];
    }

    private function at(string $value): \DateTimeImmutable
    {
        return new \DateTimeImmutable($value, new \DateTimeZone('Asia/Shanghai'));
    }
}
