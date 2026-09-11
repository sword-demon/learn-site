<?php

declare(strict_types=1);

namespace App\service;

use App\model\ContentTodo;
use App\model\ContentTodoCandidate;
use App\support\HtmlSanitizer;
use App\support\Logger;
use support\think\Db;
use think\db\exception\PDOException as ThinkPdoException;

use function nowDatetime;

/**
 * Content workflow orchestration for Q&A and private course feedback.
 *
 * The service intentionally owns the workflow instead of extending
 * ops_inbox_state. The inbox is an operational projection; this record owns
 * the durable content result, candidate history, and learner-facing outcome.
 */
final class ContentTodoService
{
    private const MAX_PAGE_LIMIT = 50;
    private const MAX_BODY_LENGTH = 200_000;
    private const LABELS = [
        ContentTodo::LABEL_ERROR,
        ContentTodo::LABEL_MISSING_EXAMPLE,
        ContentTodo::LABEL_RESOURCE_PROBLEM,
        ContentTodo::LABEL_OTHER,
    ];
    private const CLOSE_REASONS = ['duplicate', 'not_actionable', 'already_covered', 'not_planned'];
    private const WORKFLOW_STATUSES = [
        ContentTodo::STATUS_UNTRIAGED,
        ContentTodo::STATUS_TRIAGED,
        ContentTodo::STATUS_AWAITING_APPROVAL,
        ContentTodo::STATUS_RESOLVED,
        ContentTodo::STATUS_CLOSED,
    ];
    private const NOTIFY_MODES = ['none', 'submitter', 'enrolled', 'both'];

    public function __construct(
        private readonly DataScopeService $scope = new DataScopeService(),
        private readonly PermissionService $permissions = new PermissionService(),
        private readonly MessageService $messages = new MessageService(),
        private readonly CourseService $courses = new CourseService(),
        private readonly NotificationDispatchService $dispatches = new NotificationDispatchService(),
    ) {
    }

    /**
     * @param list<string> $permissions
     * @param array<string,mixed> $filters
     * @return array{items:list<array<string,mixed>>,total:int,page:int,limit:int}
     */
    public function list(int $staffId, array $permissions = [], array $filters = []): array
    {
        $this->assertStaff($staffId);
        $effective = $this->effectivePermissions($staffId, $permissions);
        if (!in_array('*', $effective, true)
            && !in_array('ops_inbox.view', $effective, true)
            && !in_array('content_todo.manage', $effective, true)) {
            throw new BusinessException('FORBIDDEN', 'FORBIDDEN');
        }

        $sourceType = $filters['source_type'] ?? null;
        if ($sourceType !== null && !in_array($sourceType, [
            ContentTodo::SOURCE_QUESTION_PENDING,
            ContentTodo::SOURCE_FEEDBACK_PENDING,
        ], true)) {
            throw new BusinessException('VALIDATION_FAILED', 'CONTENT_TODO_SOURCE_INVALID');
        }
        $workflowStatus = $filters['workflow_status'] ?? null;
        if ($workflowStatus !== null && !in_array($workflowStatus, self::WORKFLOW_STATUSES, true)) {
            throw new BusinessException('VALIDATION_FAILED', 'CONTENT_TODO_STATUS_INVALID');
        }

        $page = max(1, (int) ($filters['page'] ?? 1));
        $limit = min(self::MAX_PAGE_LIMIT, max(1, (int) ($filters['limit'] ?? 20)));
        $scope = $this->scope->resolveForCourses($staffId);
        $rows = [];
        foreach ($this->sourceTypes($sourceType) as $type) {
            if (!$this->canViewSource($effective, $type)) {
                continue;
            }
            foreach ($this->sourceRows($staffId, $type, $scope) as $source) {
                $todo = $this->findOrCreate($source);
                if ($workflowStatus !== null && (string) $todo['workflow_status'] !== $workflowStatus) {
                    continue;
                }
                if ($workflowStatus === null && in_array((string) $todo['workflow_status'], [ContentTodo::STATUS_RESOLVED, ContentTodo::STATUS_CLOSED], true)) {
                    continue;
                }
                $rows[] = $this->shapeTodo($todo, $source);
            }
        }

        usort($rows, static function (array $a, array $b): int {
            return [$a['age_seconds'], $a['id']] <=> [$b['age_seconds'], $b['id']];
        });
        return [
            'items' => array_values(array_slice($rows, ($page - 1) * $limit, $limit)),
            'total' => count($rows),
            'page' => $page,
            'limit' => $limit,
        ];
    }

    /**
     * @param list<string> $permissions
     * @return array<string,mixed>
     */
    public function get(int $staffId, int $todoId, array $permissions = []): array
    {
        $this->assertStaff($staffId);
        $effective = $this->effectivePermissions($staffId, $permissions);
        if (!in_array('*', $effective, true)
            && !in_array('ops_inbox.view', $effective, true)
            && !in_array('content_todo.manage', $effective, true)) {
            throw new BusinessException('FORBIDDEN', 'FORBIDDEN');
        }
        $todo = $this->findTodo($todoId);
        $source = $this->sourceForTodo($todo);
        $this->assertSourceAccess($staffId, $effective, $source);

        $candidates = Db::name('content_todo_candidates')
            ->where('content_todo_id', $todoId)
            ->order('version', 'desc')
            ->select()
            ->toArray();
        $audits = Db::name('audit_log')
            ->where('target_type', 'content_todo')
            ->where('target_id', $todoId)
            ->order('id', 'asc')
            ->select()
            ->toArray();

        return [
            ...$this->shapeTodo($todo, $source),
            'source' => $this->shapeSourceDetail($source),
            'candidates' => array_map([$this, 'shapeCandidate'], is_array($candidates) ? $candidates : []),
            'audit' => array_map([$this, 'shapeAudit'], is_array($audits) ? $audits : []),
        ];
    }

    /**
     * Keep legacy question/feedback admin routes inside the content workflow.
     *
     * @param list<string> $permissions 调用者已持有权限码，用于二次收口
     * @return array<string,mixed>
     */
    public function projectSource(
        int $staffId,
        string $sourceType,
        int $sourceKey,
        ?string $firstResponseKind = null,
        array $permissions = [],
    ): array {
        $this->assertStaff($staffId);
        if (!in_array($sourceType, [
            ContentTodo::SOURCE_QUESTION_PENDING,
            ContentTodo::SOURCE_FEEDBACK_PENDING,
        ], true) || $sourceKey <= 0) {
            throw new BusinessException('VALIDATION_FAILED', 'CONTENT_TODO_SOURCE_INVALID');
        }
        $effective = $this->effectivePermissions($staffId, $permissions);
        $source = $this->sourceProjection($sourceType, (string) $sourceKey);
        $required = $sourceType === ContentTodo::SOURCE_QUESTION_PENDING
            ? ['qa.view', 'qa.answer']
            : ['course_feedback.manage'];
        if (!in_array('*', $effective, true) && array_intersect($required, $effective) === []) {
            throw new BusinessException('FORBIDDEN', 'FORBIDDEN');
        }
        $this->assertSourceScope($staffId, $source);

        $todo = $this->findOrCreate($source);
        if ($firstResponseKind !== null && $sourceType === ContentTodo::SOURCE_QUESTION_PENDING) {
            $notificationId = (int) (Db::name('learner_notifications')
                ->where('learner_id', (int) $source['learner_id'])
                ->where('idempotency_key', 'question_message:' . $this->latestQuestionMessageId($sourceKey))
                ->value('id') ?? 0);
            $this->recordFirstResponse($todo, $staffId, $notificationId, $firstResponseKind);
            if ($notificationId > 0 && (string) $todo['workflow_status'] !== ContentTodo::STATUS_AWAITING_APPROVAL) {
                Db::name('content_todos')->where('id', (int) $todo['id'])->update([
                    'result_type' => 'responded_only',
                    'updated_at' => nowDatetime(),
                ]);
            }
        }
        $todo = $this->findTodo((int) $todo['id']);
        return $this->shapeTodo($todo, $source);
    }

