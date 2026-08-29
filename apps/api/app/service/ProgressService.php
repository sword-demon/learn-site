<?php
declare(strict_types=1);

namespace App\service;

use App\support\Logger;
use support\think\Db;

/**
 * ProgressService — learner-side progress tracking (Phase 6, US3).
 *
 * Two aggregates per learner-course:
 *   - course_enrollments: progress_percent, last_lesson_id, last_position,
 *     completed_at (set once all enabled lessons are completed).
 *   - lesson_progresses: per-lesson opened_at, completed, completed_at,
 *     position_seconds (last playback head for video; ≥1 once a markdown
 *     or pdf lesson has been opened).
 *
 * Rules:
 *   1. Only authorized learners (active entitlement) can write progress.
 *      EntitlementService::viewerAuthorized is the single source of truth
 *      for that check; callers must pass a learner whose grant we just
 *      confirmed.
 *   2. completed flips 0 → 1 only (monotonic). Re-reporting a completed
 *      lesson is a no-op — never demote.
 *   3. progress_percent is recomputed from lesson_progresses.completed
 *      counts and the enabled-lesson total for the course. We never trust
 *      a client-supplied percent.
 *   4. Markdown/PDF require opened_at before completed=1 is accepted.
 *      Video lessons auto-complete once watched_seconds reaches 90% of
 *      the lesson's total duration.
 *   5. lesson_progresses.position_seconds is monotonically non-decreasing;
 *      we never rewind the head — clients that fall behind only advance
 *      forward when their reported position is greater.
 *
 *   // ponytail: progress_percent recomputation is a single SQL aggregate
 *   per write. The dataset is small enough (≤100 lessons / course) that
 *   we don't need a denormalised counter column. Add one if a course
 *   ever exceeds a few hundred lessons.
 */
final class ProgressService
{
    public function __construct(private readonly EntitlementService $entitlements)
    {
    }

    /**
     * Return the learner's durable course records together with the newest
     * entitlement state. Revoked free access remains visible so the learner
     * can understand why resume is blocked and rejoin without losing progress.
     *
     * @return array{items:list<array<string, mixed>>}
     */
    public function listLearning(int $learnerId): array
    {
        if ($learnerId <= 0) {
            throw new BusinessException('VALIDATION_FAILED', 'LEARNER_INVALID');
        }

        $enrollments = Db::name('course_enrollments')
            ->where('learner_id', $learnerId)
            ->select()
            ->toArray();
        $entitlements = Db::name('course_entitlements')
            ->where('learner_id', $learnerId)
            ->order('id', 'desc')
            ->select()
            ->toArray();

        $latestByCourse = [];
        foreach ($entitlements as $entitlement) {
            $courseId = (int) $entitlement['course_id'];
            if (!isset($latestByCourse[$courseId])) {
                $latestByCourse[$courseId] = $entitlement;
            }
        }

        $enrollmentByCourse = [];
        foreach ($enrollments as $enrollment) {
            $enrollmentByCourse[(int) $enrollment['course_id']] = $enrollment;
        }

        $courseIds = array_values(array_unique(array_merge(
            array_map('intval', array_keys($enrollmentByCourse)),
            array_map('intval', array_keys($latestByCourse)),
        )));
        if ($courseIds === []) {
            return ['items' => []];
        }

        $courses = Db::name('courses')->where('id', 'in', $courseIds)->select()->toArray();
        $courseById = [];
        foreach ($courses as $course) {
            $courseById[(int) $course['id']] = $course;
        }

        $items = [];
        foreach ($courseIds as $courseId) {
            $course = $courseById[$courseId] ?? null;
            $entitlement = $latestByCourse[$courseId] ?? null;
            if ($course === null || $entitlement === null) {
                continue;
            }
            $enrollment = $enrollmentByCourse[$courseId] ?? null;
            $entitlementStatus = (string) $entitlement['status'];
            $entitlementSource = (string) $entitlement['source'];
            $enrollmentUpdatedAt = (string) ($enrollment['updated_at'] ?? '');
            $entitlementUpdatedAt = (string) ($entitlement['updated_at'] ?? '');

            $items[] = [
                'course_id' => $courseId,
                'progress_percent' => (int) ($enrollment['progress_percent'] ?? 0),
                'last_lesson_id' => isset($enrollment['last_lesson_id']) && $enrollment['last_lesson_id'] !== null
                    ? (int) $enrollment['last_lesson_id']
                    : null,
                'last_position' => (int) ($enrollment['last_position'] ?? 0),
                'completed_at' => !empty($enrollment['completed_at'])
                    ? (string) $enrollment['completed_at']
                    : null,
                'updated_at' => max($enrollmentUpdatedAt, $entitlementUpdatedAt),
                'entitlement_status' => $entitlementStatus,
                'entitlement_source' => $entitlementSource,
                'revoked_at' => !empty($entitlement['revoked_at']) ? (string) $entitlement['revoked_at'] : null,
                'revoked_reason' => !empty($entitlement['revoked_reason'])
                    ? (string) $entitlement['revoked_reason']
                    : null,
                'can_rejoin' => $entitlementStatus === 'revoked'
                    && $entitlementSource === 'free'
                    && (string) $course['status'] === 'published'
                    && (string) $course['price_mode'] === 'free',
                'course' => [
                    'id' => $courseId,
                    'title' => (string) $course['title'],
                    'cover_url' => $course['cover_url'] ? (string) $course['cover_url'] : null,
                    'teacher_name' => (string) ($course['teacher_name'] ?? ''),
                    'status' => (string) $course['status'],
                    'price_mode' => (string) $course['price_mode'],
                ],
            ];
        }

        usort($items, static fn(array $left, array $right): int =>
            strcmp((string) $right['updated_at'], (string) $left['updated_at']));

        return ['items' => $items];
    }

