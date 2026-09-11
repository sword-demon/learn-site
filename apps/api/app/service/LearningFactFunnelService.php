<?php

declare(strict_types=1);

namespace App\service;

use DateTimeImmutable;
use DateTimeZone;
use support\think\Db;

use function toIso8601;

final class LearningFactFunnelService
{
    private const TZ = 'Asia/Shanghai';
    private const WINDOWS = [7, 30, 90];
    private const SOURCES = ['all', 'free', 'purchase', 'activation_code'];
    private const DISCLAIMER = '本报告展示事实转化，不表示增量效果，也不把订单成功或券已使用当成学习完成。';
    private const STAGE_LABELS = [
        'entitled' => '访问权生效',
        'first_opened' => '首次打开课节',
        'valid_progress' => '产生有效进度',
        'completed' => '完成课程',
    ];

    public function __construct(private readonly DataScopeService $scope = new DataScopeService())
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function show(int $staffId, int $courseId, int $windowDays, string $source): array
    {
        if (!in_array($windowDays, self::WINDOWS, true)) {
            throw new BusinessException('VALIDATION_FAILED', 'INVALID_WINDOW');
        }
        if (!in_array($source, self::SOURCES, true)) {
            throw new BusinessException('VALIDATION_FAILED', 'INVALID_SOURCE');
        }
        $this->assertCourseAccessible($staffId, $courseId);

        $now = new DateTimeImmutable('now', new DateTimeZone(self::TZ));
        $lookbackStart = $now->modify('-' . $windowDays . ' days');
        $cohort = $this->cohort($courseId, $source);
        $allGrants = $this->cohort($courseId, 'all');
        $noEffective = $this->enabledLessonCount($courseId) === 0;
        $progressByLearner = $this->progressByLearner($courseId);
        $completedByLearner = $this->courseCompletedAt($courseId);

        $entitled = 0;
        $firstOpened = 0;
        $validProgress = 0;
        $completed = 0;
        $inWindow = 0;
        $elapsed = 0;

        foreach ($cohort as $learnerId => $grant) {
            $entitled++;
            $grantedAt = $this->parseTime((string) $grant['granted_at']);
            $windowEnd = $grantedAt->modify('+' . $windowDays . ' days');
            $revokedAt = $grant['revoked_at'] !== null ? $this->parseTime($grant['revoked_at']) : null;
            $progress = $progressByLearner[$learnerId] ?? ['opens' => [], 'completes' => [], 'preview_open' => null];
            $openedAt = $this->firstInWindow($progress['opens'], $grantedAt, $windowEnd, $revokedAt);
            $progressAt = $this->firstInWindow($progress['completes'], $grantedAt, $windowEnd, $revokedAt);
            $courseDoneAt = $completedByLearner[$learnerId] ?? null;
            $openedInWindow = $openedAt !== null;
            $progressInWindow = $openedInWindow && $progressAt !== null;
            $completedInWindow = $progressInWindow
                && $courseDoneAt !== null
                && $this->inWindow($courseDoneAt, $grantedAt, $windowEnd, $revokedAt);

            if ($openedInWindow) {
                $firstOpened++;
            } elseif ($now < $windowEnd) {
                $inWindow++;
            } else {
                $elapsed++;
            }
            if ($progressInWindow) {
                $validProgress++;
            }
            if ($completedInWindow) {
                $completed++;
            }
        }

        $stages = [
            $this->stage('entitled', $entitled, $entitled, $entitled),
            $this->stage('first_opened', $firstOpened, $entitled, $entitled),
            $this->stage('valid_progress', $validProgress, $entitled, $firstOpened),
            $this->stage('completed', $completed, $entitled, $validProgress),
        ];

        $activeEntitled = (int) Db::name('course_entitlements')
            ->where('course_id', $courseId)
            ->where('status', 'active')
            ->count();

        return [
            'course_id' => $courseId,
            'window_days' => $windowDays,
            'source' => $source,
            'generated_at' => toIso8601(time()) ?? $now->format(DateTimeImmutable::ATOM),
            'disclaimer' => self::DISCLAIMER,
            'no_effective_lesson' => $noEffective,
            'stages' => $stages,
            'pending' => [
                'in_window' => $inWindow,
                'in_window_label' => '窗口进行中、尚未开始',
                'window_elapsed' => $elapsed,
                'window_elapsed_label' => '窗口内未转化',
            ],
            'trial' => [
                'learners' => $this->trialLearners($allGrants, $progressByLearner),
                'note' => '未取得课程访问权时打开试看课节的登录学员，不含访客曝光',
            ],
            'orders' => $this->orderContrast($courseId, $lookbackStart),
            'publish_reach' => $this->publishReach($courseId, $activeEntitled, $lookbackStart),
        ];
    }