    /**
     * @param array<string,mixed> $input
     * @param list<string> $permissions
     * @return array<string,mixed>
     */
    public function triage(int $staffId, int $todoId, array $input, array $permissions = []): array
    {
        $todo = $this->loadForAction($staffId, $todoId, $permissions);
        $label = (string) ($input['label'] ?? '');
        if (!in_array($label, self::LABELS, true)) {
            throw new BusinessException('VALIDATION_FAILED', 'CONTENT_TODO_LABEL_INVALID');
        }
        $expectedVersion = $this->expectedVersion($input);
        $this->assertVersion($todo, $expectedVersion);

        $source = $this->sourceForTodo($todo);
        $targetCourseId = array_key_exists('target_course_id', $input)
            ? $this->nullablePositiveInt($input['target_course_id'], 'CONTENT_TODO_TARGET_INVALID')
            : ($todo['target_course_id'] !== null ? (int) $todo['target_course_id'] : null);
        $targetChapterId = array_key_exists('target_chapter_id', $input)
            ? $this->nullablePositiveInt($input['target_chapter_id'], 'CONTENT_TODO_TARGET_INVALID')
            : ($todo['target_chapter_id'] !== null ? (int) $todo['target_chapter_id'] : null);
        $targetLessonId = array_key_exists('target_lesson_id', $input)
            ? $this->nullablePositiveInt($input['target_lesson_id'], 'CONTENT_TODO_TARGET_INVALID')
            : ($todo['target_lesson_id'] !== null ? (int) $todo['target_lesson_id'] : null);
        $this->assertTarget($staffId, $targetCourseId, $targetChapterId, $targetLessonId);

        $changedTarget = (int) ($todo['target_course_id'] ?? 0) !== (int) ($targetCourseId ?? 0)
            || (int) ($todo['target_chapter_id'] ?? 0) !== (int) ($targetChapterId ?? 0)
            || (int) ($todo['target_lesson_id'] ?? 0) !== (int) ($targetLessonId ?? 0);
        $now = nowDatetime();
        Db::transaction(function () use ($todo, $label, $targetCourseId, $targetChapterId, $targetLessonId, $changedTarget, $staffId, $now): void {
            if ($changedTarget) {
                Db::name('content_todo_candidates')
                    ->where('content_todo_id', (int) $todo['id'])
                    ->whereIn('status', [ContentTodoCandidate::STATUS_DRAFT])
                    ->update(['status' => ContentTodoCandidate::STATUS_SUPERSEDED, 'updated_at' => $now]);
            }
            Db::name('content_todos')->where('id', (int) $todo['id'])->update([
                'label' => $label,
                'target_course_id' => $targetCourseId,
                'target_chapter_id' => $targetChapterId,
                'target_lesson_id' => $targetLessonId,
                'workflow_status' => ContentTodo::STATUS_TRIAGED,
                'version' => (int) $todo['version'] + 1,
                'updated_at' => $now,
            ]);
            $this->writeAudit($staffId, 'content_todo.triaged', (int) $todo['id'], [
                'label' => $label,
                'target_course_id' => $targetCourseId,
                'target_chapter_id' => $targetChapterId,
                'target_lesson_id' => $targetLessonId,
                'target_changed' => $changedTarget,
            ]);
        });
        return $this->get($staffId, (int) $todo['id'], $permissions);
    }

    /**
     * @param array<string,mixed> $input
     * @param list<string> $permissions
     * @return array<string,mixed>
     */
    public function respond(int $staffId, int $todoId, array $input, array $permissions = []): array
    {
        $body = trim((string) ($input['body'] ?? ''));
        if ($body === '' || mb_strlen($body) > 4_000) {
            throw new BusinessException('VALIDATION_FAILED', 'CONTENT_TODO_RESPONSE_INVALID');
        }
        $todo = $this->loadForAction($staffId, $todoId, $permissions);
        $source = $this->sourceForTodo($todo);
        $notificationId = 0;
        $kind = 'private_confirmation';
        if ($source['source_type'] === ContentTodo::SOURCE_QUESTION_PENDING) {
            $effective = $this->effectivePermissions($staffId, $permissions);
            $this->assertPermission($effective, 'qa.answer');
            $question = new QuestionService(new EntitlementService(), $this->scope, $this->messages);
            $question->adminAnswer($staffId, (int) $source['source_key'], $body);
            $notificationId = (int) (Db::name('learner_notifications')
                ->where('learner_id', (int) $source['learner_id'])
                ->where('idempotency_key', 'question_message:' . $this->latestQuestionMessageId((int) $source['source_key']))
                ->value('id') ?? 0);
            $kind = 'public_answer';
        } else {
            $effective = $this->effectivePermissions($staffId, $permissions);
            $this->assertPermission($effective, 'course_feedback.manage');
            $notificationId = $this->messages->emit(
                MessageService::KIND_INTERNAL_MESSAGE,
                (int) $source['learner_id'],
                '课程意见反馈已收到处理',
                '你提交的课程意见反馈已收到处理。',
                ['content_todo_id' => (int) $todo['id'], 'result_type' => 'responded_only'],
                'course',
                (int) $source['source_course_id'],
                'content_todo:' . (int) $todo['id'] . ':response:' . (int) $todo['version'],
            );
            (new CourseFeedbackService($this->scope))->updateStatus(
                $staffId,
                (int) $source['source_course_id'],
                (int) $source['source_key'],
                'processed',
            );
        }
        Db::transaction(function () use ($staffId, $todoId, $permissions, $notificationId, $kind, $source): void {
            $todo = $this->loadForAction($staffId, $todoId, $permissions, true);
            $this->recordFirstResponse($todo, $staffId, $notificationId, $kind);
            $fresh = $this->findTodo((int) $todo['id']);
            if ((string) $fresh['workflow_status'] !== ContentTodo::STATUS_AWAITING_APPROVAL) {
                Db::name('content_todos')->where('id', (int) $todo['id'])->update([
                    'workflow_status' => $fresh['label'] !== null ? ContentTodo::STATUS_TRIAGED : ContentTodo::STATUS_UNTRIAGED,
                    'result_type' => 'responded_only',
                    'updated_at' => nowDatetime(),
                ]);
            }
            $this->writeAudit($staffId, 'content_todo.respond', (int) $todo['id'], [
                'source_type' => $source['source_type'],
                'first_response_kind' => $kind,
            ]);
        });
        return $this->get($staffId, $todoId, $permissions);
    }

