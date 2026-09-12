<?php

declare(strict_types=1);

namespace App\service;

use App\support\Logger;
use App\support\ShanghaiTime;
use support\think\Db;

final class CourseStudentService
{
    public function __construct(
        private readonly DataScopeService $scope,
        private readonly EntitlementService $entitlements,
    ) {
    }

    /**
     * @param array<string,mixed> $filters
     * @return array{items:list<array<string,int|string|null>>,total:int,page:int,limit:int}
     */
    public function listForCourse(int $staffId, int $courseId, array $filters): array
    {
        $this->assertCourseAccessible($staffId, $courseId);
        $status = (string) ($filters['status'] ?? '');
        if ($status !== '' && !in_array($status, ['active', 'revoked'], true)) {
            throw new BusinessException('VALIDATION_FAILED', 'ENTITLEMENT_STATUS_INVALID');
        }
        $source = (string) ($filters['source'] ?? '');
        if ($source !== '' && !in_array($source, ['free', 'purchase', 'activation_code'], true)) {
            throw new BusinessException('VALIDATION_FAILED', 'ENTITLEMENT_SOURCE_INVALID');
        }
        $learningStatus = (string) ($filters['learning_status'] ?? '');
        if ($learningStatus !== '' && !in_array($learningStatus, ['not_started', 'in_progress', 'completed'], true)) {
            throw new BusinessException('VALIDATION_FAILED', 'LEARNING_STATUS_INVALID');
        }
        $page = max(1, (int) ($filters['page'] ?? 1));
        $limit = max(1, min(200, (int) ($filters['limit'] ?? 20)));
        $query = Db::name('course_entitlements')
            ->alias('ce')
            ->join('learners l', 'l.account_id = ce.learner_id')
            ->join('accounts a', 'a.id = ce.learner_id')
            ->leftJoin('course_enrollments e', 'e.learner_id = ce.learner_id AND e.course_id = ce.course_id')
            ->where('ce.course_id', $courseId)
            ->where('ce.id', 'in', function ($latest) use ($courseId): void {
                $latest->name('course_entitlements')
                    ->where('course_id', $courseId)
                    ->group('learner_id')
                    ->field('MAX(id)');
            })
            ->field('a.id AS account_id, a.login, a.status AS account_status, a.last_login_at, l.nickname, ce.source, ce.status AS entitlement_status, ce.created_at AS enrolled_at, ce.revoked_at, ce.revoked_reason, e.progress_percent, e.completed_at, e.last_lesson_id, e.updated_at AS enrollment_updated_at');
        if ($status !== '') {
            $query->where('ce.status', $status);
        }
        if ($source !== '') {
            $query->where('ce.source', $source);
        }
        if ($learningStatus === 'not_started') {
            $query->whereRaw('(e.id IS NULL OR e.progress_percent = 0)');
        } elseif ($learningStatus === 'in_progress') {
            $query->whereRaw('e.progress_percent > 0 AND e.progress_percent < 100 AND e.completed_at IS NULL');
        } elseif ($learningStatus === 'completed') {
            $query->whereRaw('(e.completed_at IS NOT NULL OR e.progress_percent >= 100)');
        }
        $total = (int) (clone $query)->count();
        $rows = $query->order('ce.id', 'desc')->page($page, $limit)->select()->toArray();

        return [
            'items' => array_map([$this, 'shapeItem'], is_array($rows) ? $rows : []),
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
        ];
    }

