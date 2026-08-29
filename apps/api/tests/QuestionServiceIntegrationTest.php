<?php

declare(strict_types=1);

namespace Tests;

use App\middleware\Authorize;
use App\service\BusinessException;
use App\service\DataScopeService;
use App\service\EntitlementService;
use App\service\QuestionService;
use PHPUnit\Framework\TestCase;
use support\App;
use support\think\Db;
use Throwable;
use Webman\Route;
use Webman\ThinkOrm\ThinkOrm;

final class QuestionServiceIntegrationTest extends TestCase
{
    private QuestionService $service;
    private int $actorId;
    private int $ownerLearnerId;
    private int $authorizedLearnerId;
    private int $unauthorizedLearnerId;
    private int $inScopeCourseId;
    private int $inScopeLessonId;
    private int $inScopeQuestionId;
    private int $selfScopeCourseId;
    private int $selfScopeLessonId;
    private int $selfScopeQuestionId;
    private int $outsideQuestionId;

    public static function setUpBeforeClass(): void
    {
        App::loadAllConfig(['route', 'container']);
        ThinkOrm::start(null);
    }

    protected function setUp(): void
    {
        Db::startTrans();
        try {
            $this->service = new QuestionService(
                new EntitlementService(),
                new DataScopeService(),
            );
            $this->seedFixture();
        } catch (Throwable $exception) {
            Db::rollback();
            throw $exception;
        }
    }

    protected function tearDown(): void
    {
        Db::rollback();
    }

    public function testAnswerFollowupAndReanswerKeepTheCompleteAuthorizedThread(): void
    {
        $asked = $this->service->askOnLesson(
            $this->ownerLearnerId,
            $this->inScopeLessonId,
            'How does the lifecycle work?',
            'Please explain the first answer.',
        );
        $questionId = (int) $asked['question']['id'];

        $firstAnswer = $this->service->adminAnswer($this->actorId, $questionId, 'First answer.');
        $this->assertSame('answered', $firstAnswer['question']['status']);
        $this->assertCount(2, $firstAnswer['messages']);
        $firstAnsweredAt = $firstAnswer['question']['answered_at'];
        $this->assertNotSame('', $firstAnsweredAt);
        $this->assertSame(1, Db::name('learner_notifications')
            ->where('learner_id', $this->ownerLearnerId)
            ->where('kind', 'question_update')
            ->where('resource_type', 'question')
            ->where('resource_id', $questionId)
            ->count());

        $followup = $this->service->appendLearnerFollowup(
            $this->ownerLearnerId,
            $questionId,
            'What about a follow-up?',
        );
        $this->assertSame('pending', $followup['question']['status']);
        $this->assertCount(3, $followup['messages']);

        $secondAnswer = $this->service->adminAnswer($this->actorId, $questionId, 'Second answer.');
        $this->assertSame('answered', $secondAnswer['question']['status']);
        $this->assertSame($firstAnsweredAt, $secondAnswer['question']['answered_at']);
        $this->assertSame(2, Db::name('learner_notifications')
            ->where('learner_id', $this->ownerLearnerId)
            ->where('kind', 'question_update')
            ->where('resource_id', $questionId)
            ->count());
        $this->assertSame(
            [
                'Please explain the first answer.',
                'First answer.',
                'What about a follow-up?',
                'Second answer.',
            ],
            array_column($secondAnswer['messages'], 'body'),
        );

        $authorizedView = $this->service->showForLearner($this->authorizedLearnerId, $questionId);
        $this->assertSame('answered', $authorizedView['question']['status']);
        $this->assertCount(4, $authorizedView['messages']);
        $this->assertNull($authorizedView['messages'][0]['author_learner_id']);

        $this->assertBusinessException(
            fn (): array => $this->service->showForLearner($this->unauthorizedLearnerId, $questionId),
            'FORBIDDEN',
            'NOT_AUTHORIZED',
        );
    }