    /**
     * Record an open / progress event for a single lesson. Used by
     *   - markdown/pdf: when the learner clicks the "open" link.
     *   - video: at every heartbeat (≤30s) with the new position.
     *
     * @param string $contentType 'markdown'|'pdf'|'video'
     * @param int $durationSeconds lesson duration; only used for video.
     * @param int $positionSeconds new playback head (or 1 for md/pdf open).
     * @param bool $clientComplete optional client hint; ignored for video
     *   (auto-completion is authoritative), and only accepted for md/pdf
     *   once the lesson has been opened.
     */
    /** @return array<string, mixed> */
    public function reportProgress(
        int $learnerId,
        int $lessonId,
        string $contentType,
        int $durationSeconds,
        int $positionSeconds,
        bool $clientComplete,
    ): array {
        $lesson = Db::name('lessons')->where('id', $lessonId)->find();
        if (!$lesson || (string) ($lesson['status'] ?? '') !== 'enabled') {
            throw new BusinessException('NOT_FOUND', 'LESSON_NOT_FOUND');
        }
        $chapter = Db::name('chapters')->where('id', (int) ($lesson['chapter_id'] ?? 0))->find();
        if (!$chapter) {
            throw new BusinessException('NOT_FOUND', 'LESSON_NOT_FOUND');
        }
        $courseId = (int) $chapter['course_id'];
        $actualContentType = (string) ($lesson['content_type'] ?? '');
        if ($actualContentType !== $contentType) {
            throw new BusinessException('VALIDATION_FAILED', 'LESSON_CONTENT_TYPE_MISMATCH');
        }
        $actualDurationSeconds = max(0, (int) ($lesson['duration_seconds'] ?? 0));

        if (!$this->entitlements->viewerAuthorized($courseId, $learnerId)) {
            throw new BusinessException('FORBIDDEN', 'LESSON_LOCKED');
        }

        $now = date('Y-m-d H:i:s');
        return Db::transaction(function () use ($learnerId, $lessonId, $courseId, $contentType, $actualDurationSeconds, $positionSeconds, $clientComplete, $now) {
            // Upsert the per-lesson row. We never mutate completed back
            // to false — see rule 2.
            $row = Db::name('lesson_progresses')
                ->where('learner_id', $learnerId)
                ->where('lesson_id', $lessonId)
                ->lock(true)
                ->find();

            $openedBefore = $row !== null && !empty($row['opened_at']);
            $completedBefore = $row && (int) ($row['completed'] ?? 0) === 1;

            $newPosition = $row
                ? max((int) $row['position_seconds'], max(0, $positionSeconds))
                : max(0, $positionSeconds);

            $markComplete = false;
            if ($contentType === 'video') {
                // Auto-complete once watched ≥ 90% of the lesson's
                // declared duration. A heartbeat that arrives past the
                // 90% mark closes the lesson out.
                if ($actualDurationSeconds > 0 && $newPosition >= (int) ceil($actualDurationSeconds * 0.9)) {
                    $markComplete = true;
                }
            } else {
                // markdown / pdf — completion requires an explicit open
                // (opened_at is set the first time the lesson is delivered
                // to the learner). The first progress report itself
                // counts as the open — once we've decided this is a
                // non-video lesson, we know opened_at will be set on
                // the row (either it already was, or the insert below
                // will populate it). Client may then complete on a
                // subsequent report.
                $effectiveOpened = $openedBefore;
                if (!$completedBefore && $clientComplete && $effectiveOpened) {
                    $markComplete = true;
                }
            }

            if (!$completedBefore && $markComplete) {
                Logger::info('progress.completed', [
                    'learner_id' => $learnerId,
                    'lesson_id'  => $lessonId,
                    'kind'       => $contentType,
                ]);
            }

            $patch = [
                'position_seconds' => $newPosition,
                'updated_at'       => $now,
            ];
            if (!$row) {
                $patch['learner_id'] = $learnerId;
                $patch['lesson_id']  = $lessonId;
                $patch['opened_at']  = $now;
                $patch['completed']  = $markComplete ? 1 : 0;
                $patch['completed_at'] = $markComplete ? $now : null;
                $patch['created_at'] = $now;
                Db::name('lesson_progresses')->insert($patch);
            } else {
                if (!$openedBefore) {
                    $patch['opened_at'] = $now;
                }
                if ($markComplete && !$completedBefore) {
                    $patch['completed']    = 1;
                    $patch['completed_at'] = $now;
                }
                Db::name('lesson_progresses')
                    ->where('id', (int) $row['id'])
                    ->update($patch);
            }

            // Update the aggregate enrollment row. We always touch
            // last_lesson_id and last_position so the resume banner on
            // /my/learning is fresh after every report.
            $this->refreshEnrollment($learnerId, $courseId, $lessonId, $newPosition);

            return $this->shapeProgress($learnerId, $lessonId);
        });
    }