    /**
     * @param array<string,mixed> $input
     * @param list<string> $permissions
     * @return array<string,mixed>
     */
    public function generateCandidate(int $staffId, int $todoId, array $input = [], array $permissions = []): array
    {
        $todo = $this->loadForAction($staffId, $todoId, $permissions);
        $this->assertPermission($this->effectivePermissions($staffId, $permissions), 'content_todo.manage');
        if ($todo['label'] === null) {
            throw new BusinessException('VALIDATION_FAILED', 'CONTENT_TODO_LABEL_REQUIRED');
        }
        $this->assertTarget($staffId, (int) ($todo['target_course_id'] ?? 0), $todo['target_chapter_id'] !== null ? (int) $todo['target_chapter_id'] : null, $todo['target_lesson_id'] !== null ? (int) $todo['target_lesson_id'] : null);

        $targetKind = (string) ($input['target_kind'] ?? '');
        if ($targetKind === '') {
            $targetKind = (string) $todo['label'] === ContentTodo::LABEL_RESOURCE_PROBLEM
                ? ContentTodoCandidate::TARGET_HELP_CENTER
                : ($todo['target_lesson_id'] !== null
                    ? ContentTodoCandidate::TARGET_LESSON_MARKDOWN
                    : ContentTodoCandidate::TARGET_COURSE_INTRO);
        }
        if (!in_array($targetKind, [
            ContentTodoCandidate::TARGET_COURSE_INTRO,
            ContentTodoCandidate::TARGET_LESSON_MARKDOWN,
            ContentTodoCandidate::TARGET_HELP_CENTER,
        ], true)) {
            throw new BusinessException('VALIDATION_FAILED', 'CONTENT_TODO_TARGET_KIND_INVALID');
        }
        if (
            (string) $todo['label'] === ContentTodo::LABEL_RESOURCE_PROBLEM
            && $targetKind !== ContentTodoCandidate::TARGET_HELP_CENTER
        ) {
            throw new BusinessException('VALIDATION_FAILED', 'CONTENT_TODO_RESOURCE_PATCH_FORBIDDEN');
        }
        $context = $this->targetContext($todo, $targetKind);
        $bodyFormat = $targetKind === ContentTodoCandidate::TARGET_COURSE_INTRO ? 'html'
            : ($targetKind === ContentTodoCandidate::TARGET_LESSON_MARKDOWN ? 'markdown' : 'plain');
        $body = array_key_exists('body', $input)
            ? (string) $input['body']
            : $this->draftBody((string) $todo['label'], (string) $context['title'], $bodyFormat);
        $body = $this->sanitizeCandidateBody($body, $bodyFormat);
        if (trim(strip_tags($body)) === '') {
            throw new BusinessException('VALIDATION_FAILED', 'CONTENT_TODO_CANDIDATE_EMPTY');
        }

        $nextVersion = (int) (Db::name('content_todo_candidates')
            ->where('content_todo_id', $todoId)
            ->max('version') ?? 0) + 1;
        $now = nowDatetime();
        $candidateId = (int) Db::transaction(function () use ($todo, $nextVersion, $context, $targetKind, $body, $bodyFormat, $staffId, $now): int {
            Db::name('content_todo_candidates')
                ->where('content_todo_id', (int) $todo['id'])
                ->where('status', ContentTodoCandidate::STATUS_DRAFT)
                ->update(['status' => ContentTodoCandidate::STATUS_SUPERSEDED, 'updated_at' => $now]);
            $id = (int) Db::name('content_todo_candidates')->insertGetId([
                'content_todo_id' => (int) $todo['id'],
                'version' => $nextVersion,
                'target_course_id' => $context['target_course_id'],
                'target_chapter_id' => $context['target_chapter_id'],
                'target_lesson_id' => $context['target_lesson_id'],
                'target_kind' => $targetKind,
                'body' => $body,
                'body_format' => $bodyFormat,
                'base_content_fingerprint' => $context['fingerprint'],
                'generator' => 'server_template',
                'status' => ContentTodoCandidate::STATUS_DRAFT,
                'generated_by_staff_id' => $staffId,
                'generated_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            Db::name('content_todos')->where('id', (int) $todo['id'])->update([
                'workflow_status' => ContentTodo::STATUS_AWAITING_APPROVAL,
                'version' => (int) $todo['version'] + 1,
                'updated_at' => $now,
            ]);
            $this->writeAudit($staffId, 'content_todo.candidate_generated', (int) $todo['id'], [
                'candidate_id' => $id,
                'candidate_version' => $nextVersion,
                'target_kind' => $targetKind,
                'base_content_fingerprint' => $context['fingerprint'],
            ]);
            return $id;
        });
        return $this->get($staffId, $todoId, $permissions);
    }

    /**
     * @param array<string,mixed> $input
     * @param list<string> $permissions
     * @return array<string,mixed>
     */
    public function editCandidate(int $staffId, int $todoId, int $candidateId, array $input, array $permissions = []): array
    {
        Db::transaction(function () use ($staffId, $todoId, $candidateId, $input, $permissions): void {
            $this->loadForAction($staffId, $todoId, $permissions, true);
            $candidate = $this->findCandidate($todoId, $candidateId);
            if ((string) $candidate['status'] !== ContentTodoCandidate::STATUS_DRAFT) {
                throw new BusinessException('CONFLICT', 'CONTENT_TODO_CANDIDATE_NOT_EDITABLE');
            }
            $body = $this->sanitizeCandidateBody((string) ($input['body'] ?? ''), (string) $candidate['body_format']);
            if (trim(strip_tags($body)) === '') {
                throw new BusinessException('VALIDATION_FAILED', 'CONTENT_TODO_CANDIDATE_EMPTY');
            }
            Db::name('content_todo_candidates')->where('id', $candidateId)->update([
                'body' => $body,
                'updated_at' => nowDatetime(),
            ]);
            $this->writeAudit($staffId, 'content_todo.candidate_edited', $todoId, ['candidate_id' => $candidateId]);
        });
        return $this->get($staffId, $todoId, $permissions);
    }

    /**
     * @param list<string> $permissions
     * @return array<string,mixed>
     */
    public function rejectCandidate(int $staffId, int $todoId, int $candidateId, string $reason, array $permissions = []): array
    {
        $reason = trim($reason);
        if ($reason === '' || mb_strlen($reason) > 500) {
            throw new BusinessException('VALIDATION_FAILED', 'CONTENT_TODO_REJECTION_REASON_REQUIRED');
        }
        Db::transaction(function () use ($staffId, $todoId, $candidateId, $reason, $permissions): void {
            $todo = $this->loadForAction($staffId, $todoId, $permissions, true);
            $candidate = $this->findCandidate($todoId, $candidateId);
            if ((string) $candidate['status'] !== ContentTodoCandidate::STATUS_DRAFT) {
                throw new BusinessException('CONFLICT', 'CONTENT_TODO_CANDIDATE_NOT_REJECTABLE');
            }
            $now = nowDatetime();
            Db::name('content_todo_candidates')->where('id', $candidateId)->update([
                'status' => ContentTodoCandidate::STATUS_REJECTED,
                'rejected_by_staff_id' => $staffId,
                'rejected_at' => $now,
                'rejection_reason' => $reason,
                'updated_at' => $now,
            ]);
            Db::name('content_todos')->where('id', $todoId)->update([
                'workflow_status' => ContentTodo::STATUS_TRIAGED,
                'version' => (int) $todo['version'] + 1,
                'updated_at' => $now,
            ]);
            $this->writeAudit($staffId, 'content_todo.candidate_rejected', $todoId, ['candidate_id' => $candidateId]);
        });
        return $this->get($staffId, $todoId, $permissions);
    }

