<?php

declare(strict_types=1);

namespace App\service;

use App\support\Logger;
use support\think\Db;
use think\db\Query;

/**
 * QuestionService — Q&A lifecycle for course / chapter / lesson threads.
 *
 * Phase 11 (US4). One open thread per (learner, lesson). Learners post
 * questions and follow-ups, admins answer and (or close). All authorised
 * learners of the course can read every thread; there is no privacy split
 * (FR-046 + data-model §互动). Closing is admin-only.
 *
 * Invariants:
 *   1. Authorization check reuses EntitlementService.viewerAuthorized — a
 *      learner without an active entitlement cannot post or read a thread.
 *   2. answered_at / answered_by_staff_id are stamped on the first admin
 *      message; later admin messages do not move the timestamp again.
 *   3. Question rows are append-only in feel: the learner never edits the
 *      title/body, but follow-ups append to question_messages. Closing a
 *      thread moves status → 'closed' and no new admin messages are allowed.
 */
final class QuestionService
{
    private readonly MessageService $messages;

    public function __construct(
        private readonly EntitlementService $entitlements,
        private readonly DataScopeService $dataScopes,
        ?MessageService $messages = null,
    ) {
        $this->messages = $messages ?? new MessageService();
    }

    /** @return array<string, mixed> */
    public function askOnLesson(int $learnerId, int $lessonId, string $title, string $body): array
    {
        $lesson = $this->findLessonWithCourse($lessonId);
        if (!$lesson) {
            throw new BusinessException('NOT_FOUND', 'LESSON_NOT_FOUND');
        }
        $courseId = (int) $lesson['course_id'];
        if (!$this->entitlements->viewerAuthorized($courseId, $learnerId)) {
            throw new BusinessException('FORBIDDEN', 'NOT_AUTHORIZED');
        }
        $title = trim($title);
        $body  = trim($body);
        if ($title === '' || mb_strlen($title) > 128) {
            throw new BusinessException('VALIDATION_FAILED', 'QUESTION_TITLE_INVALID');
        }
        if ($body === '' || mb_strlen($body) > 4000) {
            throw new BusinessException('VALIDATION_FAILED', 'QUESTION_BODY_INVALID');
        }
        $id = (int) Db::transaction(function () use ($courseId, $lesson, $lessonId, $learnerId, $title, $body) {
            $now = date('Y-m-d H:i:s');
            $id = (int) Db::name('questions')->insertGetId([
                'course_id'   => $courseId,
                'chapter_id'  => (int) $lesson['chapter_id'],
                'lesson_id'   => $lessonId,
                'learner_id'  => $learnerId,
                'title'       => $title,
                'body'        => $body,
                'status'      => 'pending',
                'created_at'  => $now,
                'updated_at'  => $now,
            ]);
            Db::name('question_messages')->insert([
                'question_id'       => $id,
                'kind'              => 'questioner',
                'author_learner_id' => $learnerId,
                'author_staff_id'   => null,
                'body'              => $body,
                'created_at'        => $now,
            ]);
            return $id;
        });
        Logger::info('question.asked', [
            'question_id' => $id,
            'learner_id'  => $learnerId,
            'lesson_id'   => $lessonId,
        ]);
        return $this->findThread($id, $learnerId);
    }

    /** @return array<string, mixed> */
    public function appendLearnerFollowup(int $learnerId, int $questionId, string $body): array
    {
        $body = trim($body);
        if ($body === '' || mb_strlen($body) > 4000) {
            throw new BusinessException('VALIDATION_FAILED', 'MESSAGE_BODY_INVALID');
        }
        return Db::transaction(function () use ($learnerId, $questionId, $body) {
            $q = $this->findQuestionOrThrow($questionId, true);
            if ((int) $q['learner_id'] !== $learnerId) {
                throw new BusinessException('FORBIDDEN', 'NOT_QUESTION_OWNER');
            }
            if ($q['status'] === 'closed') {
                throw new BusinessException('VALIDATION_FAILED', 'QUESTION_CLOSED');
            }
            $now = date('Y-m-d H:i:s');
            Db::name('question_messages')->insert([
                'question_id'       => $questionId,
                'kind'              => 'questioner',
                'author_learner_id' => $learnerId,
                'author_staff_id'   => null,
                'body'              => $body,
                'created_at'        => $now,
            ]);
            Db::name('questions')->where('id', $questionId)->update([
                'status'     => 'pending',
                'updated_at' => $now,
            ]);
            return $this->findThread($questionId, $learnerId);
        });
    }