    /**
     * @param array<string,mixed> $filters
     * @return array{
     *   items: list<array<string,mixed>>,
     *   total: int,
     *   page: int,
     *   limit: int,
     *   policy: array{idle_threshold_hours:int,reminder_frequency_hours:int,reminder_cap:int}
     * }
     */
    public function listStartQueue(int $staffId, int $courseId, array $filters): array
    {
        $course = $this->assertCourseAccessible($staffId, $courseId);
        $policy = [
            'idle_threshold_hours' => (int) ($course['idle_threshold_hours'] ?? CourseService::DEFAULT_IDLE_THRESHOLD_HOURS),
            'reminder_frequency_hours' => (int) ($course['reminder_frequency_hours'] ?? CourseService::DEFAULT_REMINDER_FREQUENCY_HOURS),
            'reminder_cap' => (int) ($course['reminder_cap'] ?? CourseService::DEFAULT_REMINDER_CAP),
        ];
        $page = max(1, (int) ($filters['page'] ?? 1));
        $limit = max(1, min(200, (int) ($filters['limit'] ?? 20)));
        $empty = [
            'items' => [],
            'total' => 0,
            'page' => $page,
            'limit' => $limit,
            'policy' => $policy,
        ];
        if ($this->enabledLessonCount($courseId) === 0) {
            return $empty;
        }

        $source = (string) ($filters['source'] ?? '');
        if ($source !== '' && !in_array($source, ['free', 'purchase', 'activation_code'], true)) {
            throw new BusinessException('VALIDATION_FAILED', 'ENTITLEMENT_SOURCE_INVALID');
        }
        $startupState = (string) ($filters['startup_state'] ?? '');
        if ($startupState !== '' && !in_array($startupState, ['never_opened', 'opened_zero_progress'], true)) {
            throw new BusinessException('VALIDATION_FAILED', 'STARTUP_STATE_INVALID');
        }
        $sort = (string) ($filters['sort'] ?? 'idle_hours');
        if (!in_array($sort, ['idle_hours', 'entitled_at', 'source'], true)) {
            throw new BusinessException('VALIDATION_FAILED', 'STARTUP_SORT_INVALID');
        }
        $order = strtolower((string) ($filters['order'] ?? 'desc')) === 'asc' ? 'asc' : 'desc';

        $openedExists = '(EXISTS (SELECT 1 FROM lesson_progresses lp '
            . 'INNER JOIN lessons l ON l.id = lp.lesson_id '
            . 'INNER JOIN chapters ch ON ch.id = l.chapter_id '
            . 'WHERE ch.course_id = ce.course_id AND lp.learner_id = ce.learner_id '
            . 'AND (lp.opened_at IS NOT NULL OR lp.created_at IS NOT NULL)))';
        $validProgressExists = '(EXISTS (SELECT 1 FROM lesson_progresses lp '
            . 'INNER JOIN lessons l ON l.id = lp.lesson_id '
            . 'INNER JOIN chapters ch ON ch.id = l.chapter_id '
            . 'WHERE ch.course_id = ce.course_id AND lp.learner_id = ce.learner_id '
            . 'AND lp.completed = 1))';
        $cutoff = ShanghaiTime::now()
            ->modify('-' . $policy['idle_threshold_hours'] . ' hours')
            ->format(ShanghaiTime::DATETIME);
        $query = Db::name('course_entitlements')
            ->alias('ce')
            ->join('learners lnr', 'lnr.account_id = ce.learner_id')
            ->join('accounts a', 'a.id = ce.learner_id')
            ->leftJoin('course_enrollments e', 'e.learner_id = ce.learner_id AND e.course_id = ce.course_id')
            ->where('ce.course_id', $courseId)
            ->where('ce.status', 'active')
            ->where('ce.id', 'in', function ($latest) use ($courseId): void {
                $latest->name('course_entitlements')
                    ->where('course_id', $courseId)
                    ->group('learner_id')
                    ->field('MAX(id)');
            })
            ->whereRaw('(e.id IS NULL OR (e.progress_percent = 0 AND e.completed_at IS NULL))')
            ->whereRaw('NOT ' . $validProgressExists)
            ->where('ce.created_at', '<=', $cutoff)
            ->field(
                'a.id AS account_id, a.login, a.status AS account_status, '
                . 'lnr.nickname, ce.source, ce.status AS entitlement_status, ce.created_at AS entitled_at, '
                . 'e.progress_percent, e.completed_at, e.last_lesson_id, e.updated_at AS enrollment_updated_at, '
                . $openedExists . ' AS has_opened, '
                . '(SELECT COUNT(*) FROM notification_dispatch_recipients r '
                . 'INNER JOIN notification_dispatches d ON d.id = r.dispatch_id '
                . 'WHERE r.learner_id = ce.learner_id AND d.type = \'learning_reminder\' '
                . 'AND d.resource_type = \'course\' AND d.resource_id = ce.course_id) AS reminder_count, '
                . '(SELECT MAX(d2.created_at) FROM notification_dispatch_recipients r2 '
                . 'INNER JOIN notification_dispatches d2 ON d2.id = r2.dispatch_id '
                . 'WHERE r2.learner_id = ce.learner_id AND d2.type = \'learning_reminder\' '
                . 'AND d2.resource_type = \'course\' AND d2.resource_id = ce.course_id) AS last_reminded_at',
            );
        if ($source !== '') {
            $query->where('ce.source', $source);
        }
        if ($startupState === 'never_opened') {
            $query->whereRaw('NOT ' . $openedExists);
        } elseif ($startupState === 'opened_zero_progress') {
            $query->whereRaw($openedExists);
        }
        $total = (int) (clone $query)->count();
        $orderMap = [
            'idle_hours' => 'ce.created_at',
            'entitled_at' => 'ce.created_at',
            'source' => 'ce.source',
        ];
        $sqlOrder = $sort === 'idle_hours'
            ? ($order === 'desc' ? 'asc' : 'desc')
            : $order;
        $rows = $query->order($orderMap[$sort], $sqlOrder)->order('ce.id', 'desc')->page($page, $limit)->select()->toArray();

        return [
            'items' => array_map(
                fn (array $row): array => $this->shapeQueueItem($row, $policy),
                is_array($rows) ? $rows : [],
            ),
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
            'policy' => $policy,
        ];
    }

