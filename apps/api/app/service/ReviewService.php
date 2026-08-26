<?php

declare(strict_types=1);

namespace App\service;

use App\support\Logger;
use support\think\Db;

/**
 * Course reviews and their three-level reply tree.
 *
 * Public DTOs deliberately remove account ids. Admin operations expose ids
 * only after the course's current department has passed the data-scope guard.
 */
final class ReviewService
{
    private const MAX_REPLY_LEVEL = 3;

    public function __construct(
        private readonly EntitlementService $entitlements,
        private readonly DataScopeService $dataScopes,
    ) {
    }

    /** @return array<string, mixed> */
    public function postReview(int $learnerId, int $courseId, int $rating, string $body): array
    {
        if (!$this->findCourse($courseId)) {
            throw new BusinessException('NOT_FOUND', 'COURSE_NOT_FOUND');
        }
        if (!$this->entitlements->viewerAuthorized($courseId, $learnerId)) {
            throw new BusinessException('FORBIDDEN', 'NOT_AUTHORIZED');
        }
        if (!$this->hasCompletedLesson($learnerId, $courseId)) {
            throw new BusinessException('FORBIDDEN', 'REVIEW_REQUIRES_COMPLETED_LESSON');
        }
        [$rating, $body] = $this->validateReview($rating, $body);

        $now = date('Y-m-d H:i:s');
        try {
            $reviewId = (int) Db::name('reviews')->insertGetId([
                'course_id' => $courseId,
                'learner_id' => $learnerId,
                'rating' => $rating,
                'body' => $body,
                'visibility' => 'public',
                'active_key' => sprintf('%d:%d', $learnerId, $courseId),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        } catch (\Throwable $exception) {
            if (str_contains($exception->getMessage(), 'uq_active_review')) {
                throw new BusinessException('CONFLICT', 'REVIEW_ALREADY_EXISTS');
            }
            throw $exception;
        }

        return $this->buildThread($this->loadReviewWithAuthor($reviewId), false, $learnerId);
    }

    /** @return array<string, mixed> */
    public function updateReview(
        int $learnerId,
        int $reviewId,
        int $rating,
        string $body,
    ): array {
        [$rating, $body] = $this->validateReview($rating, $body);
        Db::transaction(function () use ($learnerId, $reviewId, $rating, $body): void {
            $review = $this->loadReviewOrThrow($reviewId, true);
            if ((int) $review['learner_id'] !== $learnerId) {
                throw new BusinessException('FORBIDDEN', 'NOT_REVIEW_OWNER');
            }
            Db::name('reviews')->where('id', $reviewId)->update([
                'rating' => $rating,
                'body' => $body,
                'updated_at' => $this->nextEditTime((string) $review['created_at']),
            ]);
        });

        return $this->buildThread($this->loadReviewWithAuthor($reviewId), false, $learnerId);
    }

    public function deleteReview(int $learnerId, int $reviewId): void
    {
        Db::transaction(function () use ($learnerId, $reviewId): void {
            $review = $this->loadReviewOrThrow($reviewId, true);
            if ((int) $review['learner_id'] !== $learnerId) {
                throw new BusinessException('FORBIDDEN', 'NOT_REVIEW_OWNER');
            }
            Db::name('reviews')->where('id', $reviewId)->delete();
        });
    }

    /** @return array<string, mixed> */
    public function listForCourse(
        int $courseId,
        int $page = 1,
        int $limit = 20,
        ?int $viewerLearnerId = null,
    ): array {
        [$page, $limit] = $this->normalizePagination($page, $limit);
        $query = $this->reviewQueryWithAuthor()
            ->where('r.course_id', $courseId)
            ->where('r.visibility', 'public');
        $total = (int) (clone $query)->count();
        $rows = $query
            ->order('r.id', 'desc')
            ->page($page, $limit)
            ->select()
            ->toArray();
        $viewerReview = null;
        if ($viewerLearnerId !== null) {
            $viewerRow = $this->reviewQueryWithAuthor()
                ->where('r.course_id', $courseId)
                ->where('r.learner_id', $viewerLearnerId)
                ->where('r.visibility', 'public')
                ->find();
            if ($viewerRow) {
                $viewerReview = $this->shapeReview($viewerRow, false, $viewerLearnerId);
            }
        }

        return [
            'items' => array_map(
                fn (array $row): array => $this->shapeReview($row, false, $viewerLearnerId),
                $rows,
            ),
            'viewer_review' => $viewerReview,
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
        ];
    }

    /** @return array<string, mixed> */
    public function showThread(int $reviewId, ?int $viewerLearnerId = null): array
    {
        $review = $this->loadReviewWithAuthor($reviewId);
        if (!$review || $review['visibility'] !== 'public') {
            throw new BusinessException('NOT_FOUND', 'REVIEW_NOT_FOUND');
        }
        return $this->buildThread($review, false, $viewerLearnerId);
    }

    /** @return array<string, mixed> */
    public function replyAsLearner(
        int $learnerId,
        int $reviewId,
        ?int $parentReplyId,
        string $body,
    ): array {
        return $this->reply($learnerId, 'learner', $reviewId, $parentReplyId, $body, false);
    }

    /** @return array<string, mixed> */
    public function replyAsAdmin(
        int $staffId,
        int $reviewId,
        ?int $parentReplyId,
        string $body,
    ): array {
        return $this->reply($staffId, 'admin', $reviewId, $parentReplyId, $body, true);
    }

    /** @return array<string, mixed> */
    public function listForModeration(
        int $staffId,
        int $courseId,
        string $visibility,
        int $page = 1,
        int $limit = 20,
    ): array {
        [$page, $limit] = $this->normalizePagination($page, $limit);
        $course = $this->findCourse($courseId);
        if (!$course || !$this->canAccessCourse($staffId, $course)) {
            return ['items' => [], 'total' => 0, 'page' => $page, 'limit' => $limit];
        }

        $query = $this->reviewQueryWithAuthor()->where('r.course_id', $courseId);
        if (in_array($visibility, ['public', 'hidden'], true)) {
            $query->where('r.visibility', $visibility);
        }
        $total = (int) (clone $query)->count();
        $rows = $query
            ->order('r.id', 'desc')
            ->page($page, $limit)
            ->select()
            ->toArray();

        return [
            'items' => array_map(fn (array $row): array => $this->shapeReview($row, true), $rows),
            'viewer_review' => null,
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
        ];
    }

    /** @return array{courses:list<array{id:int,title:string}>} */
    public function moderationFilterOptions(int $staffId): array
    {
        $scope = $this->dataScopes->resolveForCourses($staffId);
        $query = Db::name('reviews')
            ->alias('r')
            ->join('courses c', 'c.id = r.course_id')
            ->field('c.id,c.title');
        $this->applyAdminScope($query, $scope, $staffId);
        $rows = $query
            ->group('c.id,c.title')
            ->order('c.title', 'asc')
            ->order('c.id', 'asc')
            ->select()
            ->toArray();

        return [
            'courses' => array_map(static fn (array $row): array => [
                'id' => (int) $row['id'],
                'title' => (string) $row['title'],
            ], $rows),
        ];
    }

    /** @return array<string, mixed> */
    public function showForModeration(int $staffId, int $reviewId): array
    {
        $review = $this->loadReviewOrThrow($reviewId);
        $this->assertAdminAccessible($staffId, $review);
        return $this->buildThread($this->loadReviewWithAuthor($reviewId), true);
    }

    /** @return array<string, mixed> */
    public function hideReview(int $staffId, int $reviewId, string $reason): array
    {
        $changed = Db::transaction(function () use ($staffId, $reviewId, $reason): bool {
            $review = $this->loadReviewOrThrow($reviewId, true);
            $this->assertAdminAccessible($staffId, $review);
            if ($review['visibility'] === 'hidden') {
                return false;
            }
            $reason = $this->validateHideReason($reason);
            $now = date('Y-m-d H:i:s');
            Db::name('reviews')->where('id', $reviewId)->update([
                'visibility' => 'hidden',
                'active_key' => null,
                'hidden_reason' => $reason,
                'hidden_by_staff_id' => $staffId,
                'hidden_at' => $now,
            ]);
            $this->insertModerationLog('review', $reviewId, 'hide', $reason, $staffId, $now);
            return true;
        });
        if ($changed) {
            Logger::info('review.hidden', ['review_id' => $reviewId, 'staff_id' => $staffId]);
        }
        return $this->showForModeration($staffId, $reviewId);
    }

    /** @return array<string, mixed> */
    public function restoreReview(int $staffId, int $reviewId): array
    {
        $changed = Db::transaction(function () use ($staffId, $reviewId): bool {
            $review = $this->loadReviewOrThrow($reviewId, true);
            $this->assertAdminAccessible($staffId, $review);
            if ($review['visibility'] === 'public') {
                return false;
            }
            $duplicate = Db::name('reviews')
                ->where('course_id', (int) $review['course_id'])
                ->where('learner_id', (int) $review['learner_id'])
                ->where('visibility', 'public')
                ->where('id', '<>', $reviewId)
                ->find();
            if ($duplicate) {
                throw new BusinessException('CONFLICT', 'REVIEW_ALREADY_EXISTS');
            }
            $now = date('Y-m-d H:i:s');
            try {
                Db::name('reviews')->where('id', $reviewId)->update([
                    'visibility' => 'public',
                    'active_key' => sprintf(
                        '%d:%d',
                        (int) $review['learner_id'],
                        (int) $review['course_id'],
                    ),
                    'hidden_reason' => null,
                    'hidden_by_staff_id' => null,
                    'hidden_at' => null,
                ]);
            } catch (\Throwable $exception) {
                if (str_contains($exception->getMessage(), 'uq_active_review')) {
                    throw new BusinessException('CONFLICT', 'REVIEW_ALREADY_EXISTS');
                }
                throw $exception;
            }
            $this->insertModerationLog('review', $reviewId, 'restore', '', $staffId, $now);
            return true;
        });
        if ($changed) {
            Logger::info('review.restored', ['review_id' => $reviewId, 'staff_id' => $staffId]);
        }
        return $this->showForModeration($staffId, $reviewId);
    }

    /** @return array<string, mixed> */
    public function hideReply(int $staffId, int $replyId, string $reason): array
    {
        $reviewId = 0;
        $changed = Db::transaction(function () use ($staffId, $replyId, $reason, &$reviewId): bool {
            $reply = $this->loadReplyOrThrow($replyId, true);
            $reviewId = (int) $reply['review_id'];
            $review = $this->loadReviewOrThrow($reviewId);
            $this->assertAdminAccessible($staffId, $review);
            if ($reply['visibility'] === 'hidden') {
                return false;
            }
            $reason = $this->validateHideReason($reason);
            $now = date('Y-m-d H:i:s');
            Db::name('review_replies')->where('id', $replyId)->update([
                'visibility' => 'hidden',
                'hidden_reason' => $reason,
                'hidden_by_staff_id' => $staffId,
                'hidden_at' => $now,
            ]);
            $this->insertModerationLog('reply', $replyId, 'hide', $reason, $staffId, $now);
            return true;
        });
        if ($changed) {
            Logger::info('review.reply_hidden', ['reply_id' => $replyId, 'staff_id' => $staffId]);
        }
        return $this->showForModeration($staffId, $reviewId);
    }

    /** @return array<string, mixed> */
    public function restoreReply(int $staffId, int $replyId): array
    {
        $reviewId = 0;
        $changed = Db::transaction(function () use ($staffId, $replyId, &$reviewId): bool {
            $reply = $this->loadReplyOrThrow($replyId, true);
            $reviewId = (int) $reply['review_id'];
            $review = $this->loadReviewOrThrow($reviewId);
            $this->assertAdminAccessible($staffId, $review);
            if ($reply['visibility'] === 'public') {
                return false;
            }
            $now = date('Y-m-d H:i:s');
            Db::name('review_replies')->where('id', $replyId)->update([
                'visibility' => 'public',
                'hidden_reason' => null,
                'hidden_by_staff_id' => null,
                'hidden_at' => null,
            ]);
            $this->insertModerationLog('reply', $replyId, 'restore', '', $staffId, $now);
            return true;
        });
        if ($changed) {
            Logger::info('review.reply_restored', ['reply_id' => $replyId, 'staff_id' => $staffId]);
        }
        return $this->showForModeration($staffId, $reviewId);
    }

    /** @return array<string, mixed> */
    private function reply(
        int $actorId,
        string $kind,
        int $reviewId,
        ?int $parentReplyId,
        string $body,
        bool $admin,
    ): array {
        $body = trim($body);
        if ($body === '' || mb_strlen($body) > 4000) {
            throw new BusinessException('VALIDATION_FAILED', 'REPLY_BODY_INVALID');
        }

        $replyId = (int) Db::transaction(function () use (
            $actorId,
            $kind,
            $reviewId,
            $parentReplyId,
            $body,
            $admin,
        ): int {
            $review = $this->loadReviewOrThrow($reviewId, true);
            if ($review['visibility'] !== 'public') {
                throw new BusinessException('NOT_FOUND', 'REVIEW_NOT_FOUND');
            }
            if ($admin) {
                $this->assertAdminAccessible($actorId, $review);
            } elseif (!$this->entitlements->viewerAuthorized((int) $review['course_id'], $actorId)) {
                throw new BusinessException('FORBIDDEN', 'NOT_AUTHORIZED');
            }

            if ($parentReplyId !== null) {
                $parent = $this->loadReplyOrThrow($parentReplyId, true, 'REPLY_PARENT_INVALID');
                if (
                    (int) $parent['review_id'] !== $reviewId
                    || $parent['visibility'] !== 'public'
                ) {
                    throw new BusinessException('VALIDATION_FAILED', 'REPLY_PARENT_INVALID');
                }
                if ($this->replyLevel($parentReplyId) >= self::MAX_REPLY_LEVEL) {
                    throw new BusinessException('VALIDATION_FAILED', 'REPLY_DEPTH_EXCEEDED');
                }
            }

            $now = date('Y-m-d H:i:s');
            return (int) Db::name('review_replies')->insertGetId([
                'review_id' => $reviewId,
                'parent_id' => $parentReplyId,
                'kind' => $kind,
                'author_learner_id' => $admin ? null : $actorId,
                'author_staff_id' => $admin ? $actorId : null,
                'body' => $body,
                'visibility' => 'public',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        });

        $reply = $this->loadReplyWithAuthor($replyId);
        if (!$reply) {
            throw new BusinessException('NOT_FOUND', 'REPLY_NOT_FOUND');
        }
        return $this->shapeReply($reply, $admin, $admin ? null : $actorId);
    }

    /** @return array{int, string} */
    private function validateReview(int $rating, string $body): array
    {
        if ($rating < 1 || $rating > 5) {
            throw new BusinessException('VALIDATION_FAILED', 'REVIEW_RATING_OUT_OF_RANGE');
        }
        $body = trim($body);
        if ($body === '' || mb_strlen($body) > 4000) {
            throw new BusinessException('VALIDATION_FAILED', 'REVIEW_BODY_INVALID');
        }
        return [$rating, $body];
    }

    private function validateHideReason(string $reason): string
    {
        $reason = trim($reason);
        if ($reason === '' || mb_strlen($reason) > 255) {
            throw new BusinessException('VALIDATION_FAILED', 'HIDE_REASON_REQUIRED');
        }
        return $reason;
    }

    private function hasCompletedLesson(int $learnerId, int $courseId): bool
    {
        return Db::name('lesson_progresses')
            ->alias('lp')
            ->join('lessons l', 'l.id = lp.lesson_id')
            ->join('chapters ch', 'ch.id = l.chapter_id')
            ->where('lp.learner_id', $learnerId)
            ->where('lp.completed', 1)
            ->where('ch.course_id', $courseId)
            ->count() > 0;
    }

    /** @return array<string, mixed>|null */
    private function findCourse(int $courseId): ?array
    {
        $row = Db::name('courses')->where('id', $courseId)->find();
        return $row ?: null;
    }

    /** @return array<string, mixed> */
    private function loadReviewOrThrow(int $reviewId, bool $lock = false): array
    {
        $query = Db::name('reviews')->where('id', $reviewId);
        if ($lock) {
            $query->lock(true);
        }
        $review = $query->find();
        if (!$review) {
            throw new BusinessException('NOT_FOUND', 'REVIEW_NOT_FOUND');
        }
        return $review;
    }

    /** @return array<string, mixed> */
    private function loadReplyOrThrow(
        int $replyId,
        bool $lock = false,
        string $notFoundMessage = 'REPLY_NOT_FOUND',
    ): array {
        $query = Db::name('review_replies')->where('id', $replyId);
        if ($lock) {
            $query->lock(true);
        }
        $reply = $query->find();
        if (!$reply) {
            $apiCode = $notFoundMessage === 'REPLY_PARENT_INVALID' ? 'VALIDATION_FAILED' : 'NOT_FOUND';
            throw new BusinessException($apiCode, $notFoundMessage);
        }
        return $reply;
    }

    /** @return array<string, mixed>|null */
    private function loadReviewWithAuthor(int $reviewId): ?array
    {
        $row = $this->reviewQueryWithAuthor()->where('r.id', $reviewId)->find();
        return $row ?: null;
    }

    private function reviewQueryWithAuthor(): \think\db\Query
    {
        return Db::name('reviews')
            ->alias('r')
            ->leftJoin('learners l', 'l.account_id = r.learner_id')
            ->field('r.*, l.nickname AS author_nickname, l.show_on_course AS author_public');
    }

    /** @return array<string, mixed>|null */
    private function loadReplyWithAuthor(int $replyId): ?array
    {
        $row = $this->replyQueryWithAuthor()->where('rr.id', $replyId)->find();
        return $row ?: null;
    }

    private function replyQueryWithAuthor(): \think\db\Query
    {
        return Db::name('review_replies')
            ->alias('rr')
            ->leftJoin('learners l', 'l.account_id = rr.author_learner_id')
            ->leftJoin('staff_users s', 's.account_id = rr.author_staff_id')
            ->field(
                'rr.*, l.nickname AS author_nickname, l.show_on_course AS author_public, '
                . 's.display_name AS staff_display_name',
            );
    }

    /** @param array<string, mixed> $review */
    private function assertAdminAccessible(int $staffId, array $review): void
    {
        $course = $this->findCourse((int) $review['course_id']);
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

    /** @param array<string, mixed> $course */
    private function canAccessCourse(int $staffId, array $course): bool
    {
        try {
            DataScopeService::assertCourseAccessibleFromScope(
                $this->dataScopes->resolveForCourses($staffId),
                (int) $course['department_id'],
                (int) $course['created_by_staff_id'],
                $staffId,
            );
            return true;
        } catch (BusinessException $exception) {
            if ($exception->apiCode === 'FORBIDDEN') {
                return false;
            }
            throw $exception;
        }
    }

    /** @param array{all:bool,include_self:bool,department_ids:list<int>,scope:string} $scope */
    private function applyAdminScope(\think\db\Query $query, array $scope, int $staffId): void
    {
        if ($scope['all']) {
            return;
        }
        if ($scope['department_ids'] === [] && !$scope['include_self']) {
            $query->where('c.department_id', -1);
            return;
        }
        $query->where(function (\think\db\Query $where) use ($scope, $staffId): void {
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

    private function replyLevel(int $replyId): int
    {
        $level = 0;
        $currentId = $replyId;
        $visited = [];
        while ($currentId > 0) {
            if (isset($visited[$currentId]) || $level >= self::MAX_REPLY_LEVEL) {
                throw new BusinessException('VALIDATION_FAILED', 'REPLY_PARENT_INVALID');
            }
            $visited[$currentId] = true;
            $reply = Db::name('review_replies')
                ->field('parent_id')
                ->where('id', $currentId)
                ->find();
            if (!$reply) {
                throw new BusinessException('VALIDATION_FAILED', 'REPLY_PARENT_INVALID');
            }
            $level++;
            $currentId = $reply['parent_id'] === null ? 0 : (int) $reply['parent_id'];
        }
        return $level;
    }

    /**
     * @param array<string, mixed>|null $review
     * @return array<string, mixed>
     */
    private function buildThread(
        ?array $review,
        bool $admin,
        ?int $viewerLearnerId = null,
    ): array {
        if (!$review) {
            throw new BusinessException('NOT_FOUND', 'REVIEW_NOT_FOUND');
        }
        $rows = $this->replyQueryWithAuthor()
            ->where('rr.review_id', (int) $review['id'])
            ->order('rr.id', 'asc')
            ->select()
            ->toArray();
        if (!$admin) {
            $rows = $review['visibility'] === 'public' ? $this->publicReplies($rows) : [];
        }
        return [
            'review' => $this->shapeReview($review, $admin, $viewerLearnerId),
            'replies' => array_map(
                fn (array $row): array => $this->shapeReply($row, $admin, $viewerLearnerId),
                $rows,
            ),
        ];
    }

    /**
     * A public reply is visible only when it and every ancestor are public.
     *
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    private function publicReplies(array $rows): array
    {
        $byId = [];
        foreach ($rows as $row) {
            $byId[(int) $row['id']] = $row;
        }
        $memo = [];
        $visible = [];
        foreach ($rows as $row) {
            $visiting = [];
            if ($this->replyIsPublic((int) $row['id'], $byId, $memo, $visiting)) {
                $visible[] = $row;
            }
        }
        return $visible;
    }

    /**
     * @param array<int, array<string, mixed>> $byId
     * @param array<int, bool> $memo
     * @param array<int, bool> $visiting
     */
    private function replyIsPublic(int $replyId, array $byId, array &$memo, array &$visiting): bool
    {
        if (array_key_exists($replyId, $memo)) {
            return $memo[$replyId];
        }
        if (isset($visiting[$replyId]) || !isset($byId[$replyId])) {
            return false;
        }
        $visiting[$replyId] = true;
        $row = $byId[$replyId];
        $visible = $row['visibility'] === 'public';
        $parentId = $row['parent_id'] === null ? null : (int) $row['parent_id'];
        if ($visible && $parentId !== null) {
            $visible = $this->replyIsPublic($parentId, $byId, $memo, $visiting);
        }
        unset($visiting[$replyId]);
        return $memo[$replyId] = $visible;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function shapeReview(
        array $row,
        bool $admin,
        ?int $viewerLearnerId = null,
    ): array {
        return [
            'id' => (int) $row['id'],
            'course_id' => (int) $row['course_id'],
            'learner_id' => $admin ? (int) $row['learner_id'] : null,
            'viewer_owned' => !$admin
                && $viewerLearnerId !== null
                && (int) $row['learner_id'] === $viewerLearnerId,
            'author_name' => $this->learnerAuthorName($row),
            'rating' => (int) $row['rating'],
            'body' => (string) $row['body'],
            'visibility' => (string) $row['visibility'],
            'hidden_reason' => $admin && $row['hidden_reason'] !== null
                ? (string) $row['hidden_reason']
                : null,
            'hidden_by_staff_id' => $admin && $row['hidden_by_staff_id'] !== null
                ? (int) $row['hidden_by_staff_id']
                : null,
            'hidden_at' => $admin && $row['hidden_at'] !== null ? (string) $row['hidden_at'] : null,
            'edited' => $this->wasEdited($row),
            'created_at' => (string) $row['created_at'],
            'updated_at' => (string) $row['updated_at'],
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function shapeReply(
        array $row,
        bool $admin,
        ?int $viewerLearnerId = null,
    ): array {
        return [
            'id' => (int) $row['id'],
            'review_id' => (int) $row['review_id'],
            'parent_id' => $row['parent_id'] === null ? null : (int) $row['parent_id'],
            'kind' => (string) $row['kind'],
            'author_learner_id' => $admin && $row['author_learner_id'] !== null
                ? (int) $row['author_learner_id']
                : null,
            'author_staff_id' => $admin && $row['author_staff_id'] !== null
                ? (int) $row['author_staff_id']
                : null,
            'viewer_owned' => !$admin
                && $viewerLearnerId !== null
                && $row['author_learner_id'] !== null
                && (int) $row['author_learner_id'] === $viewerLearnerId,
            'author_name' => $this->replyAuthorName($row),
            'body' => (string) $row['body'],
            'visibility' => (string) $row['visibility'],
            'hidden_reason' => $admin && $row['hidden_reason'] !== null
                ? (string) $row['hidden_reason']
                : null,
            'hidden_by_staff_id' => $admin && $row['hidden_by_staff_id'] !== null
                ? (int) $row['hidden_by_staff_id']
                : null,
            'hidden_at' => $admin && $row['hidden_at'] !== null ? (string) $row['hidden_at'] : null,
            'edited' => $this->wasEdited($row),
            'created_at' => (string) $row['created_at'],
            'updated_at' => (string) $row['updated_at'],
        ];
    }

    /** @param array<string, mixed> $row */
    private function learnerAuthorName(array $row): string
    {
        $nickname = trim((string) ($row['author_nickname'] ?? ''));
        return (int) ($row['author_public'] ?? 0) === 1 && $nickname !== ''
            ? $nickname
            : '匿名学员';
    }

    /** @param array<string, mixed> $row */
    private function replyAuthorName(array $row): string
    {
        if ($row['kind'] === 'admin') {
            $name = trim((string) ($row['staff_display_name'] ?? ''));
            return $name !== '' ? $name : '管理员';
        }
        if ($row['kind'] === 'system') {
            return '系统';
        }
        return $this->learnerAuthorName($row);
    }

    /** @param array<string, mixed> $row */
    private function wasEdited(array $row): bool
    {
        return strtotime((string) ($row['updated_at'] ?? ''))
            > strtotime((string) ($row['created_at'] ?? ''));
    }

    private function nextEditTime(string $createdAt): string
    {
        $createdTimestamp = strtotime($createdAt);
        $now = time();
        if ($createdTimestamp !== false && $now <= $createdTimestamp) {
            $now = $createdTimestamp + 1;
        }
        return date('Y-m-d H:i:s', $now);
    }

    private function insertModerationLog(
        string $objectType,
        int $objectId,
        string $action,
        string $reason,
        int $staffId,
        string $createdAt,
    ): void {
        Db::name('moderation_logs')->insert([
            'object_type' => $objectType,
            'object_id' => $objectId,
            'action' => $action,
            'reason' => $reason,
            'staff_id' => $staffId,
            'created_at' => $createdAt,
        ]);
    }

    /** @return array{int, int} */
    private function normalizePagination(int $page, int $limit): array
    {
        return [max(1, $page), max(1, min(100, $limit))];
    }
}