    /**
     * @param array<string,mixed> $input
     * @param list<string> $permissions
     * @return array<string,mixed>
     */
    public function approveCandidate(int $staffId, int $todoId, int $candidateId, array $input = [], array $permissions = []): array
    {
        $todo = $this->loadForAction($staffId, $todoId, $permissions);
        $effective = $this->effectivePermissions($staffId, $permissions);
        $candidate = $this->findCandidate($todoId, $candidateId);
        if ((string) $candidate['target_kind'] !== ContentTodoCandidate::TARGET_HELP_CENTER) {
            $this->assertPermission($effective, 'course.manage');
        }
        if ((string) $candidate['status'] !== ContentTodoCandidate::STATUS_DRAFT) {
            throw new BusinessException('CONFLICT', 'CONTENT_TODO_CANDIDATE_NOT_APPROVABLE');
        }
        $expectedTodoVersion = $this->expectedVersion($input);
        $this->assertVersion($todo, $expectedTodoVersion);
        $notifyMode = (string) ($input['notify_mode'] ?? 'submitter');
        if (!in_array($notifyMode, self::NOTIFY_MODES, true)) {
            throw new BusinessException('VALIDATION_FAILED', 'CONTENT_TODO_NOTIFY_MODE_INVALID');
        }
        $context = $this->targetContext($todo, (string) $candidate['target_kind']);
        $this->assertTarget(
            $staffId,
            $context['target_course_id'] !== null ? (int) $context['target_course_id'] : null,
            $context['target_chapter_id'] !== null ? (int) $context['target_chapter_id'] : null,
            $context['target_lesson_id'] !== null ? (int) $context['target_lesson_id'] : null,
        );
        if (
            ($candidate['target_course_id'] !== null ? (int) $candidate['target_course_id'] : null) !== $context['target_course_id']
            || ($candidate['target_chapter_id'] !== null ? (int) $candidate['target_chapter_id'] : null) !== $context['target_chapter_id']
            || ($candidate['target_lesson_id'] !== null ? (int) $candidate['target_lesson_id'] : null) !== $context['target_lesson_id']
        ) {
            throw new BusinessException('CONFLICT', 'CONTENT_TODO_TARGET_CHANGED');
        }
        if ((string) $context['fingerprint'] !== (string) $candidate['base_content_fingerprint']) {
            throw new BusinessException('CONFLICT', 'CONTENT_TODO_CONTENT_CHANGED');
        }
        $source = $this->sourceForTodo($todo);
        $now = nowDatetime();
        Db::transaction(function () use ($todo, $candidate, $context, $staffId, $now, $todoId, $permissions): void {
            $this->loadForAction($staffId, $todoId, $permissions, true);
            if ((string) $candidate['target_kind'] === ContentTodoCandidate::TARGET_COURSE_INTRO) {
                $this->courses->updateCourse(
                    (int) $context['target_course_id'],
                    ['intro_rich_text' => (string) $candidate['body']],
                    $staffId,
                );
            } elseif ((string) $candidate['target_kind'] === ContentTodoCandidate::TARGET_LESSON_MARKDOWN) {
                $this->courses->updateLesson(
                    (int) $context['target_lesson_id'],
                    [
                        'body_markdown' => (string) $candidate['body'],
                        'content_type' => 'markdown',
                    ],
                    $staffId,
                    (int) $context['target_course_id'],
                );
            }
            Db::name('content_todo_candidates')->where('id', (int) $candidate['id'])->update([
                'status' => ContentTodoCandidate::STATUS_APPROVED,
                'approved_by_staff_id' => $staffId,
                'approved_at' => $now,
                'updated_at' => $now,
            ]);
            $resultType = (string) $candidate['target_kind'] === ContentTodoCandidate::TARGET_HELP_CENTER
                ? 'help_center_candidate'
                : 'content_updated';
            Db::name('content_todos')->where('id', (int) $todo['id'])->update([
                'workflow_status' => ContentTodo::STATUS_RESOLVED,
                'result_type' => $resultType,
                'resolved_by_staff_id' => $staffId,
                'resolved_at' => $now,
                'version' => (int) $todo['version'] + 1,
                'updated_at' => $now,
            ]);
            $this->writeAudit($staffId, 'content_todo.candidate_approved', (int) $todo['id'], [
                'candidate_id' => (int) $candidate['id'],
                'candidate_version' => (int) $candidate['version'],
                'target_kind' => (string) $candidate['target_kind'],
                'content_fingerprint' => $context['fingerprint'],
            ]);
        });

        if ($notifyMode !== 'none') {
            $this->notifyResult($staffId, $todo, $source, $candidate, $notifyMode);
        }
        return $this->get($staffId, $todoId, $permissions);
    }

    /**
     * @param array<string,mixed> $input
     * @param list<string> $permissions
     * @return array<string,mixed>
     */
    public function close(int $staffId, int $todoId, array $input, array $permissions = []): array
    {
        $reason = (string) ($input['close_reason_code'] ?? '');
        if (!in_array($reason, self::CLOSE_REASONS, true)) {
            throw new BusinessException('VALIDATION_FAILED', 'CONTENT_TODO_CLOSE_REASON_INVALID');
        }
        $note = trim((string) ($input['close_reason_note'] ?? ''));
        if ($note === '' || mb_strlen($note) > 500) {
            throw new BusinessException('VALIDATION_FAILED', 'CONTENT_TODO_CLOSE_NOTE_REQUIRED');
        }
        Db::transaction(function () use ($staffId, $todoId, $input, $permissions, $reason, $note): void {
            $todo = $this->loadForAction($staffId, $todoId, $permissions, true);
            $this->assertVersion($todo, $this->expectedVersion($input));
            $this->assertTarget(
                $staffId,
                $todo['target_course_id'] !== null ? (int) $todo['target_course_id'] : null,
                $todo['target_chapter_id'] !== null ? (int) $todo['target_chapter_id'] : null,
                $todo['target_lesson_id'] !== null ? (int) $todo['target_lesson_id'] : null,
            );
            $now = nowDatetime();
            $source = $this->sourceForTodo($todo);
            if ($source['source_type'] === ContentTodo::SOURCE_QUESTION_PENDING) {
                $status = (string) (Db::name('questions')->where('id', (int) $source['source_key'])->value('status') ?? '');
                if ($status === 'pending') {
                    Db::name('questions')->where('id', (int) $source['source_key'])->update([
                        'status' => 'closed',
                        'updated_at' => $now,
                    ]);
                }
            } else {
                $status = (string) (Db::name('course_feedbacks')->where('id', (int) $source['source_key'])->value('status') ?? '');
                if ($status === 'pending') {
                    Db::name('course_feedbacks')->where('id', (int) $source['source_key'])->update([
                        'status' => 'processed',
                        'processed_by_staff_id' => $staffId,
                        'processed_at' => $now,
                        'updated_at' => $now,
                    ]);
                }
            }
            Db::name('content_todos')->where('id', (int) $todo['id'])->update([
                'workflow_status' => ContentTodo::STATUS_CLOSED,
                'result_type' => 'closed_no_change',
                'close_reason_code' => $reason,
                'close_reason_note' => $note,
                'resolved_by_staff_id' => $staffId,
                'resolved_at' => $now,
                'version' => (int) $todo['version'] + 1,
                'updated_at' => $now,
            ]);
            $this->writeAudit($staffId, 'content_todo.closed', (int) $todo['id'], [
                'close_reason_code' => $reason,
                'close_reason_note' => $note,
            ]);
        });
        return $this->get($staffId, $todoId, $permissions);
    }