    private function assertCourseAccessible(int $staffId, int $courseId): void
    {
        $course = Db::name('courses')
            ->where('id', $courseId)
            ->field('id, department_id, created_by_staff_id')
            ->find();
        if (!$course) {
            throw new BusinessException('NOT_FOUND', 'COURSE_NOT_FOUND');
        }
        DataScopeService::assertCourseAccessibleFromScope(
            $this->scope->resolveForCourses($staffId),
            (int) ($course['department_id'] ?? 0),
            (int) ($course['created_by_staff_id'] ?? 0),
            $staffId,
        );
    }

    /**
     * @return array<int, array{granted_at: string, source: string, revoked_at: ?string}>
     */
    private function cohort(int $courseId, string $source): array
    {
        $query = Db::name('course_entitlements')
            ->where('course_id', $courseId)
            ->order('id', 'asc');
        if ($source !== 'all') {
            $query->where('source', $source);
        }
        $rows = $query->select();
        $cohort = [];
        foreach ($rows as $row) {
            $learnerId = (int) $row['learner_id'];
            if (isset($cohort[$learnerId])) {
                continue;
            }
            $cohort[$learnerId] = [
                'granted_at' => (string) $row['created_at'],
                'source' => (string) $row['source'],
                'revoked_at' => isset($row['revoked_at']) && $row['revoked_at'] !== ''
                    ? (string) $row['revoked_at']
                    : null,
            ];
        }
        return $cohort;
    }

    private function enabledLessonCount(int $courseId): int
    {
        return (int) Db::name('lessons')->alias('l')
            ->join('chapters c', 'c.id = l.chapter_id')
            ->where('c.course_id', $courseId)
            ->where('l.status', 'enabled')
            ->count();
    }

    /**
     * @return array<int, array{opens: list<DateTimeImmutable>, completes: list<DateTimeImmutable>, preview_open: ?DateTimeImmutable}>
     */
    private function progressByLearner(int $courseId): array
    {
        $rows = Db::name('lesson_progresses')->alias('lp')
            ->join('lessons l', 'l.id = lp.lesson_id')
            ->join('chapters c', 'c.id = l.chapter_id')
            ->where('c.course_id', $courseId)
            ->field('lp.learner_id, lp.opened_at, lp.created_at, lp.completed, lp.completed_at, l.is_preview')
            ->select();
        $byLearner = [];
        foreach ($rows as $row) {
            $learnerId = (int) $row['learner_id'];
            if (!isset($byLearner[$learnerId])) {
                $byLearner[$learnerId] = [
                    'opens' => [],
                    'completes' => [],
                    'preview_open' => null,
                ];
            }
            $openRaw = (string) ($row['opened_at'] ?: $row['created_at']);
            if ($openRaw !== '') {
                $openAt = $this->parseTime($openRaw);
                $byLearner[$learnerId]['opens'][] = $openAt;
                if ((int) ($row['is_preview'] ?? 0) === 1) {
                    $preview = $byLearner[$learnerId]['preview_open'];
                    if ($preview === null || $openAt < $preview) {
                        $byLearner[$learnerId]['preview_open'] = $openAt;
                    }
                }
            }
            if ((int) ($row['completed'] ?? 0) === 1 && !empty($row['completed_at'])) {
                $byLearner[$learnerId]['completes'][] = $this->parseTime((string) $row['completed_at']);
            }
        }
        return $byLearner;
    }