    /**
     * @param list<int> $learnerIds
     * @return array{
     *   sent_count:int,
     *   blocked_count:int,
     *   dispatch_id:int|null,
     *   outcomes:list<array{account_id:int,sent:bool,blocked_reason:?string}>
     * }
     */
    public function sendStartReminders(int $staffId, int $courseId, array $learnerIds): array
    {
        $course = $this->assertCourseAccessible($staffId, $courseId);
        $unique = [];
        foreach ($learnerIds as $id) {
            $id = (int) $id;
            if ($id > 0) {
                $unique[$id] = $id;
            }
        }
        if ($unique === []) {
            throw new BusinessException('VALIDATION_FAILED', 'INVALID_RECIPIENTS');
        }

        return Db::transaction(function () use ($staffId, $courseId, $course, $unique): array {
            $policy = [
                'idle_threshold_hours' => (int) ($course['idle_threshold_hours'] ?? CourseService::DEFAULT_IDLE_THRESHOLD_HOURS),
                'reminder_frequency_hours' => (int) ($course['reminder_frequency_hours'] ?? CourseService::DEFAULT_REMINDER_FREQUENCY_HOURS),
                'reminder_cap' => (int) ($course['reminder_cap'] ?? CourseService::DEFAULT_REMINDER_CAP),
            ];
            $outcomes = [];
            $eligible = [];
            foreach ($unique as $learnerId) {
                $reason = $this->evaluateReminderEligibility($courseId, $learnerId, $policy, true);
                if ($reason === null) {
                    $eligible[] = $learnerId;
                    $outcomes[] = [
                        'account_id' => $learnerId,
                        'sent' => true,
                        'blocked_reason' => null,
                    ];
                } else {
                    $outcomes[] = [
                        'account_id' => $learnerId,
                        'sent' => false,
                        'blocked_reason' => $reason,
                    ];
                }
            }
            $dispatchId = null;
            if ($eligible !== []) {
                $title = '开始学习「' . (string) ($course['title'] ?? '课程') . '」';
                $body = '你的课程访问权已经生效。点击开始学习进入本课程的学习入口。提醒本身不是学习行为，也不会改变访问权或进度。';
                $dispatchId = (new NotificationDispatchService())->sendCourseStartReminder(
                    $staffId,
                    $title,
                    $body,
                    $eligible,
                    $courseId,
                );
            }
            $sentCount = count($eligible);
            return [
                'sent_count' => $sentCount,
                'blocked_count' => count($outcomes) - $sentCount,
                'dispatch_id' => $dispatchId,
                'outcomes' => $outcomes,
            ];
        });
    }