    public function testAdminInboxCombinesDepartmentAndSelfScopes(): void
    {
        $inbox = $this->service->adminInbox($this->actorId, ['status' => 'pending']);
        $questionIds = array_map('intval', array_column($inbox['items'], 'id'));
        sort($questionIds);

        $expected = [$this->inScopeQuestionId, $this->selfScopeQuestionId];
        sort($expected);
        $this->assertSame($expected, $questionIds);
        $this->assertSame(2, $inbox['total']);
        $this->assertNotContains($this->outsideQuestionId, $questionIds);
    }

    public function testAdminInboxFiltersByCourseAndLessonWithoutWideningScope(): void
    {
        $courseInbox = $this->service->adminInbox($this->actorId, [
            'status' => 'pending',
            'course_id' => $this->inScopeCourseId,
        ]);
        $this->assertSame(
            [$this->inScopeQuestionId],
            array_map('intval', array_column($courseInbox['items'], 'id')),
        );

        $lessonInbox = $this->service->adminInbox($this->actorId, [
            'status' => 'pending',
            'course_id' => $this->selfScopeCourseId,
            'lesson_id' => $this->selfScopeLessonId,
        ]);
        $this->assertSame(
            [$this->selfScopeQuestionId],
            array_map('intval', array_column($lessonInbox['items'], 'id')),
        );

        $outsideInbox = $this->service->adminInbox($this->actorId, [
            'status' => 'pending',
            'course_id' => (int) Db::name('questions')
                ->where('id', $this->outsideQuestionId)
                ->value('course_id'),
        ]);
        $this->assertSame([], $outsideInbox['items']);
        $this->assertSame(0, $outsideInbox['total']);
    }

    public function testAdminFilterOptionsRespectScopeAndSelectedCourse(): void
    {
        $options = $this->service->adminFilterOptions($this->actorId, null);
        $courseIds = array_map('intval', array_column($options['courses'], 'id'));
        sort($courseIds);
        $expectedCourseIds = [$this->inScopeCourseId, $this->selfScopeCourseId];
        sort($expectedCourseIds);
        $this->assertSame($expectedCourseIds, $courseIds);
        $this->assertSame([], $options['lessons']);

        $selected = $this->service->adminFilterOptions(
            $this->actorId,
            $this->selfScopeCourseId,
        );
        $this->assertSame(
            [$this->selfScopeLessonId],
            array_map('intval', array_column($selected['lessons'], 'id')),
        );
    }

    public function testOutOfScopeAdminActionsAreRejectedWithoutMutation(): void
    {
        $beforeStatus = (string) Db::name('questions')
            ->where('id', $this->outsideQuestionId)
            ->value('status');
        $beforeMessageCount = $this->messageCount($this->outsideQuestionId);

        $operations = [
            fn (): array => $this->service->adminShow($this->actorId, $this->outsideQuestionId),
            fn (): array => $this->service->adminAnswer(
                $this->actorId,
                $this->outsideQuestionId,
                'Must not be persisted.',
            ),
            fn (): array => $this->service->adminClose($this->actorId, $this->outsideQuestionId),
        ];

        foreach ($operations as $operation) {
            $this->assertBusinessException(
                $operation,
                'FORBIDDEN',
                'DEPARTMENT_OUT_OF_SCOPE',
            );
            $this->assertSame(
                $beforeStatus,
                (string) Db::name('questions')->where('id', $this->outsideQuestionId)->value('status'),
            );
            $this->assertSame($beforeMessageCount, $this->messageCount($this->outsideQuestionId));
        }
    }

    public function testClosingAnAlreadyClosedQuestionDoesNotAppendAnotherMessage(): void
    {
        $first = $this->service->adminClose($this->actorId, $this->inScopeQuestionId);
        $countAfterFirstClose = $this->messageCount($this->inScopeQuestionId);

        $second = $this->service->adminClose($this->actorId, $this->inScopeQuestionId);

        $this->assertSame('closed', $first['question']['status']);
        $this->assertSame('closed', $second['question']['status']);
        $this->assertSame(2, $countAfterFirstClose);
        $this->assertSame($countAfterFirstClose, $this->messageCount($this->inScopeQuestionId));
    }