    /**
     * @param list<string> $permissions
     * @return array<string,mixed>
     */
    private function loadForAction(int $staffId, int $todoId, array $permissions, bool $lock = false): array
    {
        $this->assertStaff($staffId);
        $effective = $this->effectivePermissions($staffId, $permissions);
        $this->assertPermission($effective, 'content_todo.manage');
        $query = Db::name('content_todos')->where('id', $todoId);
        if ($lock) {
            $query->lock(true);
        }
        $todo = $query->find();
        if (!is_array($todo)) {
            throw new BusinessException('NOT_FOUND', 'CONTENT_TODO_NOT_FOUND');
        }
        $source = $this->sourceForTodo($todo);
        $this->assertSourceAccess($staffId, $effective, $source);
        return $todo;
    }

    /** @return array<string,mixed> */
    private function findTodo(int $todoId): array
    {
        $row = Db::name('content_todos')->where('id', $todoId)->find();
        if (!is_array($row)) {
            throw new BusinessException('NOT_FOUND', 'CONTENT_TODO_NOT_FOUND');
        }
        return $row;
    }

    /** @return array<string,mixed> */
    private function findCandidate(int $todoId, int $candidateId): array
    {
        $row = Db::name('content_todo_candidates')
            ->where('id', $candidateId)
            ->where('content_todo_id', $todoId)
            ->find();
        if (!is_array($row)) {
            throw new BusinessException('NOT_FOUND', 'CONTENT_TODO_CANDIDATE_NOT_FOUND');
        }
        return $row;
    }

