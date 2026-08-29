<?php
declare(strict_types=1);

namespace App\controller\learner;

use App\service\BusinessException;
use App\service\EntitlementService;
use App\service\ProgressService;
use App\support\ApiResponse;
use support\Request;
use support\think\Db;

/**
 * LearningController — Phase 6 / US3 entry points the learner drives:
 *
 *   - POST /courses/{id}/start      free → grant + 200; paid → 409
 *   - POST /lessons/{id}/progress   record open / position / completion
 *   - GET  /my/learning             resume banner list
 *
 * The payment-side POST /courses/{id}/orders + GET /orders/{id} live in
 * OrderController. /my/learning is wired here because its primary
 * purpose is to drive lesson consumption (Phase 6 US3 "继续学习" entry).
 *
 *   // ponytail: we don't expose a `delete` / `reset` endpoint for
 *   progress. FR-070 forbids progress regression. A revoke from staff
 *   (Phase 10) flips the entitlement; the per-lesson progress rows
 *   remain so the audit trail stays clean.
 */
final class LearningController
{
    public function __construct(
        private readonly EntitlementService $entitlements,
        private readonly ProgressService $progress,
    ) {}

    /**
     * POST /api/learner/v1/courses/{id}/start
     *
     * Free courses grant immediately; paid courses return 409 with a
     * message pointing the learner to /courses/{id}/orders.
     */
    public function start(Request $request, string $id): \support\Response
    {
        $learnerId = $this->requireLearner($request);
        $courseId = (int) $id;
        if ($courseId <= 0) {
            return ApiResponse::fail(ApiResponse::VALIDATION_FAILED, 'COURSE_INVALID');
        }
        $course = Db::name('courses')->where('id', $courseId)->find();
        if (!$course || $course['status'] !== 'published') {
            return ApiResponse::fail(ApiResponse::NOT_FOUND, 'COURSE_NOT_FOUND');
        }

        // Already entitled → idempotent 200 with the existing entitlement.
        $existing = $this->entitlements->findActive($learnerId, $courseId);
        if ($existing) {
            return ApiResponse::ok([
                'course_id'    => $courseId,
                'entitled'     => true,
                'source'       => $existing['source'],
                'price_mode'   => (string) $course['price_mode'],
                'first_lesson' => $this->firstLessonSummary($courseId),
            ]);
        }

        if ((string) $course['price_mode'] !== 'free') {
            return ApiResponse::fail(ApiResponse::CONFLICT, 'COURSE_PAID');
        }

        $row = $this->entitlements->grant($learnerId, $courseId, 'free');
        return ApiResponse::ok([
            'course_id'    => $courseId,
            'entitled'     => true,
            'source'       => $row['source'],
            'price_mode'   => 'free',
            'first_lesson' => $this->firstLessonSummary($courseId),
        ]);
    }

    /**
     * POST /api/learner/v1/lessons/{id}/progress
     *
     * Body: { content_type: 'markdown'|'pdf'|'video', position_seconds,
     *         duration_seconds?, completed? }
     *
     * Markdown/PDF: completed=true only accepted after the lesson has
     *   been opened (opened_at is set the first time the lesson is
     *   delivered to the learner via PublicLessonService).
     * Video: completion is auto-derived when position ≥ 90% of duration.
     */
    public function reportProgress(Request $request, string $id): \support\Response
    {
        $learnerId = $this->requireLearner($request);
        $lessonId = (int) $id;
        if ($lessonId <= 0) {
            return ApiResponse::fail(ApiResponse::VALIDATION_FAILED, 'LESSON_INVALID');
        }

        $body = self::readJson($request);
        $contentType = (string) ($body['content_type'] ?? '');
        if ($contentType !== 'markdown' && $contentType !== 'pdf' && $contentType !== 'video') {
            return ApiResponse::fail(ApiResponse::VALIDATION_FAILED, 'LESSON_TYPE_INVALID');
        }
        $position = (int) ($body['position_seconds'] ?? 0);
        $duration = (int) ($body['duration_seconds'] ?? 0);
        $clientComplete = (bool) ($body['completed'] ?? false);

        try {
            $result = $this->progress->reportProgress(
                $learnerId,
                $lessonId,
                $contentType,
                $duration,
                $position,
                $clientComplete,
            );
        } catch (BusinessException $e) {
            return ApiResponse::fail($e->apiCode, $e->getMessage());
        }
        return ApiResponse::ok($result);
    }

    /**
     * POST /api/learner/v1/lessons/{id}/video-heartbeat
     *
     * The route is deliberately video-only. Clients cannot switch the
     * content type through this seam; ProgressService still verifies the
     * persisted lesson type and duration before writing anything.
     */
    public function videoHeartbeat(Request $request, string $id): \support\Response
    {
        $learnerId = $this->requireLearner($request);
        $lessonId = (int) $id;
        if ($lessonId <= 0) {
            return ApiResponse::fail(ApiResponse::VALIDATION_FAILED, 'LESSON_INVALID');
        }

        $body = self::readJson($request);
        $position = (int) ($body['position_seconds'] ?? 0);
        $duration = (int) ($body['duration_seconds'] ?? 0);
        if ($position < 0 || $duration < 0) {
            return ApiResponse::fail(ApiResponse::VALIDATION_FAILED, 'PROGRESS_INVALID');
        }

        try {
            $result = $this->progress->reportProgress(
                $learnerId,
                $lessonId,
                'video',
                $duration,
                $position,
                false,
            );
        } catch (BusinessException $e) {
            return ApiResponse::fail($e->apiCode, $e->getMessage());
        }
        return ApiResponse::ok($result);
    }

    /**
     * GET /api/learner/v1/my/learning
     *
     * Returns one row per course the learner has either an active
     * entitlement for, or has a non-zero enrollment for. Sorted by
     * updated_at DESC so the most-recently-touched course floats to
     * the top — this is the "继续学习" entry on the /me page.
     */
    public function myLearning(Request $request): \support\Response
    {
        $learnerId = $this->requireLearner($request);
        return ApiResponse::ok($this->progress->listLearning($learnerId));
    }

    private function requireLearner(Request $request): int
    {
        $id = (int) ($request->account_id ?? 0);
        if ($id <= 0) {
            throw new BusinessException(ApiResponse::UNAUTHENTICATED, 'UNAUTHENTICATED');
        }
        return $id;
    }

    /**
     * Find the first enabled lesson for a course, in chapter/lesson sort
     * order. Used so /start can hand the client a deep-link to the
     * first lesson. Returns null when the course has no enabled
     * lessons yet (admin still building the curriculum).
     */
    /** @return array<string, mixed>|null */
    private function firstLessonSummary(int $courseId): ?array
    {
        $chapterIds = Db::name('chapters')
            ->where('course_id', $courseId)
            ->where('status', 'enabled')
            ->order('sort', 'asc')
            ->column('id');
        if (empty($chapterIds)) {
            return null;
        }
        $lesson = Db::name('lessons')
            ->where('chapter_id', 'in', $chapterIds)
            ->where('status', 'enabled')
            ->order('sort', 'asc')
            ->find();
        if (!$lesson) {
            return null;
        }
        return [
            'id'           => (int) $lesson['id'],
            'title'        => (string) $lesson['title'],
            'content_type' => (string) $lesson['content_type'],
            'is_preview'   => (int) ($lesson['is_preview'] ?? 0) === 1,
        ];
    }

    /** @return array<string, mixed> */
    private static function readJson(Request $request): array
    {
        $raw = (string) $request->rawBody();
        if ($raw === '') {
            return [];
        }
        $data = json_decode($raw, true);
        return is_array($data) ? $data : [];
    }
}
