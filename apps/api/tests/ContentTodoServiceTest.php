<?php

declare(strict_types=1);

namespace Tests;

use App\service\BusinessException;
use App\service\ContentTodoService;
use App\service\OpsInboxService;
use App\service\ReviewService;
use PHPUnit\Framework\TestCase;
use support\App;
use support\think\Db;
use Webman\ThinkOrm\ThinkOrm;

final class ContentTodoServiceTest extends TestCase
{
    private int $staffId;
    private int $learnerId;
    private int $courseId;
    private int $chapterId;
    private int $lessonId;
    private int $questionId;
    private int $feedbackId;

    public static function setUpBeforeClass(): void
    {
        App::loadAllConfig(['route', 'container']);
        ThinkOrm::start(null);
    }

    protected function setUp(): void
    {
        Db::startTrans();
        $now = gmdate('Y-m-d H:i:s');
        $this->staffId = (int) Db::name('accounts')->insertGetId([
            'kind' => 'staff',
            'login' => 'content-todo-staff-' . bin2hex(random_bytes(4)),
            'password_hash' => 'hash',
            'must_change_password' => 0,
            'status' => 'active',
            'last_login_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        Db::name('staff_users')->insert([
            'account_id' => $this->staffId,
            'is_super_admin' => 1,
            'department_id' => null,
            'display_name' => 'Content Todo Test',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $categoryId = (int) Db::name('categories')->insertGetId([
            'parent_id' => 0,
            'name' => 'content-todo-' . bin2hex(random_bytes(4)),
            'path' => '/',
            'depth' => 1,
            'sort' => 0,
            'status' => 'enabled',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $this->learnerId = (int) Db::name('accounts')->insertGetId([
            'kind' => 'learner',
            'login' => '139' . random_int(100000000, 999999999),
            'password_hash' => 'hash',
            'must_change_password' => 0,
            'status' => 'active',
            'last_login_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $this->insertLearnerProfile($this->learnerId, $now);
        $this->courseId = (int) Db::name('courses')->insertGetId([
            'department_id' => null,
            'category_id' => $categoryId,
            'title' => '内容待办课程',
            'cover_url' => null,
            'teacher_name' => '讲师',
            'summary' => '',
            'intro_rich_text' => '<p>原始简介</p>',
            'status' => 'published',
            'price_mode' => 'free',
            'list_price' => 0,
            'sale_price' => 0,
            'sale_start_at' => null,
            'sale_end_at' => null,
            'created_by_staff_id' => $this->staffId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $this->chapterId = (int) Db::name('chapters')->insertGetId([
            'course_id' => $this->courseId,
            'title' => '第一章',
            'sort' => 1,
            'status' => 'enabled',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $this->lessonId = (int) Db::name('lessons')->insertGetId([
            'chapter_id' => $this->chapterId,
            'title' => '第一课',
            'sort' => 1,
            'status' => 'enabled',
            'content_type' => 'markdown',
            'body_markdown' => '原始正文',
            'asset_id' => null,
            'is_preview' => 0,
            'duration_seconds' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $this->questionId = (int) Db::name('questions')->insertGetId([
            'course_id' => $this->courseId,
            'chapter_id' => $this->chapterId,
            'lesson_id' => $this->lessonId,
            'learner_id' => $this->learnerId,
            'title' => '为什么没有例子？',
            'body' => '请补充一个例子。',
            'status' => 'pending',
            'answered_at' => null,
            'answered_by_staff_id' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $this->feedbackId = (int) Db::name('course_feedbacks')->insertGetId([
            'course_id' => $this->courseId,
            'learner_id' => $this->learnerId,
            'body_html' => '<p>私有反馈正文</p>',
            'status' => 'pending',
            'processed_by_staff_id' => null,
            'processed_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    protected function tearDown(): void
    {
        Db::rollback();
    }

    public function testListProjectsBothSourcesAndUsesCompositeIdentity(): void
    {
        $service = new ContentTodoService();
        $result = $service->list($this->staffId, [
            'ops_inbox.view',
            'content_todo.manage',
            'qa.view',
            'course_feedback.manage',
        ]);
        $mine = array_values(array_filter(
            $result['items'],
            fn (array $item): bool => (int) $item['source_course_id'] === $this->courseId,
        ));

        self::assertCount(2, $mine);
        self::assertSame(
            ['question_pending', 'feedback_pending'],
            array_values(array_column($mine, 'source_type')),
        );
        self::assertSame(2, (int) Db::name('content_todos')->where('source_course_id', $this->courseId)->count());
        self::assertSame(1, (int) Db::name('content_todos')->where('source_type', 'question_pending')->where('source_key', (string) $this->questionId)->count());
        self::assertSame(1, (int) Db::name('content_todos')->where('source_type', 'feedback_pending')->where('source_key', (string) $this->feedbackId)->count());
        foreach ($mine as $item) {
            self::assertFalse($item['first_response_confirmed']);
            self::assertGreaterThanOrEqual(0, $item['age_seconds']);
            self::assertNotSame('', $item['age_label']);
            self::assertSame($this->courseId, $item['target']['course_id']);
            self::assertNull($item['result_type']);
        }
    }

    public function testFeedbackCannotBeReadWithReviewPermissionOnly(): void
    {
        $service = new ContentTodoService();
        $service->list($this->staffId, ['ops_inbox.view', 'course_feedback.manage']);
        $todoId = $this->feedbackTodoId();

        $this->expectException(BusinessException::class);
        $this->expectExceptionMessage('FORBIDDEN');
        $service->get($this->staffId, $todoId, ['ops_inbox.view', 'review.view']);
    }

    public function testCandidateApprovalUpdatesMarkdownAndRecordsResult(): void
    {
        $service = new ContentTodoService();
        $service->list($this->staffId, ['ops_inbox.view', 'content_todo.manage', 'qa.view', 'course_feedback.manage']);
        $todoId = $this->questionTodoId();

        $service->triage($this->staffId, $todoId, [
            'label' => 'missing_example',
            'target_course_id' => $this->courseId,
            'target_chapter_id' => $this->chapterId,
            'target_lesson_id' => $this->lessonId,
        ], ['content_todo.manage', 'qa.view']);
        $detail = $service->generateCandidate($this->staffId, $todoId, [
            'target_kind' => 'lesson_markdown',
        ], ['content_todo.manage', 'qa.view']);
        $candidateId = (int) $detail['candidates'][0]['id'];

        $approved = $service->approveCandidate($this->staffId, $todoId, $candidateId, [
            'notify_mode' => 'none',
        ], ['content_todo.manage', 'course.manage', 'qa.view']);

        self::assertSame('resolved', $approved['workflow_status']);
        self::assertSame('content_updated', $approved['result_type']);
        self::assertSame('lesson_markdown', $approved['candidates'][0]['target_kind']);
        self::assertNotSame('原始正文', (string) Db::name('lessons')->where('id', $this->lessonId)->value('body_markdown'));

        $remaining = $service->list($this->staffId, ['ops_inbox.view', 'content_todo.manage', 'qa.view', 'course_feedback.manage']);
        $remainingMine = array_values(array_filter(
            $remaining['items'],
            fn (array $item): bool => (int) $item['source_course_id'] === $this->courseId,
        ));
        self::assertNotContains($this->questionId, array_map('intval', array_column($remainingMine, 'source_key')));

        $inbox = (new OpsInboxService())->list($this->staffId, ['qa.view'], [
            'source_type' => 'question_pending',
            'limit' => 50,
        ]);
        self::assertNotContains((string) $this->questionId, array_column($inbox['items'], 'source_key'));
    }

    public function testAnsweredOrProcessedSourcesRemainVisibleUntilContentTodoIsResolved(): void
    {
        $service = new ContentTodoService();
        $permissions = ['ops_inbox.view', 'content_todo.manage', 'qa.view', 'course_feedback.manage'];
        $service->list($this->staffId, $permissions);

        Db::name('questions')->where('id', $this->questionId)->update([
            'status' => 'answered',
            'answered_at' => gmdate('Y-m-d H:i:s'),
            'answered_by_staff_id' => $this->staffId,
        ]);
        Db::name('course_feedbacks')->where('id', $this->feedbackId)->update([
            'status' => 'processed',
            'processed_at' => gmdate('Y-m-d H:i:s'),
            'processed_by_staff_id' => $this->staffId,
        ]);

        $result = $service->list($this->staffId, $permissions);
        $mine = array_values(array_filter(
            $result['items'],
            fn (array $item): bool => (int) $item['source_course_id'] === $this->courseId,
        ));

        self::assertSame(
            ['question_pending', 'feedback_pending'],
            array_values(array_column($mine, 'source_type')),
        );

        $inbox = (new OpsInboxService())->list($this->staffId, [
            'qa.view',
            'course_feedback.manage',
        ], [
            'source_type' => 'question_pending',
            'limit' => 50,
        ]);
        self::assertContains((string) $this->questionId, array_column($inbox['items'], 'source_key'));
    }

    public function testInvalidNotifyModeDoesNotWriteContent(): void
    {
        $service = new ContentTodoService();
        $permissions = ['ops_inbox.view', 'content_todo.manage', 'qa.view', 'course_feedback.manage'];
        $service->list($this->staffId, $permissions);
        $todoId = $this->questionTodoId();

        $service->triage($this->staffId, $todoId, [
            'label' => 'missing_example',
            'target_course_id' => $this->courseId,
            'target_chapter_id' => $this->chapterId,
            'target_lesson_id' => $this->lessonId,
        ], ['content_todo.manage', 'qa.view']);
        $detail = $service->generateCandidate($this->staffId, $todoId, [
            'target_kind' => 'lesson_markdown',
        ], ['content_todo.manage', 'qa.view']);
        $candidateId = (int) $detail['candidates'][0]['id'];
        $before = (string) Db::name('lessons')->where('id', $this->lessonId)->value('body_markdown');

        $this->expectException(BusinessException::class);
        $this->expectExceptionMessage('CONTENT_TODO_NOTIFY_MODE_INVALID');
        try {
            $service->approveCandidate($this->staffId, $todoId, $candidateId, [
                'notify_mode' => 'invalid',
            ], ['content_todo.manage', 'course.manage', 'qa.view']);
        } finally {
            self::assertSame($before, (string) Db::name('lessons')->where('id', $this->lessonId)->value('body_markdown'));
            self::assertSame('draft', (string) Db::name('content_todo_candidates')->where('id', $candidateId)->value('status'));
            self::assertSame('awaiting_approval', (string) Db::name('content_todos')->where('id', $todoId)->value('workflow_status'));
        }
    }

    public function testCloseRequiresReasonAndNeverCopiesPrivateBodyIntoAudit(): void
    {
        $service = new ContentTodoService();
        $service->list($this->staffId, ['ops_inbox.view', 'content_todo.manage', 'course_feedback.manage']);
        $todoId = $this->feedbackTodoId();

        $service->close($this->staffId, $todoId, [
            'close_reason_code' => 'already_covered',
            'close_reason_note' => '已有同类课程说明',
        ], ['content_todo.manage', 'course_feedback.manage', 'ops_inbox.view']);

        $payload = (string) Db::name('audit_log')
            ->where('target_type', 'content_todo')
            ->where('target_id', $todoId)
            ->order('id', 'desc')
            ->value('payload_json');
        self::assertStringNotContainsString('私有反馈正文', $payload);
        self::assertSame('closed', (string) Db::name('content_todos')->where('id', $todoId)->value('workflow_status'));
    }

    public function testCloseHonoursExpectedVersion(): void
    {
        $service = new ContentTodoService();
        $service->list($this->staffId, ['ops_inbox.view', 'content_todo.manage', 'course_feedback.manage']);
        $todoId = $this->feedbackTodoId();

        $this->expectException(BusinessException::class);
        $this->expectExceptionMessage('CONTENT_TODO_VERSION_CONFLICT');
        $service->close($this->staffId, $todoId, [
            'close_reason_code' => 'already_covered',
            'close_reason_note' => '已有同类课程说明',
            'expected_version' => 999,
        ], ['content_todo.manage', 'course_feedback.manage', 'ops_inbox.view']);
    }

    public function testEnrolledNotifyUsesDispatchFanOutInsteadOfPerLearnerEmit(): void
    {
        $now = gmdate('Y-m-d H:i:s');
        $otherLearnerId = $this->insertLearnerAccount($now);
        foreach ([$this->learnerId, $otherLearnerId] as $learnerId) {
            Db::name('course_entitlements')->insert([
                'learner_id' => $learnerId,
                'course_id' => $this->courseId,
                'source' => 'free',
                'order_id' => null,
                'status' => 'active',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $service = new ContentTodoService();
        $permissions = ['ops_inbox.view', 'content_todo.manage', 'qa.view', 'course.manage'];
        $service->list($this->staffId, $permissions);
        $todoId = $this->questionTodoId();
        $service->triage($this->staffId, $todoId, [
            'label' => 'missing_example',
            'target_course_id' => $this->courseId,
            'target_chapter_id' => $this->chapterId,
            'target_lesson_id' => $this->lessonId,
        ], $permissions);
        $detail = $service->generateCandidate($this->staffId, $todoId, [
            'target_kind' => 'lesson_markdown',
        ], $permissions);
        $candidateId = (int) $detail['candidates'][0]['id'];
        $candidateVersion = (int) $detail['candidates'][0]['version'];

        $service->approveCandidate($this->staffId, $todoId, $candidateId, [
            'notify_mode' => 'enrolled',
        ], $permissions);

        self::assertSame(0, (int) Db::name('learner_notifications')
            ->where('idempotency_key', 'content_todo:' . $todoId . ':' . $candidateVersion . ':' . $this->learnerId)
            ->count());
        $dispatch = Db::name('notification_dispatches')
            ->where('resource_type', 'course')
            ->where('resource_id', $this->courseId)
            ->where('title', '课程内容已更新')
            ->order('id', 'desc')
            ->find();
        self::assertIsArray($dispatch);
        self::assertSame(2, (int) $dispatch['recipient_count']);
        self::assertSame(
            2,
            (int) Db::name('notification_dispatch_recipients')->where('dispatch_id', (int) $dispatch['id'])->count(),
        );
        self::assertStringNotContainsString('私有反馈正文', (string) $dispatch['body']);
        self::assertStringNotContainsString('请补充一个例子。', (string) $dispatch['body']);
    }

    public function testDuplicateSourceIdentityIsRejectedByUniqueKey(): void
    {
        $service = new ContentTodoService();
        $service->list($this->staffId, ['ops_inbox.view', 'qa.view', 'course_feedback.manage']);
        $now = gmdate('Y-m-d H:i:s');
        $this->expectException(\Throwable::class);
        Db::name('content_todos')->insert([
            'source_type' => 'question_pending',
            'source_key' => (string) $this->questionId,
            'source_course_id' => $this->courseId,
            'workflow_status' => 'untriaged',
            'version' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function testGeneratedDraftDoesNotWriteLessonOrNotifyLearners(): void
    {
        $service = new ContentTodoService();
        $permissions = ['ops_inbox.view', 'content_todo.manage', 'qa.view'];
        $service->list($this->staffId, $permissions);
        $todoId = $this->questionTodoId();
        $service->triage($this->staffId, $todoId, [
            'label' => 'missing_example',
            'target_course_id' => $this->courseId,
            'target_chapter_id' => $this->chapterId,
            'target_lesson_id' => $this->lessonId,
        ], $permissions);
        $detail = $service->generateCandidate($this->staffId, $todoId, [
            'target_kind' => 'lesson_markdown',
            'body' => '草稿例子不应出现在课节里',
        ], $permissions);

        self::assertSame('awaiting_approval', $detail['workflow_status']);
        self::assertSame('draft', $detail['candidates'][0]['status']);
        self::assertSame('原始正文', (string) Db::name('lessons')->where('id', $this->lessonId)->value('body_markdown'));
        self::assertSame('<p>原始简介</p>', (string) Db::name('courses')->where('id', $this->courseId)->value('intro_rich_text'));
        self::assertSame(0, (int) Db::name('learner_notifications')->where('body', 'like', '%草稿例子不应出现在课节里%')->count());
        self::assertSame(0, (int) Db::name('question_messages')->where('body', 'like', '%草稿例子不应出现在课节里%')->count());
    }

    public function testFingerprintMismatchDoesNotOverwriteLesson(): void
    {
        $service = new ContentTodoService();
        $permissions = ['ops_inbox.view', 'content_todo.manage', 'qa.view', 'course.manage'];
        $service->list($this->staffId, $permissions);
        $todoId = $this->questionTodoId();
        $service->triage($this->staffId, $todoId, [
            'label' => 'missing_example',
            'target_course_id' => $this->courseId,
            'target_chapter_id' => $this->chapterId,
            'target_lesson_id' => $this->lessonId,
        ], $permissions);
        $detail = $service->generateCandidate($this->staffId, $todoId, [
            'target_kind' => 'lesson_markdown',
            'body' => '候选草稿正文',
        ], $permissions);
        $candidateId = (int) $detail['candidates'][0]['id'];
        Db::name('lessons')->where('id', $this->lessonId)->update(['body_markdown' => '其他人改过的正文']);

        try {
            $service->approveCandidate($this->staffId, $todoId, $candidateId, [
                'notify_mode' => 'none',
            ], $permissions);
            self::fail('changed content must conflict');
        } catch (BusinessException $exception) {
            self::assertSame('CONTENT_TODO_CONTENT_CHANGED', $exception->getMessage());
        }
        self::assertSame('其他人改过的正文', (string) Db::name('lessons')->where('id', $this->lessonId)->value('body_markdown'));
        self::assertSame('draft', (string) Db::name('content_todo_candidates')->where('id', $candidateId)->value('status'));
        self::assertSame('awaiting_approval', (string) Db::name('content_todos')->where('id', $todoId)->value('workflow_status'));
    }

    public function testOutOfScopeStaffCannotReadOrApprove(): void
    {
        $service = new ContentTodoService();
        $permissions = ['ops_inbox.view', 'content_todo.manage', 'qa.view', 'course.manage'];
        $service->list($this->staffId, $permissions);
        $todoId = $this->questionTodoId();
        $now = gmdate('Y-m-d H:i:s');
        $departmentId = (int) Db::name('departments')->insertGetId([
            'parent_id' => null,
            'name' => 'out-of-scope-' . bin2hex(random_bytes(3)),
            'path' => '/',
            'depth' => 1,
            'sort' => 0,
            'status' => 'enabled',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        Db::name('departments')->where('id', $departmentId)->update(['path' => '/' . $departmentId]);
        $outsiderId = (int) Db::name('accounts')->insertGetId([
            'kind' => 'staff',
            'login' => 'outsider-' . bin2hex(random_bytes(3)),
            'password_hash' => 'hash',
            'must_change_password' => 0,
            'status' => 'active',
            'last_login_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        Db::name('staff_users')->insert([
            'account_id' => $outsiderId,
            'is_super_admin' => 0,
            'department_id' => $departmentId,
            'display_name' => 'Outsider',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        try {
            $service->get($outsiderId, $todoId, $permissions);
            self::fail('out-of-scope read must be rejected');
        } catch (BusinessException $exception) {
            self::assertSame('DEPARTMENT_OUT_OF_SCOPE', $exception->getMessage());
        }
        try {
            $service->approveCandidate($outsiderId, $todoId, 1, ['notify_mode' => 'none'], $permissions);
            self::fail('out-of-scope approve must be rejected');
        } catch (BusinessException $exception) {
            self::assertSame('DEPARTMENT_OUT_OF_SCOPE', $exception->getMessage());
        }
        self::assertSame('原始正文', (string) Db::name('lessons')->where('id', $this->lessonId)->value('body_markdown'));
    }

    public function testInboxCannotResolveContentSourcesAsContentResult(): void
    {
        $this->expectException(BusinessException::class);
        $this->expectExceptionMessage('CONTENT_TODO_REQUIRED');
        (new OpsInboxService())->transitionState($this->staffId, 'question_pending:' . $this->questionId, [
            'to_state' => 'resolved',
        ]);
    }

    public function testReviewModerationDoesNotCreateContentTodos(): void
    {
        $now = gmdate('Y-m-d H:i:s');
        $reviewId = (int) Db::name('reviews')->insertGetId([
            'course_id' => $this->courseId,
            'learner_id' => $this->learnerId,
            'rating' => 5,
            'body' => '公开评价不应进入内容待办',
            'visibility' => 'public',
            'active_key' => $this->learnerId . ':' . $this->courseId,
            'hidden_reason' => null,
            'hidden_by_staff_id' => null,
            'hidden_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $before = (int) Db::name('content_todos')->count();
        (new ReviewService(new \App\service\EntitlementService(), new \App\service\DataScopeService()))
            ->hideReview($this->staffId, $reviewId, '与内容待办无关');
        self::assertSame($before, (int) Db::name('content_todos')->count());
        self::assertSame(0, (int) Db::name('content_todos')->where('source_key', (string) $reviewId)->count());
    }

    public function testEmptyEnrolledRecipientsDoNotBlockApprovedWrite(): void
    {
        $service = new ContentTodoService();
        $permissions = ['ops_inbox.view', 'content_todo.manage', 'qa.view', 'course.manage'];
        $service->list($this->staffId, $permissions);
        $todoId = $this->questionTodoId();
        $service->triage($this->staffId, $todoId, [
            'label' => 'missing_example',
            'target_course_id' => $this->courseId,
            'target_chapter_id' => $this->chapterId,
            'target_lesson_id' => $this->lessonId,
        ], $permissions);
        $detail = $service->generateCandidate($this->staffId, $todoId, [
            'target_kind' => 'lesson_markdown',
            'body' => '无收件人时仍写回',
        ], $permissions);
        $candidateId = (int) $detail['candidates'][0]['id'];

        $approved = $service->approveCandidate($this->staffId, $todoId, $candidateId, [
            'notify_mode' => 'enrolled',
        ], $permissions);

        self::assertSame('resolved', $approved['workflow_status']);
        self::assertSame('content_updated', $approved['result_type']);
        self::assertSame('无收件人时仍写回', (string) Db::name('lessons')->where('id', $this->lessonId)->value('body_markdown'));
        self::assertSame(0, (int) Db::name('notification_dispatches')->where('title', '课程内容已更新')->count());
    }

    public function testFeedbackApproveNoticeOmitsPrivateBody(): void
    {
        $service = new ContentTodoService();
        $permissions = ['ops_inbox.view', 'content_todo.manage', 'course_feedback.manage', 'course.manage'];
        $service->list($this->staffId, $permissions);
        $todoId = $this->feedbackTodoId();
        $service->triage($this->staffId, $todoId, [
            'label' => 'other',
            'target_course_id' => $this->courseId,
        ], $permissions);
        $detail = $service->generateCandidate($this->staffId, $todoId, [
            'target_kind' => 'course_intro',
            'body' => '<p>已补充说明</p>',
        ], $permissions);
        $candidateId = (int) $detail['candidates'][0]['id'];
        $service->approveCandidate($this->staffId, $todoId, $candidateId, [
            'notify_mode' => 'submitter',
        ], $permissions);

        $notice = (string) Db::name('learner_notifications')
            ->where('learner_id', $this->learnerId)
            ->where('idempotency_key', 'like', 'content_todo:' . $todoId . ':%')
            ->value('body');
        self::assertNotSame('', $notice);
        self::assertStringNotContainsString('私有反馈正文', $notice);
        self::assertStringNotContainsString('<p>已补充说明</p>', $notice);
    }

    public function testPrivateFeedbackRespondUsesGenericNotice(): void
    {
        $service = new ContentTodoService();
        $permissions = ['ops_inbox.view', 'content_todo.manage', 'course_feedback.manage'];
        $service->list($this->staffId, $permissions);
        $todoId = $this->feedbackTodoId();

        $service->respond($this->staffId, $todoId, [
            'body' => '内部备注: 私有反馈正文不要外泄',
        ], $permissions);

        $notice = (string) Db::name('learner_notifications')
            ->where('learner_id', $this->learnerId)
            ->where('idempotency_key', 'like', 'content_todo:' . $todoId . ':response:%')
            ->value('body');
        self::assertNotSame('', $notice);
        self::assertStringNotContainsString('私有反馈正文', $notice);
        self::assertStringNotContainsString('内部备注', $notice);
    }

    public function testTimestampsUseShanghaiClock(): void
    {
        $service = new ContentTodoService();
        $permissions = ['ops_inbox.view', 'content_todo.manage', 'course_feedback.manage'];
        $service->list($this->staffId, $permissions);
        $todoId = $this->feedbackTodoId();
        $before = nowDatetime();
        $service->close($this->staffId, $todoId, [
            'close_reason_code' => 'already_covered',
            'close_reason_note' => '已有同类课程说明',
        ], $permissions);
        $after = nowDatetime();
        $resolvedAt = (string) Db::name('content_todos')->where('id', $todoId)->value('resolved_at');
        self::assertGreaterThanOrEqual($before, $resolvedAt);
        self::assertLessThanOrEqual($after, $resolvedAt);
        $utc = gmdate('Y-m-d H:i:s');
        $shanghai = nowDatetime();
        if ($utc !== $shanghai) {
            self::assertNotSame($utc, $resolvedAt);
        }
    }

    public function testResourceProblemCannotPatchCourseIntro(): void
    {
        $service = new ContentTodoService();
        $permissions = ['ops_inbox.view', 'content_todo.manage', 'course_feedback.manage'];
        $service->list($this->staffId, $permissions);
        $todoId = $this->feedbackTodoId();
        $service->triage($this->staffId, $todoId, [
            'label' => 'resource_problem',
            'target_course_id' => $this->courseId,
        ], $permissions);

        $this->expectException(BusinessException::class);
        $this->expectExceptionMessage('CONTENT_TODO_RESOURCE_PATCH_FORBIDDEN');
        $service->generateCandidate($this->staffId, $todoId, [
            'target_kind' => 'course_intro',
        ], $permissions);
    }

    public function testResourceProblemDefaultsToHelpCenterHandoff(): void
    {
        $service = new ContentTodoService();
        $permissions = ['ops_inbox.view', 'content_todo.manage', 'course_feedback.manage'];
        $service->list($this->staffId, $permissions);
        $todoId = $this->feedbackTodoId();
        $service->triage($this->staffId, $todoId, [
            'label' => 'resource_problem',
            'target_course_id' => $this->courseId,
        ], $permissions);
        $detail = $service->generateCandidate($this->staffId, $todoId, [], $permissions);
        self::assertSame('help_center_candidate', $detail['candidates'][0]['target_kind']);
        $candidateId = (int) $detail['candidates'][0]['id'];
        $approved = $service->approveCandidate($this->staffId, $todoId, $candidateId, [
            'notify_mode' => 'none',
        ], $permissions);
        self::assertSame('help_center_candidate', $approved['result_type']);
        self::assertSame('<p>原始简介</p>', (string) Db::name('courses')->where('id', $this->courseId)->value('intro_rich_text'));
    }

    public function testMarkdownCandidateStripsUnsafeHtml(): void
    {
        $service = new ContentTodoService();
        $permissions = ['ops_inbox.view', 'content_todo.manage', 'qa.view'];
        $service->list($this->staffId, $permissions);
        $todoId = $this->questionTodoId();
        $service->triage($this->staffId, $todoId, [
            'label' => 'missing_example',
            'target_course_id' => $this->courseId,
            'target_chapter_id' => $this->chapterId,
            'target_lesson_id' => $this->lessonId,
        ], $permissions);
        $detail = $service->generateCandidate($this->staffId, $todoId, [
            'target_kind' => 'lesson_markdown',
            'body' => "例子\n<script>alert(1)</script>\n[x](javascript:alert(1))",
        ], $permissions);
        $body = (string) $detail['candidates'][0]['body'];
        self::assertStringNotContainsString('<script>', $body);
        self::assertStringNotContainsString('javascript:', $body);
    }

    public function testCloseUpdatesSourceStatusAndRequiresTarget(): void
    {
        $service = new ContentTodoService();
        $permissions = ['ops_inbox.view', 'content_todo.manage', 'course_feedback.manage'];
        $service->list($this->staffId, $permissions);
        $todoId = $this->feedbackTodoId();
        Db::name('content_todos')->where('id', $todoId)->update(['target_course_id' => null]);

        try {
            $service->close($this->staffId, $todoId, [
                'close_reason_code' => 'already_covered',
                'close_reason_note' => '已有同类课程说明',
            ], $permissions);
            self::fail('close without target must fail');
        } catch (BusinessException $exception) {
            self::assertSame('CONTENT_TODO_TARGET_REQUIRED', $exception->getMessage());
        }

        $service->triage($this->staffId, $todoId, [
            'label' => 'other',
            'target_course_id' => $this->courseId,
        ], $permissions);
        $service->close($this->staffId, $todoId, [
            'close_reason_code' => 'already_covered',
            'close_reason_note' => '已有同类课程说明',
        ], $permissions);
        self::assertSame('processed', (string) Db::name('course_feedbacks')->where('id', $this->feedbackId)->value('status'));
    }

    public function testQuestionFirstResponseUsesAnsweredAt(): void
    {
        $service = new ContentTodoService();
        $permissions = ['ops_inbox.view', 'content_todo.manage', 'qa.view'];
        $service->list($this->staffId, $permissions);
        $answeredAt = nowDatetime();
        Db::name('questions')->where('id', $this->questionId)->update([
            'status' => 'answered',
            'answered_at' => $answeredAt,
            'answered_by_staff_id' => $this->staffId,
        ]);
        $result = $service->list($this->staffId, $permissions);
        $item = null;
        foreach ($result['items'] as $row) {
            if ((int) $row['source_key'] === $this->questionId) {
                $item = $row;
                break;
            }
        }
        self::assertIsArray($item);
        self::assertTrue($item['first_response_confirmed']);
        self::assertSame($answeredAt, $item['first_response_at']);
    }

    private function questionTodoId(): int
    {
        return (int) Db::name('content_todos')
            ->where('source_type', 'question_pending')
            ->where('source_key', (string) $this->questionId)
            ->value('id');
    }

    private function feedbackTodoId(): int
    {
        return (int) Db::name('content_todos')
            ->where('source_type', 'feedback_pending')
            ->where('source_key', (string) $this->feedbackId)
            ->value('id');
    }

    private function insertLearnerAccount(string $now): int
    {
        $id = (int) Db::name('accounts')->insertGetId([
            'kind' => 'learner',
            'login' => '139' . random_int(100000000, 999999999),
            'password_hash' => 'hash',
            'must_change_password' => 0,
            'status' => 'active',
            'last_login_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $this->insertLearnerProfile($id, $now);
        return $id;
    }

    private function insertLearnerProfile(int $accountId, string $now): void
    {
        Db::name('learners')->insert([
            'account_id' => $accountId,
            'nickname' => null,
            'avatar_url' => null,
            'show_on_course' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
}