    public function testQaContractRoutesAreRegistered(): void
    {
        if (Route::getRoutes() === []) {
            Route::load([app_path()]);
        }
        $registered = [];
        foreach (Route::getRoutes() as $route) {
            foreach ($route->getMethods() as $method) {
                $registered[] = "$method {$route->getPath()}";
            }
        }

        foreach (
            [
                'POST /api/learner/v1/questions/{id}/messages',
                'GET /api/admin/v1/questions',
                'GET /api/admin/v1/questions/filter-options',
                'GET /api/admin/v1/questions/{id}',
                'POST /api/admin/v1/questions/{id}/answer',
                'POST /api/admin/v1/questions/{id}/close',
            ] as $route
        ) {
            $this->assertContains($route, $registered);
        }

        $this->assertNotContains('POST /api/learner/v1/questions/{id}/followup', $registered);
        $this->assertNotContains('GET /api/admin/v1/qa', $registered);
        $this->assertNotContains('GET /api/admin/v1/qa/{id}', $registered);

        $this->assertSame(
            'qa.answer',
            Authorize::permissionFor('/api/admin/v1/questions/7/close', 'POST'),
        );
    }

    private function seedFixture(): void
    {
        $suffix = bin2hex(random_bytes(6));
        $now = date('Y-m-d H:i:s');

        $departmentId = $this->insertDepartment("T066 scope $suffix", $now);
        $outsideDepartmentId = $this->insertDepartment("T066 outside $suffix", $now);
        $this->actorId = $this->insertStaff("t066-actor-$suffix", $departmentId, $now);
        $otherStaffId = $this->insertStaff("t066-other-$suffix", $outsideDepartmentId, $now);

        foreach (['dept', 'self'] as $scope) {
            $roleId = (int) Db::name('roles')->insertGetId([
                'name' => "T066 $scope $suffix",
                'code' => "t066-$scope-$suffix",
                'data_scope' => $scope,
                'status' => 'enabled',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            Db::name('staff_role')->insert([
                'staff_user_id' => $this->actorId,
                'role_id' => $roleId,
            ]);
        }

        $categoryId = (int) Db::name('categories')->insertGetId([
            'parent_id' => 0,
            'name' => "T066 category $suffix",
            'path' => '/',
            'depth' => 1,
            'sort' => 0,
            'status' => 'enabled',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        [$this->inScopeCourseId, $this->inScopeLessonId] = $this->insertCourseWithLesson(
            $departmentId,
            $categoryId,
            $otherStaffId,
            "T066 in-scope $suffix",
            $now,
        );
        [$this->selfScopeCourseId, $this->selfScopeLessonId] = $this->insertCourseWithLesson(
            $outsideDepartmentId,
            $categoryId,
            $this->actorId,
            "T066 self-scope $suffix",
            $now,
        );
        [$outsideCourseId, $outsideLessonId] = $this->insertCourseWithLesson(
            $outsideDepartmentId,
            $categoryId,
            $otherStaffId,
            "T066 outside $suffix",
            $now,
        );

        $this->ownerLearnerId = $this->insertLearner("t066-owner-$suffix", $now);
        $this->authorizedLearnerId = $this->insertLearner("t066-authorized-$suffix", $now);
        $this->unauthorizedLearnerId = $this->insertLearner("t066-unauthorized-$suffix", $now);
        foreach ([$this->ownerLearnerId, $this->authorizedLearnerId] as $learnerId) {
            Db::name('course_entitlements')->insert([
                'learner_id' => $learnerId,
                'course_id' => $this->inScopeCourseId,
                'source' => 'free',
                'order_id' => null,
                'status' => 'active',
                'revoked_at' => null,
                'revoked_reason' => null,
                'revoked_by_staff_id' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $this->inScopeQuestionId = $this->insertQuestion(
            $this->inScopeCourseId,
            $this->inScopeLessonId,
            $this->ownerLearnerId,
            'T066 in-scope question',
            $now,
        );
        $this->selfScopeQuestionId = $this->insertQuestion(
            $this->selfScopeCourseId,
            $this->selfScopeLessonId,
            $this->ownerLearnerId,
            'T066 self-scope question',
            $now,
        );
        $this->outsideQuestionId = $this->insertQuestion(
            $outsideCourseId,
            $outsideLessonId,
            $this->ownerLearnerId,
            'T066 outside question',
            $now,
        );
    }

    private function insertDepartment(string $name, string $now): int
    {
        $id = (int) Db::name('departments')->insertGetId([
            'parent_id' => null,
            'name' => $name,
            'path' => '/',
            'depth' => 1,
            'sort' => 0,
            'status' => 'enabled',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        Db::name('departments')->where('id', $id)->update(['path' => "/$id"]);
        return $id;
    }

    private function insertStaff(string $login, int $departmentId, string $now): int
    {
        $accountId = $this->insertAccount('staff', $login, $now);
        Db::name('staff_users')->insert([
            'account_id' => $accountId,
            'is_super_admin' => 0,
            'department_id' => $departmentId,
            'display_name' => $login,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        return $accountId;
    }

    private function insertLearner(string $login, string $now): int
    {
        return $this->insertAccount('learner', $login, $now);
    }

    private function insertAccount(string $kind, string $login, string $now): int
    {
        return (int) Db::name('accounts')->insertGetId([
            'kind' => $kind,
            'login' => $login,
            'password_hash' => 'not-used-by-test',
            'must_change_password' => 0,
            'status' => 'active',
            'last_login_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /** @return array{int, int} */
    private function insertCourseWithLesson(
        int $departmentId,
        int $categoryId,
        int $creatorId,
        string $title,
        string $now,
    ): array {
        $courseId = (int) Db::name('courses')->insertGetId([
            'department_id' => $departmentId,
            'category_id' => $categoryId,
            'title' => $title,
            'cover_url' => null,
            'teacher_name' => 'T066 teacher',
            'summary' => 'T066 integration fixture',
            'intro_rich_text' => '<p>T066</p>',
            'status' => 'published',
            'price_mode' => 'free',
            'list_price' => 0,
            'sale_price' => 0,
            'sale_start_at' => null,
            'sale_end_at' => null,
            'created_by_staff_id' => $creatorId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $chapterId = (int) Db::name('chapters')->insertGetId([
            'course_id' => $courseId,
            'title' => "$title chapter",
            'sort' => 0,
            'status' => 'enabled',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $lessonId = (int) Db::name('lessons')->insertGetId([
            'chapter_id' => $chapterId,
            'title' => "$title lesson",
            'sort' => 0,
            'status' => 'enabled',
            'content_type' => 'markdown',
            'body_markdown' => 'T066 lesson fixture',
            'asset_id' => null,
            'is_preview' => 0,
            'duration_seconds' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        return [$courseId, $lessonId];
    }

    private function insertQuestion(
        int $courseId,
        int $lessonId,
        int $learnerId,
        string $title,
        string $now,
    ): int {
        $chapterId = (int) Db::name('lessons')->where('id', $lessonId)->value('chapter_id');
        $id = (int) Db::name('questions')->insertGetId([
            'course_id' => $courseId,
            'chapter_id' => $chapterId,
            'lesson_id' => $lessonId,
            'learner_id' => $learnerId,
            'title' => $title,
            'body' => "$title body",
            'status' => 'pending',
            'answered_at' => null,
            'answered_by_staff_id' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        Db::name('question_messages')->insert([
            'question_id' => $id,
            'kind' => 'questioner',
            'author_learner_id' => $learnerId,
            'author_staff_id' => null,
            'body' => "$title body",
            'created_at' => $now,
        ]);
        return $id;
    }

    private function messageCount(int $questionId): int
    {
        return (int) Db::name('question_messages')->where('question_id', $questionId)->count();
    }

    private function assertBusinessException(
        callable $operation,
        string $apiCode,
        string $message,
    ): void {
        try {
            $operation();
            $this->fail("Expected $apiCode: $message");
        } catch (BusinessException $exception) {
            $this->assertSame($apiCode, $exception->apiCode);
            $this->assertSame($message, $exception->getMessage());
        }
    }
}