    /**
     * @param array{idle_threshold_hours:int,reminder_frequency_hours:int,reminder_cap:int} $policy
     */
    private function evaluateReminderEligibility(int $courseId, int $learnerId, array $policy, bool $lock = false): ?string
    {
        if ($this->enabledLessonCount($courseId) === 0) {
            return 'no_effective_lesson';
        }
        $entitlementQuery = Db::name('course_entitlements')
            ->where('course_id', $courseId)
            ->where('learner_id', $learnerId)
            ->order('id', 'desc');
        if ($lock) {
            $entitlementQuery->lock(true);
        }
        $entitlement = $entitlementQuery->find();
        if (!$entitlement || (string) $entitlement['status'] !== 'active') {
            return 'no_active_entitlement';
        }
        try {
            $grantedAt = ShanghaiTime::timestamp((string) $entitlement['created_at']);
        } catch (\InvalidArgumentException) {
            return 'not_eligible';
        }
        $idleHours = (int) floor((ShanghaiTime::now()->getTimestamp() - $grantedAt) / 3600);
        if ($idleHours < $policy['idle_threshold_hours']) {
            return 'below_threshold';
        }
        if ($this->hasValidLessonProgress($courseId, $learnerId)) {
            return 'started';
        }
        $enrollment = Db::name('course_enrollments')
            ->where('course_id', $courseId)
            ->where('learner_id', $learnerId)
            ->find();
        if (is_array($enrollment)) {
            $progress = (int) ($enrollment['progress_percent'] ?? 0);
            if ($enrollment['completed_at'] !== null || $progress >= 100) {
                return 'completed';
            }
            if ($progress > 0) {
                return 'started';
            }
        }
        $historyQuery = Db::name('notification_dispatch_recipients')
            ->alias('r')
            ->join('notification_dispatches d', 'd.id = r.dispatch_id')
            ->where('r.learner_id', $learnerId)
            ->where('d.type', NotificationDispatchService::TYPE_LEARNING_REMINDER)
            ->where('d.resource_type', 'course')
            ->where('d.resource_id', $courseId);
        if ($lock) {
            $historyQuery->lock(true);
        }
        $latestReminder = (clone $historyQuery)->order('d.id', 'desc')->field('d.created_at')->find();
        $reminderCount = (int) (clone $historyQuery)->count();
        $lastReminded = is_array($latestReminder) && ($latestReminder['created_at'] ?? null) !== null
            ? (string) $latestReminder['created_at']
            : null;
        return $this->queueReminderBlockReason($reminderCount, $lastReminded, $policy);
    }

    /** @return array{revoked:true} */
    public function revokeFree(int $staffId, int $courseId, int $learnerId, string $reason): array
    {
        $this->assertCourseAccessible($staffId, $courseId);
        $reason = trim($reason);
        if ($reason === '') {
            throw new BusinessException('VALIDATION_FAILED', 'REVOKE_REASON_REQUIRED');
        }
        if (mb_strlen($reason) > 255) {
            throw new BusinessException('VALIDATION_FAILED', 'REASON_TOO_LONG');
        }
        $row = $this->entitlements->revoke($learnerId, $courseId, $reason, $staffId);
        if ($row === null) {
            throw new BusinessException('NOT_FOUND', 'NO_ACTIVE_ENTITLEMENT');
        }
        Db::name('audit_log')->insert([
            'actor_id' => $staffId,
            'action' => 'course_student.revoke_free',
            'target_type' => 'course_entitlement',
            'target_id' => (int) $row['id'],
            'payload_json' => json_encode([
                'course_id' => $courseId,
                'learner_id' => $learnerId,
                'reason' => $reason,
            ], JSON_UNESCAPED_UNICODE),
            'created_at' => date('Y-m-d H:i:s'),
        ]);
        Logger::info('course_student.revoked', [
            'actor_id' => $staffId,
            'course_id' => $courseId,
            'learner_id' => $learnerId,
        ]);
        return ['revoked' => true];
    }