    /** @return array<string, mixed> */
    public function adminAnswer(int $staffId, int $questionId, string $body): array
    {
        $body = trim($body);
        if ($body === '' || mb_strlen($body) > 4000) {
            throw new BusinessException('VALIDATION_FAILED', 'MESSAGE_BODY_INVALID');
        }
        $result = Db::transaction(function () use ($staffId, $questionId, $body) {
            $q = $this->findQuestionOrThrow($questionId, true);
            $this->assertAdminAccessible($staffId, $q);
            if ($q['status'] === 'closed') {
                throw new BusinessException('VALIDATION_FAILED', 'QUESTION_CLOSED');
            }
            $now = date('Y-m-d H:i:s');
            $update = [
                'status' => 'answered',
                'updated_at' => $now,
            ];
            if (empty($q['answered_at'])) {
                $update['answered_at'] = $now;
                $update['answered_by_staff_id'] = $staffId;
            }
            $messageId = (int) Db::name('question_messages')->insertGetId([
                'question_id'       => $questionId,
                'kind'              => 'admin',
                'author_learner_id' => null,
                'author_staff_id'   => $staffId,
                'body'              => $body,
                'created_at'        => $now,
            ]);
            Db::name('questions')->where('id', $questionId)->update($update);
            return [
                'thread' => $this->findThread($questionId, null),
                'message_id' => $messageId,
                'learner_id' => (int) $q['learner_id'],
                'course_id' => (int) $q['course_id'],
                'title' => (string) ($q['title'] ?? ''),
            ];
        });
        $this->emitQuestionUpdate(
            (int) $result['learner_id'],
            $questionId,
            (int) $result['course_id'],
            (string) $result['title'],
            '管理员回复了你的问题。',
            'question_message:' . (int) $result['message_id'],
        );
        return $result['thread'];
    }