    /**
     * @return array<int, DateTimeImmutable>
     */
    private function courseCompletedAt(int $courseId): array
    {
        $rows = Db::name('course_enrollments')
            ->where('course_id', $courseId)
            ->whereNotNull('completed_at')
            ->field('learner_id, completed_at')
            ->select();
        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row['learner_id']] = $this->parseTime((string) $row['completed_at']);
        }
        return $out;
    }

    /**
     * @param array<int, array{granted_at: string, source: string, revoked_at: ?string}> $allGrants
     * @param array<int, array{opens: list<DateTimeImmutable>, completes: list<DateTimeImmutable>, preview_open: ?DateTimeImmutable}> $progressByLearner
     */
    private function trialLearners(array $allGrants, array $progressByLearner): int
    {
        $count = 0;
        foreach ($progressByLearner as $learnerId => $progress) {
            $previewOpen = $progress['preview_open'];
            if ($previewOpen === null) {
                continue;
            }
            if (!isset($allGrants[$learnerId])) {
                $count++;
                continue;
            }
            $grantedAt = $this->parseTime((string) $allGrants[$learnerId]['granted_at']);
            if ($previewOpen < $grantedAt) {
                $count++;
            }
        }
        return $count;
    }

    /** @return array<string, mixed> */
    private function orderContrast(int $courseId, DateTimeImmutable $lookbackStart): array
    {
        $rows = Db::name('orders')->where('course_id', $courseId)->select();
        $counts = [];
        foreach ($rows as $row) {
            $status = (string) $row['status'];
            $raw = $status === 'succeeded'
                ? (string) ($row['succeeded_at'] ?: $row['created_at'])
                : (string) $row['created_at'];
            if ($raw === '' || $this->parseTime($raw) < $lookbackStart) {
                continue;
            }
            $counts[$status] = ($counts[$status] ?? 0) + 1;
        }
        $buckets = [];
        foreach ($counts as $status => $count) {
            $buckets[] = ['status' => $status, 'count' => $count];
        }
        return [
            'succeeded' => $counts['succeeded'] ?? 0,
            'buckets' => $buckets,
            'note' => '订单成功不是完成课程',
        ];
    }

    /** @return array<string, mixed> */
    private function publishReach(int $courseId, int $entitledCount, DateTimeImmutable $lookbackStart): array
    {
        $rows = Db::name('notification_dispatches')
            ->where('type', 'course_published')
            ->where('resource_id', $courseId)
            ->where('created_at', '>=', $lookbackStart->format('Y-m-d H:i:s'))
            ->field('COUNT(*) AS dispatch_count, COALESCE(SUM(recipient_count), 0) AS recipient_count')
            ->find();
        return [
            'dispatch_count' => is_array($rows) ? (int) ($rows['dispatch_count'] ?? 0) : 0,
            'recipient_count' => is_array($rows) ? (int) ($rows['recipient_count'] ?? 0) : 0,
            'entitled_count' => $entitledCount,
            'note' => '发布触达与已有访问权人数分开，触达不是首次打开课节',
        ];
    }

    /**
     * @return array{id: string, label: string, count: int, of_cohort_rate: float|null, of_previous_rate: float|null}
     */
    private function stage(string $id, int $count, int $cohort, int $previous): array
    {
        return [
            'id' => $id,
            'label' => self::STAGE_LABELS[$id],
            'count' => $count,
            'of_cohort_rate' => $cohort > 0 ? round($count / $cohort, 4) : null,
            'of_previous_rate' => $previous > 0 ? round($count / $previous, 4) : null,
        ];
    }

    /**
     * @param list<DateTimeImmutable> $times
     */
    private function firstInWindow(
        array $times,
        DateTimeImmutable $grantedAt,
        DateTimeImmutable $windowEnd,
        ?DateTimeImmutable $revokedAt,
    ): ?DateTimeImmutable {
        $first = null;
        foreach ($times as $time) {
            if (!$this->inWindow($time, $grantedAt, $windowEnd, $revokedAt)) {
                continue;
            }
            if ($first === null || $time < $first) {
                $first = $time;
            }
        }
        return $first;
    }

    private function inWindow(
        DateTimeImmutable $time,
        DateTimeImmutable $grantedAt,
        DateTimeImmutable $windowEnd,
        ?DateTimeImmutable $revokedAt,
    ): bool {
        if ($time < $grantedAt || $time >= $windowEnd) {
            return false;
        }
        if ($revokedAt !== null && $time >= $revokedAt) {
            return false;
        }
        return true;
    }

    private function parseTime(string $value): DateTimeImmutable
    {
        return new DateTimeImmutable($value, new DateTimeZone(self::TZ));
    }
}