    /** @return array{reset:true} */
    public function resetProgress(int $staffId, int $courseId, int $learnerId): array
    {
        $course = $this->assertCourseAccessible($staffId, $courseId);
        $enrollment = Db::name('course_enrollments')
            ->where('course_id', $courseId)
            ->where('learner_id', $learnerId)
            ->find();
        if (!$enrollment) {
            throw new BusinessException('NOT_FOUND', 'COURSE_ENROLLMENT_NOT_FOUND');
        }

        Db::transaction(function () use ($staffId, $courseId, $learnerId, $course, $enrollment): void {
            Db::name('lesson_progresses')
                ->where('learner_id', $learnerId)
                ->where('lesson_id', 'in', function ($query) use ($courseId): void {
                    $query->name('lessons')
                        ->where('chapter_id', 'in', function ($chapters) use ($courseId): void {
                            $chapters->name('chapters')->where('course_id', $courseId)->field('id');
                        })
                        ->field('id');
                })
                ->delete();
            $now = date('Y-m-d H:i:s');
            Db::name('course_enrollments')->where('id', (int) $enrollment['id'])->update([
                'progress_percent' => 0,
                'last_lesson_id' => null,
                'last_position' => 0,
                'completed_at' => null,
                'updated_at' => $now,
            ]);
            $auditId = (int) Db::name('audit_log')->insertGetId([
                'actor_id' => $staffId,
                'action' => 'course_student.progress_reset',
                'target_type' => 'course_enrollment',
                'target_id' => (int) $enrollment['id'],
                'payload_json' => json_encode([
                    'course_id' => $courseId,
                    'learner_id' => $learnerId,
                ], JSON_UNESCAPED_UNICODE),
                'created_at' => $now,
            ]);
            (new MessageService())->emit(
                MessageService::KIND_PROGRESS_RESET,
                $learnerId,
                '课程学习进度已重置',
                '「' . (string) ($course['title'] ?? '课程') . '」的学习进度已由管理员重置。',
                ['course_id' => $courseId],
                'course',
                $courseId,
                'progress_reset:' . $auditId,
            );
        });
        Logger::info('course_student.progress_reset', [
            'actor_id' => $staffId,
            'course_id' => $courseId,
            'learner_id' => $learnerId,
        ]);
        return ['reset' => true];
    }