    /** @return array<string, mixed> */
    public function adminClose(int $staffId, int $questionId): array
    {
        $result = Db::transaction(function () use ($staffId, $questionId) {
            $q = $this->findQuestionOrThrow($questionId, true);
            $this->assertAdminAccessible($staffId, $q);
            if ($q['status'] === 'closed') {
                return ['thread' => $this->findThread($questionId, null), 'notify' => false];
            }
            $now = date('Y-m-d H:i:s');
            Db::name('questions')->where('id', $questionId)->update([
                'status'     => 'closed',
                'updated_at' => $now,
            ]);
            $messageId = (int) Db::name('question_messages')->insertGetId([
                'question_id'       => $questionId,
                'kind'              => 'system',
                'author_learner_id' => null,
                'author_staff_id'   => $staffId,
                'body'              => 'closed',
                'created_at'        => $now,
            ]);
            return [
                'thread' => $this->findThread($questionId, null),
                'notify' => true,
                'message_id' => $messageId,
                'learner_id' => (int) $q['learner_id'],
                'course_id' => (int) $q['course_id'],
                'title' => (string) ($q['title'] ?? ''),
            ];
        });
        if (($result['notify'] ?? false) === true) {
            $this->emitQuestionUpdate(
                (int) $result['learner_id'],
                $questionId,
                (int) $result['course_id'],
                (string) $result['title'],
                '你的问题已关闭。',
                'question_message:' . (int) $result['message_id'],
            );
        }
        return $result['thread'];
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    public function listForLesson(int $learnerId, int $lessonId, array $filters): array
    {
        $lesson = $this->findLessonWithCourse($lessonId);
        if (!$lesson) {
            throw new BusinessException('NOT_FOUND', 'LESSON_NOT_FOUND');
        }
        if (!$this->entitlements->viewerAuthorized((int) $lesson['course_id'], $learnerId)) {
            throw new BusinessException('FORBIDDEN', 'NOT_AUTHORIZED');
        }
        $page  = max(1, (int) ($filters['page']  ?? 1));
        $limit = min(50, max(1, (int) ($filters['limit'] ?? 20)));
        $statusFilter = (string) ($filters['status'] ?? '');
        $q = Db::name('questions')->where('lesson_id', $lessonId);
        if ($statusFilter !== '') {
            $q = $q->where('status', $statusFilter);
        }
        $total = (clone $q)->count();
        $rows = $q->order('id', 'desc')->page($page, $limit)->select()->toArray();
        $items = array_map(fn($r) => $this->shapeSummary($r), $rows);
        return ['items' => $items, 'total' => (int) $total, 'page' => $page, 'limit' => $limit];
    }

    /** @return array<string, mixed> */
    public function showForLearner(int $learnerId, int $questionId): array
    {
        $q = $this->findQuestionOrThrow($questionId);
        $courseId = (int) $q['course_id'];
        if (!$this->entitlements->viewerAuthorized($courseId, $learnerId)) {
            throw new BusinessException('FORBIDDEN', 'NOT_AUTHORIZED');
        }
        return $this->findThread($questionId, $learnerId);
    }

    /** @return array<string, mixed> */
    public function adminShow(int $staffId, int $questionId): array
    {
        $q = $this->findQuestionOrThrow($questionId);
        $this->assertAdminAccessible($staffId, $q);
        return $this->findThread($questionId, null);
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    public function adminInbox(int $staffId, array $filters): array
    {
        $page  = max(1, (int) ($filters['page']  ?? 1));
        $limit = min(50, max(1, (int) ($filters['limit'] ?? 20)));
        $courseId = max(0, (int) ($filters['course_id'] ?? 0));
        $lessonId = max(0, (int) ($filters['lesson_id'] ?? 0));
        $status = (string) ($filters['status'] ?? 'pending');
        if (!in_array($status, ['pending', 'answered', 'closed'], true)) {
            $status = 'pending';
        }
        $scope = $this->dataScopes->resolveForCourses($staffId);
        $q = Db::name('questions')
            ->alias('q')
            ->join('courses c', 'c.id = q.course_id')
            ->field('q.*')
            ->where('q.status', $status);
        $this->applyAdminScope($q, $scope, $staffId);
        if ($courseId > 0) {
            $q->where('q.course_id', $courseId);
        }
        if ($lessonId > 0) {
            $q->where('q.lesson_id', $lessonId);
        }
        $total = (clone $q)->count();
        $rows = $q->order('q.id', 'desc')->page($page, $limit)->select()->toArray();
        return [
            'items' => array_map(fn($r) => $this->shapeSummary($r), $rows),
            'total' => (int) $total,
            'page'  => $page,
            'limit' => $limit,
            'status' => $status,
        ];
    }

    /** @return array{courses:list<array{id:int,title:string}>,lessons:list<array{id:int,title:string}>} */
    public function adminFilterOptions(int $staffId, ?int $courseId): array
    {
        $scope = $this->dataScopes->resolveForCourses($staffId);
        $courseQuery = Db::name('questions')
            ->alias('q')
            ->join('courses c', 'c.id = q.course_id')
            ->field('c.id,c.title');
        $this->applyAdminScope($courseQuery, $scope, $staffId);
        $courseRows = $courseQuery
            ->group('c.id,c.title')
            ->order('c.title', 'asc')
            ->order('c.id', 'asc')
            ->select()
            ->toArray();

        $lessonRows = [];
        if ($courseId !== null && $courseId > 0) {
            $lessonQuery = Db::name('questions')
                ->alias('q')
                ->join('courses c', 'c.id = q.course_id')
                ->join('lessons l', 'l.id = q.lesson_id')
                ->field('l.id,l.title')
                ->where('q.course_id', $courseId);
            $this->applyAdminScope($lessonQuery, $scope, $staffId);
            $lessonRows = $lessonQuery
                ->group('l.id,l.title')
                ->order('l.title', 'asc')
                ->order('l.id', 'asc')
                ->select()
                ->toArray();
        }

        $shapeOption = static fn(array $row): array => [
            'id' => (int) $row['id'],
            'title' => (string) $row['title'],
        ];
        return [
            'courses' => array_map($shapeOption, $courseRows),
            'lessons' => array_map($shapeOption, $lessonRows),
        ];
    }

    // ─── helpers ─────────────────────────────────────────────────────

    /** @return array<string, mixed>|null */
    private function findLessonWithCourse(int $lessonId): ?array
    {
        $row = Db::name('lessons')->where('id', $lessonId)->find();
        if (!$row) {
            return null;
        }
        $chapter = Db::name('chapters')->where('id', (int) $row['chapter_id'])->find();
        if (!$chapter) {
            return null;
        }
        $row['course_id'] = (int) $chapter['course_id'];
        $row['chapter_id'] = (int) $row['chapter_id'];
        return $row;
    }

    /** @return array<string, mixed> */
    private function findQuestionOrThrow(int $questionId, bool $lock = false): array
    {
        $query = Db::name('questions')->where('id', $questionId);
        if ($lock) {
            $query->lock(true);
        }
        $q = $query->find();
        if (!$q) {
            throw new BusinessException('NOT_FOUND', 'QUESTION_NOT_FOUND');
        }
        return $q;
    }

    /** @param array<string, mixed> $question */
    private function assertAdminAccessible(int $staffId, array $question): void
    {
        $course = Db::name('courses')
            ->field('department_id,created_by_staff_id')
            ->where('id', (int) $question['course_id'])
            ->find();
        if (!$course) {
            throw new BusinessException('NOT_FOUND', 'COURSE_NOT_FOUND');
        }
        DataScopeService::assertCourseAccessibleFromScope(
            $this->dataScopes->resolveForCourses($staffId),
            (int) $course['department_id'],
            (int) $course['created_by_staff_id'],
            $staffId,
        );
    }

    /** @param array{all:bool,include_self:bool,department_ids:list<int>,scope:string} $scope */
    private function applyAdminScope(Query $query, array $scope, int $staffId): void
    {
        if ($scope['all']) {
            return;
        }
        if ($scope['department_ids'] === [] && !$scope['include_self']) {
            $query->where('c.department_id', -1);
            return;
        }
        $query->where(function (Query $where) use ($scope, $staffId): void {
            if ($scope['department_ids'] !== []) {
                $where->where('c.department_id', 'in', $scope['department_ids']);
            }
            if ($scope['include_self']) {
                if ($scope['department_ids'] !== []) {
                    $where->whereOr('c.created_by_staff_id', $staffId);
                } else {
                    $where->where('c.created_by_staff_id', $staffId);
                }
            }
        });
    }

    /** @return array<string, mixed> */
    private function findThread(int $questionId, ?int $viewerLearnerId): array
    {
        $q = Db::name('questions')->where('id', $questionId)->find();
        if (!$q) {
            throw new BusinessException('NOT_FOUND', 'QUESTION_NOT_FOUND');
        }
        $msgs = Db::name('question_messages')
            ->where('question_id', $questionId)
            ->order('id', 'asc')
            ->select()
            ->toArray();
        return [
            'question' => $this->shapeSummary($q),
            'messages' => array_map(
                fn($m) => $this->shapeMessage($m, $viewerLearnerId),
                $msgs,
            ),
        ];
    }

    /**
     * @param array<string, mixed> $r
     * @return array<string, mixed>
     */
    private function shapeSummary(array $r): array
    {
        return [
            'id'           => (int) $r['id'],
            'course_id'    => (int) $r['course_id'],
            'chapter_id'   => isset($r['chapter_id']) && $r['chapter_id'] !== null ? (int) $r['chapter_id'] : null,
            'lesson_id'    => isset($r['lesson_id']) && $r['lesson_id'] !== null ? (int) $r['lesson_id'] : null,
            'learner_id'   => (int) $r['learner_id'],
            'title'        => (string) ($r['title'] ?? ''),
            'status'       => (string) $r['status'],
            'answered_at'  => (string) ($r['answered_at'] ?? ''),
            'created_at'   => (string) ($r['created_at'] ?? ''),
            'updated_at'   => (string) ($r['updated_at'] ?? ''),
        ];
    }

    /**
     * @param array<string, mixed> $m
     * @return array<string, mixed>
     */
    private function shapeMessage(array $m, ?int $viewerLearnerId): array
    {
        $authorLearnerId = isset($m['author_learner_id']) && $m['author_learner_id'] !== null
            ? (int) $m['author_learner_id']
            : null;
        $authorStaffId = isset($m['author_staff_id']) && $m['author_staff_id'] !== null
            ? (int) $m['author_staff_id']
            : null;
        // ponytail: only the learner's own messages get their numeric
        // author id exposed; other learners stay anonymous for FR-008
        // (public nickname handling). Admin ids are admin-only.
        $returnAuthorLearnerId = ($viewerLearnerId !== null && $authorLearnerId === $viewerLearnerId)
            ? $authorLearnerId
            : null;
        return [
            'id'                => (int) $m['id'],
            'kind'              => (string) $m['kind'],
            'author_learner_id' => $returnAuthorLearnerId,
            'author_staff_id'   => $authorStaffId,
            'body'              => (string) $m['body'],
            'created_at'        => (string) $m['created_at'],
        ];
    }

    private function emitQuestionUpdate(
        int $learnerId,
        int $questionId,
        int $courseId,
        string $questionTitle,
        string $body,
        string $idempotencyKey,
    ): void {
        try {
            $title = $questionTitle !== '' ? '问题「' . $questionTitle . '」有新动态' : '你的问题有新动态';
            $this->messages->emit(
                MessageService::KIND_QUESTION_UPDATE,
                $learnerId,
                $title,
                $body,
                ['question_id' => $questionId, 'course_id' => $courseId],
                'question',
                $questionId,
                $idempotencyKey,
            );
        } catch (\Throwable $exception) {
            Logger::warning('question.notification_failed', [
                'question_id' => $questionId,
                'learner_id' => $learnerId,
                'err' => $exception->getMessage(),
            ]);
        }
    }
}