    /**
     * @param array<string,mixed> $source
     * @return array<string,mixed>
     */
    private function findOrCreate(array $source): array
    {
        $existing = Db::name('content_todos')
            ->where('source_type', $source['source_type'])
            ->where('source_key', $source['source_key'])
            ->find();
        if (is_array($existing)) {
            return $existing;
        }
        $now = nowDatetime();
        try {
            Db::name('content_todos')->insert([
                'source_type' => $source['source_type'],
                'source_key' => $source['source_key'],
                'source_course_id' => $source['source_course_id'],
                'target_course_id' => $source['target_course_id'],
                'target_chapter_id' => $source['target_chapter_id'],
                'target_lesson_id' => $source['target_lesson_id'],
                'workflow_status' => ContentTodo::STATUS_UNTRIAGED,
                'version' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        } catch (\Throwable $exception) {
            if (!$this->isDuplicateKey($exception)) {
                throw $exception;
            }
            // The composite unique key arbitrates concurrent lazy projection.
        }
        return $this->findTodoBySource((string) $source['source_type'], (string) $source['source_key']);
    }

    /** @return array<string,mixed> */
    private function findTodoBySource(string $sourceType, string $sourceKey): array
    {
        $row = Db::name('content_todos')
            ->where('source_type', $sourceType)
            ->where('source_key', $sourceKey)
            ->find();
        if (!is_array($row)) {
            throw new \RuntimeException('CONTENT_TODO_PROJECTION_FAILED');
        }
        return $row;
    }

    /**
     * @param array{all:bool,include_self:bool,department_ids:list<int>,scope:string} $scope
     * @return list<array<string,mixed>>
     */
    private function sourceRows(int $staffId, string $sourceType, array $scope): array
    {
        if ($sourceType === ContentTodo::SOURCE_QUESTION_PENDING) {
            $query = Db::name('questions')->alias('q')
                ->join('courses c', 'c.id = q.course_id')
                ->leftJoin('content_todos ct', "ct.source_type = '" . ContentTodo::SOURCE_QUESTION_PENDING . "' AND ct.source_key = q.id")
                ->where(function (\think\db\Query $where): void {
                    $where->where(function (\think\db\Query $pending): void {
                            $pending->where('q.status', 'pending')->whereNull('ct.id');
                        })
                        ->whereOr(function (\think\db\Query $existing): void {
                            $existing->whereNotNull('ct.id')
                                ->whereNotIn('ct.workflow_status', [ContentTodo::STATUS_RESOLVED, ContentTodo::STATUS_CLOSED]);
                        });
                })
                ->field('q.id AS source_key,q.course_id AS source_course_id,q.chapter_id,q.lesson_id,q.learner_id,q.title,q.created_at,q.answered_at,c.title AS course_title,c.department_id,c.created_by_staff_id');
            $this->applyScope($query, $scope, $staffId, 'c.department_id', 'c.created_by_staff_id');
            $rows = $query->order('q.created_at', 'asc')->order('q.id', 'asc')->select()->toArray();
            return array_map(static fn (array $row): array => [
                'source_type' => ContentTodo::SOURCE_QUESTION_PENDING,
                'source_key' => (string) $row['source_key'],
                'source_course_id' => (int) $row['source_course_id'],
                'target_course_id' => (int) $row['source_course_id'],
                'target_chapter_id' => $row['chapter_id'] !== null ? (int) $row['chapter_id'] : null,
                'target_lesson_id' => $row['lesson_id'] !== null ? (int) $row['lesson_id'] : null,
                'learner_id' => (int) $row['learner_id'],
                'title' => (string) ($row['title'] ?? '学员提问'),
                'course_title' => (string) ($row['course_title'] ?? ''),
                'created_at' => (string) $row['created_at'],
                'answered_at' => $row['answered_at'] !== null && $row['answered_at'] !== '' ? (string) $row['answered_at'] : null,
                'department_id' => (int) $row['department_id'],
                'creator_id' => (int) $row['created_by_staff_id'],
            ], is_array($rows) ? $rows : []);
        }
        $query = Db::name('course_feedbacks')->alias('f')
            ->join('courses c', 'c.id = f.course_id')
            ->leftJoin('content_todos ct', "ct.source_type = '" . ContentTodo::SOURCE_FEEDBACK_PENDING . "' AND ct.source_key = f.id")
            ->where(function (\think\db\Query $where): void {
                $where->where(function (\think\db\Query $pending): void {
                        $pending->where('f.status', 'pending')->whereNull('ct.id');
                    })
                    ->whereOr(function (\think\db\Query $existing): void {
                        $existing->whereNotNull('ct.id')
                            ->whereNotIn('ct.workflow_status', [ContentTodo::STATUS_RESOLVED, ContentTodo::STATUS_CLOSED]);
                    });
            })
            ->field('f.id AS source_key,f.course_id AS source_course_id,f.learner_id,f.created_at,c.title AS course_title,c.department_id,c.created_by_staff_id');
        $this->applyScope($query, $scope, $staffId, 'c.department_id', 'c.created_by_staff_id');
        $rows = $query->order('f.created_at', 'asc')->order('f.id', 'asc')->select()->toArray();
        return array_map(static fn (array $row): array => [
            'source_type' => ContentTodo::SOURCE_FEEDBACK_PENDING,
            'source_key' => (string) $row['source_key'],
            'source_course_id' => (int) $row['source_course_id'],
            'target_course_id' => (int) $row['source_course_id'],
            'target_chapter_id' => null,
            'target_lesson_id' => null,
            'learner_id' => (int) $row['learner_id'],
            'title' => '课程反馈',
            'course_title' => (string) ($row['course_title'] ?? ''),
            'created_at' => (string) $row['created_at'],
            'department_id' => (int) $row['department_id'],
            'creator_id' => (int) $row['created_by_staff_id'],
        ], is_array($rows) ? $rows : []);
    }

    /**
     * @param array<string,mixed> $todo
     * @return array<string,mixed>
     */
    private function sourceForTodo(array $todo): array
    {
        $type = (string) $todo['source_type'];
        $key = (string) $todo['source_key'];
        $row = $this->sourceProjection($type, $key);
        return [
            'source_type' => $type,
            'source_key' => $key,
            'source_course_id' => (int) $row['source_course_id'],
            'target_course_id' => $todo['target_course_id'] !== null ? (int) $todo['target_course_id'] : null,
            'target_chapter_id' => $todo['target_chapter_id'] !== null ? (int) $todo['target_chapter_id'] : null,
            'target_lesson_id' => $todo['target_lesson_id'] !== null ? (int) $todo['target_lesson_id'] : null,
            'learner_id' => (int) $row['learner_id'],
            'title' => (string) ($row['title'] ?? '课程反馈'),
            'course_title' => (string) ($row['course_title'] ?? ''),
            'body' => (string) ($row['body'] ?? ''),
            'created_at' => (string) $row['created_at'],
            'answered_at' => $row['answered_at'] ?? null,
            'department_id' => (int) $row['department_id'],
            'creator_id' => (int) $row['creator_id'],
        ];
    }

    /** @return array<string,mixed> */
    private function sourceProjection(string $type, string $key): array
    {
        $row = $type === ContentTodo::SOURCE_QUESTION_PENDING
            ? Db::name('questions')->alias('q')
                ->join('courses c', 'c.id = q.course_id')
                ->where('q.id', (int) $key)
                ->field('q.id AS source_key,q.course_id AS source_course_id,q.chapter_id,q.lesson_id,q.learner_id,q.title,q.body,q.created_at,q.answered_at,c.title AS course_title,c.department_id,c.created_by_staff_id')
                ->find()
            : Db::name('course_feedbacks')->alias('f')
                ->join('courses c', 'c.id = f.course_id')
                ->where('f.id', (int) $key)
                ->field('f.id AS source_key,f.course_id AS source_course_id,f.learner_id,f.body_html AS body,f.created_at,c.title AS course_title,c.department_id,c.created_by_staff_id')
                ->find();
        if (!is_array($row)) {
            throw new BusinessException('NOT_FOUND', 'CONTENT_TODO_SOURCE_NOT_FOUND');
        }
        $chapterId = $row['chapter_id'] ?? null;
        $lessonId = $row['lesson_id'] ?? null;
        return [
            'source_type' => $type,
            'source_key' => (string) $row['source_key'],
            'source_course_id' => (int) $row['source_course_id'],
            'target_course_id' => (int) $row['source_course_id'],
            'target_chapter_id' => $chapterId !== null ? (int) $chapterId : null,
            'target_lesson_id' => $lessonId !== null ? (int) $lessonId : null,
            'learner_id' => (int) $row['learner_id'],
            'title' => (string) ($row['title'] ?? '课程反馈'),
            'course_title' => (string) ($row['course_title'] ?? ''),
            'body' => (string) ($row['body'] ?? ''),
            'created_at' => (string) $row['created_at'],
            'answered_at' => isset($row['answered_at']) && $row['answered_at'] !== null && $row['answered_at'] !== ''
                ? (string) $row['answered_at']
                : null,
            'department_id' => (int) $row['department_id'],
            'creator_id' => (int) $row['created_by_staff_id'],
        ];
    }

    /** @param array<string,mixed> $source */
    private function assertSourceScope(int $staffId, array $source): void
    {
        DataScopeService::assertCourseAccessibleFromScope(
            $this->scope->resolveForCourses($staffId),
            (int) $source['department_id'],
            (int) $source['creator_id'],
            $staffId,
        );
    }

    /**
     * @param list<string> $permissions
     * @param array<string,mixed> $source
     */
    private function assertSourceAccess(int $staffId, array $permissions, array $source): void
    {
        $required = $source['source_type'] === ContentTodo::SOURCE_QUESTION_PENDING ? 'qa.view' : 'course_feedback.manage';
        $this->assertPermission($permissions, $required);
        $this->assertSourceScope($staffId, $source);
    }

    /** @param list<string> $permissions */
    private function canViewSource(array $permissions, string $sourceType): bool
    {
        return in_array('*', $permissions, true)
            || ($sourceType === ContentTodo::SOURCE_QUESTION_PENDING && in_array('qa.view', $permissions, true))
            || ($sourceType === ContentTodo::SOURCE_FEEDBACK_PENDING && in_array('course_feedback.manage', $permissions, true));
    }

    /**
     * @param array<string,mixed> $todo
     * @param array<string,mixed> $source
     * @return array<string,mixed>
     */
    private function shapeTodo(array $todo, array $source): array
    {
        $created = strtotime((string) $source['created_at']) ?: time();
        $age = max(0, time() - $created);
        return [
            'id' => (int) $todo['id'],
            'source_type' => (string) $todo['source_type'],
            'source_key' => (string) $todo['source_key'],
            'source_course_id' => (int) $source['source_course_id'],
            'workflow_status' => (string) $todo['workflow_status'],
            'label' => $todo['label'] !== null ? (string) $todo['label'] : null,
            'target' => [
                'course_id' => $todo['target_course_id'] !== null ? (int) $todo['target_course_id'] : null,
                'chapter_id' => $todo['target_chapter_id'] !== null ? (int) $todo['target_chapter_id'] : null,
                'lesson_id' => $todo['target_lesson_id'] !== null ? (int) $todo['target_lesson_id'] : null,
            ],
            'first_response_at' => $todo['first_response_at'] !== null
                ? (string) $todo['first_response_at']
                : (isset($source['answered_at']) && is_string($source['answered_at']) ? $source['answered_at'] : null),
            'first_response_kind' => $todo['first_response_kind'] !== null
                ? (string) $todo['first_response_kind']
                : (isset($source['answered_at']) && is_string($source['answered_at']) ? 'public_answer' : null),
            'first_response_confirmed' => $todo['first_response_at'] !== null
                || (isset($source['answered_at']) && is_string($source['answered_at'])),
            'result_type' => $todo['result_type'] !== null ? (string) $todo['result_type'] : null,
            'close_reason_code' => $todo['close_reason_code'] !== null ? (string) $todo['close_reason_code'] : null,
            'close_reason_note' => $todo['close_reason_note'] !== null ? (string) $todo['close_reason_note'] : null,
            'resolved_at' => $todo['resolved_at'] !== null ? (string) $todo['resolved_at'] : null,
            'resolved_by_staff_id' => $todo['resolved_by_staff_id'] !== null ? (int) $todo['resolved_by_staff_id'] : null,
            'version' => (int) $todo['version'],
            'title' => (string) $source['title'],
            'course_title' => (string) $source['course_title'],
            'age_seconds' => $age,
            'age_label' => $this->ageLabel($age),
            'created_at' => (string) $source['created_at'],
            'updated_at' => (string) $todo['updated_at'],
        ];
    }

    /**
     * @param array<string,mixed> $source
     * @return array<string,mixed>
     */
    private function shapeSourceDetail(array $source): array
    {
        return [
            'source_type' => $source['source_type'],
            'source_key' => $source['source_key'],
            'course_id' => (int) $source['source_course_id'],
            'course_title' => $source['course_title'],
            'chapter_id' => $source['target_chapter_id'],
            'lesson_id' => $source['target_lesson_id'],
            'learner_id' => (int) $source['learner_id'],
            'title' => $source['title'],
            // Private feedback is only returned from this scoped admin detail.
            'body' => $source['body'],
            'created_at' => $source['created_at'],
        ];
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function shapeCandidate(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'version' => (int) $row['version'],
            'target_course_id' => $row['target_course_id'] !== null ? (int) $row['target_course_id'] : null,
            'target_chapter_id' => $row['target_chapter_id'] !== null ? (int) $row['target_chapter_id'] : null,
            'target_lesson_id' => $row['target_lesson_id'] !== null ? (int) $row['target_lesson_id'] : null,
            'target_kind' => (string) $row['target_kind'],
            'body' => (string) $row['body'],
            'body_format' => (string) $row['body_format'],
            'base_content_fingerprint' => (string) $row['base_content_fingerprint'],
            'generator' => (string) $row['generator'],
            'status' => (string) $row['status'],
            'generated_by_staff_id' => $row['generated_by_staff_id'] !== null ? (int) $row['generated_by_staff_id'] : null,
            'generated_at' => (string) $row['generated_at'],
            'approved_by_staff_id' => $row['approved_by_staff_id'] !== null ? (int) $row['approved_by_staff_id'] : null,
            'approved_at' => $row['approved_at'] !== null ? (string) $row['approved_at'] : null,
            'rejection_reason' => $row['rejection_reason'] !== null ? (string) $row['rejection_reason'] : null,
        ];
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function shapeAudit(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'action' => (string) $row['action'],
            'actor_id' => $row['actor_id'] !== null ? (int) $row['actor_id'] : null,
            'payload' => $row['payload_json'] !== null ? json_decode((string) $row['payload_json'], true) : null,
            'created_at' => (string) $row['created_at'],
        ];
    }

    /**
     * @param array<string,mixed> $todo
     * @return array<string,mixed>
     */
    private function targetContext(array $todo, string $targetKind): array
    {
        $courseId = (int) ($todo['target_course_id'] ?? 0);
        $chapterId = $todo['target_chapter_id'] !== null ? (int) $todo['target_chapter_id'] : null;
        $lessonId = $todo['target_lesson_id'] !== null ? (int) $todo['target_lesson_id'] : null;
        if ($targetKind === ContentTodoCandidate::TARGET_COURSE_INTRO) {
            $row = Db::name('courses')->where('id', $courseId)->field('id,title,intro_rich_text')->find();
            if (!is_array($row)) {
                throw new BusinessException('NOT_FOUND', 'COURSE_NOT_FOUND');
            }
            return [
                'title' => (string) $row['title'],
                'target_course_id' => $courseId,
                'target_chapter_id' => null,
                'target_lesson_id' => null,
                'fingerprint' => hash('sha256', (string) ($row['intro_rich_text'] ?? '')),
            ];
        }
        if ($targetKind === ContentTodoCandidate::TARGET_LESSON_MARKDOWN) {
            $row = Db::name('lessons')->alias('l')
                ->join('chapters ch', 'ch.id = l.chapter_id')
                ->where('l.id', $lessonId)
                ->where('ch.course_id', $courseId)
                ->field('l.id,l.title,l.content_type,l.body_markdown,ch.id AS chapter_id')
                ->find();
            if (!is_array($row) || (string) $row['content_type'] !== 'markdown') {
                throw new BusinessException('VALIDATION_FAILED', 'CONTENT_TODO_MARKDOWN_TARGET_REQUIRED');
            }
            return [
                'title' => (string) $row['title'],
                'target_course_id' => $courseId,
                'target_chapter_id' => (int) $row['chapter_id'],
                'target_lesson_id' => (int) $row['id'],
                'fingerprint' => hash('sha256', (string) ($row['body_markdown'] ?? '')),
            ];
        }
        return [
            'title' => '帮助中心候选',
            'target_course_id' => $courseId > 0 ? $courseId : null,
            'target_chapter_id' => $chapterId,
            'target_lesson_id' => $lessonId,
            'fingerprint' => hash('sha256', $courseId . ':' . ($chapterId ?? 0) . ':' . ($lessonId ?? 0)),
        ];
    }

    private function draftBody(string $label, string $title, string $format): string
    {
        $copy = match ($label) {
            ContentTodo::LABEL_ERROR => '请修正“' . $title . '”中的错误，并补充一段准确说明。',
            ContentTodo::LABEL_MISSING_EXAMPLE => '补充一个与“' . $title . '”对应的具体例子，说明输入、步骤和结果。',
            ContentTodo::LABEL_RESOURCE_PROBLEM => '补充“' . $title . '”的资源说明、适用场景和替代阅读路径。',
            default => '补充“' . $title . '”的说明，明确概念、步骤和学习结果。',
        };
        return $format === 'html' ? '<p>' . htmlspecialchars($copy, ENT_QUOTES | ENT_HTML5, 'UTF-8') . '</p>' : $copy;
    }

    private function sanitizeCandidateBody(string $body, string $format): string
    {
        if (mb_strlen($body) > self::MAX_BODY_LENGTH) {
            throw new BusinessException('VALIDATION_FAILED', 'CONTENT_TODO_CANDIDATE_TOO_LONG');
        }
        $body = preg_replace('/\]\(\s*(?:javascript|data|vbscript):[^)]*\)/i', '](#)', $body) ?? $body;
        $body = preg_replace('/(?:javascript|data|vbscript):/i', '', $body) ?? $body;
        if ($format === 'html' || $body !== strip_tags($body)) {
            $sanitized = HtmlSanitizer::sanitize($body)['html'];
            $body = $format === 'html'
                ? $sanitized
                : html_entity_decode(strip_tags($sanitized), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
        return trim($body);
    }

    private function assertTarget(int $staffId, ?int $courseId, ?int $chapterId, ?int $lessonId): void
    {
        if ($courseId === null || $courseId <= 0) {
            throw new BusinessException('VALIDATION_FAILED', 'CONTENT_TODO_TARGET_REQUIRED');
        }
        $course = Db::name('courses')->where('id', $courseId)->field('id,department_id,created_by_staff_id')->find();
        if (!is_array($course)) {
            throw new BusinessException('NOT_FOUND', 'COURSE_NOT_FOUND');
        }
        DataScopeService::assertCourseAccessibleFromScope(
            $this->scope->resolveForCourses($staffId),
            (int) $course['department_id'],
            (int) $course['created_by_staff_id'],
            $staffId,
        );
        if ($chapterId !== null) {
            $chapter = Db::name('chapters')->where('id', $chapterId)->where('course_id', $courseId)->find();
            if (!is_array($chapter)) {
                throw new BusinessException('VALIDATION_FAILED', 'CONTENT_TODO_CHAPTER_MISMATCH');
            }
        }
        if ($lessonId !== null) {
            $lesson = Db::name('lessons')->alias('l')->join('chapters ch', 'ch.id = l.chapter_id')->where('l.id', $lessonId)->where('ch.course_id', $courseId)->find();
            if (!is_array($lesson) || ($chapterId !== null && (int) $lesson['chapter_id'] !== $chapterId)) {
                throw new BusinessException('VALIDATION_FAILED', 'CONTENT_TODO_LESSON_MISMATCH');
            }
        }
    }

    /** @param array<string,mixed> $todo */
    private function recordFirstResponse(array $todo, int $staffId, int $notificationId, string $kind): void
    {
        if ($notificationId <= 0 || (string) ($todo['first_response_at'] ?? '') !== '') {
            return;
        }
        $now = nowDatetime();
        if ($kind === 'public_answer') {
            $answeredAt = Db::name('questions')->where('id', (int) $todo['source_key'])->value('answered_at');
            if (is_string($answeredAt) && $answeredAt !== '') {
                $now = $answeredAt;
            }
        }
        Db::name('content_todos')->where('id', (int) $todo['id'])->update([
            'first_response_at' => $now,
            'first_response_kind' => $kind,
            'first_response_notification_id' => $notificationId,
            'updated_at' => $now,
        ]);
        $this->writeAudit($staffId, 'content_todo.first_response', (int) $todo['id'], [
            'kind' => $kind,
            'notification_id' => $notificationId,
        ]);
    }

    /**
     * @param array<string,mixed> $todo
     * @param array<string,mixed> $source
     * @param array<string,mixed> $candidate
     */
    private function notifyResult(int $staffId, array $todo, array $source, array $candidate, string $mode): void
    {
        $courseId = (int) $candidate['target_course_id'];
        $isHelpCenter = (string) $candidate['target_kind'] === ContentTodoCandidate::TARGET_HELP_CENTER;
        $title = $isHelpCenter ? '内容待办已处理' : '课程内容已更新';
        $body = '你提交的问题或课程意见反馈已完成处理。';
        $submitterId = (int) $source['learner_id'];
        if (in_array($mode, ['submitter', 'both'], true) && $submitterId > 0) {
            try {
                $this->messages->emit(
                    MessageService::KIND_INTERNAL_MESSAGE,
                    $submitterId,
                    $title,
                    $body,
                    ['content_todo_id' => (int) $todo['id'], 'result_type' => $isHelpCenter ? 'help_center_candidate' : 'content_updated'],
                    'course',
                    $courseId,
                    'content_todo:' . (int) $todo['id'] . ':' . (int) $candidate['version'] . ':' . $submitterId,
                );
            } catch (\Throwable $e) {
                Logger::warning('content_todo.notification_failed', [
                    'content_todo_id' => (int) $todo['id'],
                    'learner_id' => $submitterId,
                    'err' => $e->getMessage(),
                ]);
                $this->writeAudit($staffId, 'content_todo.notification_failed', (int) $todo['id'], [
                    'learner_id' => $submitterId,
                ]);
            }
        }
        if (!in_array($mode, ['enrolled', 'both'], true) || $courseId <= 0) {
            return;
        }
        $rows = Db::name('course_entitlements')
            ->where('course_id', $courseId)
            ->where('status', 'active')
            ->column('learner_id');
        $recipientIds = array_values(array_unique(array_filter(
            array_map('intval', is_array($rows) ? $rows : []),
            static fn (int $id): bool => $id > 0 && ($mode !== 'both' || $id !== $submitterId),
        )));
        if ($recipientIds === []) {
            return;
        }
        try {
            $this->dispatches->enqueueInternalNotice(
                $staffId,
                $title,
                $body,
                $recipientIds,
                'course',
                $courseId,
            );
            $this->writeAudit($staffId, 'content_todo.notification_enqueued', (int) $todo['id'], [
                'notify_mode' => $mode,
                'recipient_count' => count($recipientIds),
            ]);
        } catch (\Throwable $e) {
            Logger::warning('content_todo.notification_failed', [
                'content_todo_id' => (int) $todo['id'],
                'notify_mode' => $mode,
                'err' => $e->getMessage(),
            ]);
            $this->writeAudit($staffId, 'content_todo.notification_failed', (int) $todo['id'], [
                'notify_mode' => $mode,
            ]);
        }
    }

    private function latestQuestionMessageId(int $questionId): int
    {
        return (int) (Db::name('question_messages')->where('question_id', $questionId)->where('kind', 'admin')->order('id', 'desc')->value('id') ?? 0);
    }

    /** @param array<string,mixed> $todo */
    private function assertVersion(array $todo, ?int $expected): void
    {
        if ($expected !== null && (int) $todo['version'] !== $expected) {
            throw new BusinessException('CONFLICT', 'CONTENT_TODO_VERSION_CONFLICT');
        }
    }

    /** @param array<string,mixed> $input */
    private function expectedVersion(array $input): ?int
    {
        if (!array_key_exists('expected_version', $input) || $input['expected_version'] === null || $input['expected_version'] === '') {
            return null;
        }
        if (!is_int($input['expected_version']) && !(is_string($input['expected_version']) && ctype_digit($input['expected_version']))) {
            throw new BusinessException('VALIDATION_FAILED', 'CONTENT_TODO_VERSION_INVALID');
        }
        return (int) $input['expected_version'];
    }

    private function nullablePositiveInt(mixed $value, string $error): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        if ((!is_int($value) && !is_string($value)) || (is_string($value) && !ctype_digit($value))) {
            throw new BusinessException('VALIDATION_FAILED', $error);
        }
        $id = (int) $value;
        if ($id <= 0) {
            throw new BusinessException('VALIDATION_FAILED', $error);
        }
        return $id;
    }

    /**
     * @param list<string> $permissions
     * @return list<string>
     */
    private function effectivePermissions(int $staffId, array $permissions): array
    {
        if ($permissions !== []) {
            return $permissions;
        }
        return $this->permissions->effectiveCodes($staffId);
    }

    private function assertStaff(int $staffId): void
    {
        if ($staffId <= 0) {
            throw new BusinessException('UNAUTHENTICATED', 'UNAUTHENTICATED');
        }
    }

    /** @param list<string> $permissions */
    private function assertPermission(array $permissions, string $required): void
    {
        if (!in_array('*', $permissions, true) && !in_array($required, $permissions, true)) {
            throw new BusinessException('FORBIDDEN', 'FORBIDDEN');
        }
    }

    /** @return list<string> */
    private function sourceTypes(?string $sourceType): array
    {
        return $sourceType === null
            ? [ContentTodo::SOURCE_QUESTION_PENDING, ContentTodo::SOURCE_FEEDBACK_PENDING]
            : [$sourceType];
    }

    private function ageLabel(int $seconds): string
    {
        if ($seconds >= 86400) {
            return intdiv($seconds, 86400) . ' 天';
        }
        if ($seconds >= 3600) {
            return intdiv($seconds, 3600) . ' 小时';
        }
        return max(1, intdiv($seconds, 60)) . ' 分钟';
    }

    /** @param array<string,mixed> $scope */
    private function applyScope(\think\db\Query $query, array $scope, int $staffId, string $departmentField, string $creatorField): void
    {
        if ($scope['all']) {
            return;
        }
        if ($scope['department_ids'] === [] && !$scope['include_self']) {
            $query->where($departmentField, -1);
            return;
        }
        $query->where(function (\think\db\Query $where) use ($scope, $staffId, $departmentField, $creatorField): void {
            if ($scope['department_ids'] !== []) {
                $where->where($departmentField, 'in', $scope['department_ids']);
            }
            if ($scope['include_self']) {
                if ($scope['department_ids'] !== []) {
                    $where->whereOr($creatorField, $staffId);
                } else {
                    $where->where($creatorField, $staffId);
                }
            }
        });
    }

    private function isDuplicateKey(\Throwable $exception): bool
    {
        if ($exception instanceof ThinkPdoException) {
            $info = $exception->getData()['PDO Error Info'] ?? [];
            return ($info['SQLSTATE'] ?? null) === '23000'
                && (int) ($info['Driver Error Code'] ?? 0) === 1062;
        }
        if ($exception instanceof \PDOException) {
            return ($exception->errorInfo[0] ?? null) === '23000'
                && (int) ($exception->errorInfo[1] ?? 0) === 1062;
        }
        return false;
    }

    /** @param array<string,mixed> $payload */
    private function writeAudit(int $actorId, string $action, int $targetId, array $payload): void
    {
        Db::name('audit_log')->insert([
            'actor_id' => $actorId > 0 ? $actorId : null,
            'action' => $action,
            'target_type' => 'content_todo',
            'target_id' => $targetId,
            'payload_json' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'created_at' => nowDatetime(),
        ]);
    }
}