    /** @return array<string,mixed> */
    private function assertCourseAccessible(int $staffId, int $courseId): array
    {
        $course = Db::name('courses')
            ->where('id', $courseId)
            ->field('id, title, department_id, created_by_staff_id, idle_threshold_hours, reminder_frequency_hours, reminder_cap')
            ->find();
        if (!$course) {
            throw new BusinessException('NOT_FOUND', 'COURSE_NOT_FOUND');
        }
        DataScopeService::assertCourseAccessibleFromScope(
            $this->scope->resolveForCourses($staffId),
            (int) $course['department_id'],
            (int) $course['created_by_staff_id'],
            $staffId,
        );
        return $course;
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,int|string|null>
     */
    private function shapeItem(array $row): array
    {
        $nickname = trim((string) ($row['nickname'] ?? ''));
        $progress = (int) ($row['progress_percent'] ?? 0);
        $learningStatus = $row['completed_at'] !== null || $progress >= 100
            ? 'completed'
            : ($progress > 0 ? 'in_progress' : 'not_started');
        return [
            'account_id' => (int) $row['account_id'],
            'login' => (string) $row['login'],
            'nickname' => $nickname !== '' ? $nickname : '匿名学员',
            'account_status' => (string) $row['account_status'],
            'source' => (string) $row['source'],
            'entitlement_status' => (string) $row['entitlement_status'],
            'progress_percent' => $progress,
            'learning_status' => $learningStatus,
            'last_learning_at' => $row['last_lesson_id'] !== null && $row['enrollment_updated_at'] !== null
                ? (string) $row['enrollment_updated_at']
                : null,
            'completed_at' => $row['completed_at'] !== null ? (string) $row['completed_at'] : null,
            'enrolled_at' => (string) $row['enrolled_at'],
            'revoked_at' => $row['revoked_at'] !== null ? (string) $row['revoked_at'] : null,
            'revoked_reason' => $row['revoked_reason'] !== null ? (string) $row['revoked_reason'] : null,
            'last_login_at' => $row['last_login_at'] !== null ? (string) $row['last_login_at'] : null,
        ];
    }

    /**
     * @param array<string,mixed> $row
     * @param array{idle_threshold_hours:int,reminder_frequency_hours:int,reminder_cap:int} $policy
     * @return array<string,mixed>
     */
    private function shapeQueueItem(array $row, array $policy): array
    {
        $nickname = trim((string) ($row['nickname'] ?? ''));
        $opened = (int) ($row['has_opened'] ?? 0) === 1;
        $reminderCount = (int) ($row['reminder_count'] ?? 0);
        $lastRemindedAt = $row['last_reminded_at'] !== null ? (string) $row['last_reminded_at'] : null;
        $blocked = $this->queueReminderBlockReason($reminderCount, $lastRemindedAt, $policy);
        return [
            'account_id' => (int) $row['account_id'],
            'login' => (string) $row['login'],
            'nickname' => $nickname !== '' ? $nickname : '匿名学员',
            'account_status' => (string) $row['account_status'],
            'source' => (string) $row['source'],
            'entitlement_status' => (string) $row['entitlement_status'],
            'progress_percent' => (int) ($row['progress_percent'] ?? 0),
            'startup_state' => $opened ? 'opened_zero_progress' : 'never_opened',
            'idle_hours' => $this->idleHoursFrom((string) $row['entitled_at']),
            'entitled_at' => (string) $row['entitled_at'],
            'last_learning_at' => $row['last_lesson_id'] !== null && $row['enrollment_updated_at'] !== null
                ? (string) $row['enrollment_updated_at']
                : null,
            'reminder_count' => $reminderCount,
            'last_reminded_at' => $lastRemindedAt,
            'can_remind' => $blocked === null,
            'reminder_blocked_reason' => $blocked,
        ];
    }

    /**
     * @param array{idle_threshold_hours:int,reminder_frequency_hours:int,reminder_cap:int} $policy
     */
    private function queueReminderBlockReason(int $reminderCount, ?string $lastRemindedAt, array $policy): ?string
    {
        if ($reminderCount >= $policy['reminder_cap']) {
            return 'cap';
        }
        if ($lastRemindedAt === null) {
            return null;
        }
        try {
            $elapsedHours = (int) floor(
                (ShanghaiTime::now()->getTimestamp() - ShanghaiTime::timestamp($lastRemindedAt)) / 3600,
            );
        } catch (\InvalidArgumentException) {
            return null;
        }
        if ($elapsedHours < $policy['reminder_frequency_hours']) {
            return 'frequency';
        }
        return null;
    }

    private function enabledLessonCount(int $courseId): int
    {
        return (int) Db::name('lessons')->alias('l')
            ->join('chapters c', 'c.id = l.chapter_id')
            ->where('c.course_id', $courseId)
            ->where('l.status', 'enabled')
            ->count();
    }

    private function hasValidLessonProgress(int $courseId, int $learnerId): bool
    {
        return (int) Db::name('lesson_progresses')->alias('lp')
            ->join('lessons l', 'l.id = lp.lesson_id')
            ->join('chapters c', 'c.id = l.chapter_id')
            ->where('c.course_id', $courseId)
            ->where('lp.learner_id', $learnerId)
            ->where('lp.completed', 1)
            ->count() > 0;
    }

    private function idleHoursFrom(string $entitledAt): int
    {
        try {
            $elapsed = ShanghaiTime::now()->getTimestamp() - ShanghaiTime::timestamp($entitledAt);
        } catch (\InvalidArgumentException) {
            return 0;
        }
        return max(0, (int) floor($elapsed / 3600));
    }
}