    /**
     * Recompute the aggregate progress_percent and completed_at for a
     * single (learner, course) pair. Called after every per-lesson write.
     *
     * Pulls:
     *   - enabled lessons under the course (count)
     *   - completed lesson_progresses for the learner under those lessons
     */
    private function refreshEnrollment(int $learnerId, int $courseId, int $lastLessonId, int $lastPosition): void
    {
        $now = date('Y-m-d H:i:s');

        // Total enabled lessons under this course.
        $total = (int) Db::name('lessons')
            ->where('chapter_id', 'in', function ($q) use ($courseId) {
                $q->name('chapters')->where('course_id', $courseId)->field('id');
            })
            ->where('status', 'enabled')
            ->count();

        // Completed lesson_progresses by this learner for those lessons.
        $done = (int) Db::name('lesson_progresses')
            ->where('learner_id', $learnerId)
            ->where('completed', 1)
            ->where('lesson_id', 'in', function ($q) use ($courseId) {
                $q->name('lessons')->where('chapter_id', 'in', function ($qq) use ($courseId) {
                    $qq->name('chapters')->where('course_id', $courseId)->field('id');
                })->field('id');
            })
            ->count();

        $percent = $total > 0 ? (int) floor(min(100, ($done * 100) / $total)) : 0;
        $completedAt = ($total > 0 && $done >= $total) ? $now : null;

        $row = Db::name('course_enrollments')
            ->where('learner_id', $learnerId)
            ->where('course_id', $courseId)
            ->lock(true)
            ->find();

        $patch = [
            'progress_percent' => $percent,
            'last_lesson_id'   => $lastLessonId,
            'last_position'    => max(0, $lastPosition),
            'completed_at'     => $completedAt,
            'updated_at'       => $now,
        ];
        if (!$row) {
            $patch['learner_id'] = $learnerId;
            $patch['course_id']  = $courseId;
            $patch['created_at'] = $now;
            Db::name('course_enrollments')->insert($patch);
        } else {
            // Don't trample a previously-set completed_at with null on a
            // subsequent update — once you've finished, you've finished.
            if (!empty($row['completed_at']) && $completedAt === null) {
                $patch['completed_at'] = $row['completed_at'];
            }
            Db::name('course_enrollments')
                ->where('id', (int) $row['id'])
                ->update($patch);
        }
    }

    /**
     * Read the (learner, lesson) progress row, shaping for the API.
     */
    /** @return array<string, mixed> */
    public function shapeProgress(int $learnerId, int $lessonId): array
    {
        $row = Db::name('lesson_progresses')
            ->where('learner_id', $learnerId)
            ->where('lesson_id', $lessonId)
            ->find();
        if (!$row) {
            return [
                'lesson_id'        => $lessonId,
                'position_seconds' => 0,
                'completed'        => false,
                'completed_at'     => null,
                'opened_at'        => null,
            ];
        }
        return [
            'lesson_id'        => (int) $row['lesson_id'],
            'position_seconds' => (int) ($row['position_seconds'] ?? 0),
            'completed'        => (int) ($row['completed'] ?? 0) === 1,
            'completed_at'     => $row['completed_at'] ? (string) $row['completed_at'] : null,
            'opened_at'        => $row['opened_at'] ? (string) $row['opened_at'] : null,
        ];
    }
}
