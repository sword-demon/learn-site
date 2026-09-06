<?php
declare(strict_types=1);

namespace App\service;

use App\support\Logger;
use DateTimeImmutable;
use DateTimeZone;
use support\think\Db;
use think\db\Query;

/** Aggregates operational exceptions without persisting a duplicate inbox. */
final class OpsInboxService
{
    private const TIMEZONE = 'Asia/Shanghai';
    private const SOURCE_PERMISSIONS = [
        'course_unpublished' => 'course.view',
        'map_anomaly' => 'map.view',
        'question_pending' => 'qa.view',
        'feedback_pending' => 'review.view',
        'payment_unknown' => 'order.view',
        'queue_failed' => 'notification.manage',
        'long_pending' => '*',
    ];

    // Weights reflect direct business loss: payment and delivery failures lead.
    private const SOURCE_WEIGHTS = [
        'payment_unknown' => 100,
        'queue_failed' => 80,
        'question_pending' => 60,
        'course_unpublished' => 40,
        'map_anomaly' => 30,
        'feedback_pending' => 20,
        'long_pending' => 10,
    ];

    private const SOURCE_ACTIONS = [
        'course_unpublished' => '审核发布课程',
        'map_anomaly' => '修复学习地图课程',
        'question_pending' => '回复学员问题',
        'feedback_pending' => '处理课程反馈',
        'payment_unknown' => '核对支付回调并处理订单',
        'queue_failed' => '手动重发或转人工',
        'long_pending' => '检查并处理长期积压事项',
    ];

    private const ALLOWED_TRANSITIONS = [
        'open' => ['snoozed', 'resolved', 'assigned', 'retrying'],
        'snoozed' => ['open', 'resolved', 'assigned', 'retrying'],
        'assigned' => ['open', 'resolved', 'retrying'],
        'retrying' => ['open', 'resolved', 'assigned'],
        'resolved' => [],
    ];

    private const RETRY_MAX_ATTEMPTS = 3;
    private const RETRY_INITIAL_BACKOFF_SECONDS = 60;
    private const RETRY_BACKOFF_MULTIPLIER = 2;
    private const RETRY_MAX_BACKOFF_SECONDS = 1800;
    private const LONG_PENDING_HOURS = 72;
    private const MAX_PAGE_LIMIT = 50;

    // Conservative list: only deterministic client/configuration failures bypass retry.
    private const UNRECOVERABLE_CODES = [
        'ZPAY_INVALID_ACCOUNT', 'ZPAY_ACCOUNT_DISABLED', 'PARAM_INVALID',
        'TEMPLATE_NOT_FOUND', 'CONTENT_REJECTED',
    ];

    public function __construct(
        private readonly DataScopeService $scope = new DataScopeService(),
        private readonly NotificationDispatchService $dispatch = new NotificationDispatchService(),
        private readonly PermissionService $permissions = new PermissionService(),
    ) {
    }

    /**
     * @param list<string> $permissions
     * @param array<string,mixed> $params
     * @return array{items:list<array<string,mixed>>,total:int,page:int,limit:int,counts_by_source:array<string,int>}
     */
    public function list(int $staffAccountId, array $permissions, array $params = []): array
    {
        $startedAt = microtime(true);
        if ($staffAccountId <= 0) {
            throw new BusinessException('UNAUTHENTICATED', 'UNAUTHENTICATED');
        }
        $params = $this->normalizeListParams($params);
        $states = $this->stateMap($staffAccountId);
        $base = [];
        $degraded = false;
        foreach (array_keys(self::SOURCE_WEIGHTS) as $sourceType) {
            if ($sourceType === 'long_pending' || !$this->canView($sourceType, $permissions)) {
                continue;
            }
            if ($params['source_type'] !== null && $params['source_type'] !== $sourceType && $params['source_type'] !== 'long_pending') {
                continue;
            }
            try {
                $base = [...$base, ...$this->querySource($sourceType, $staffAccountId)];
            } catch (\Throwable $e) {
                $degraded = true;
                Logger::warning('ops_inbox.source_degraded', ['source_type' => $sourceType, 'err' => $e->getMessage()]);
            }
        }
        $longPending = [];
        $items = [];
        foreach ($base as $row) {
            $key = $row['source_type'] . ':' . $row['source_key'];
            $state = $states[$key] ?? ['status' => 'open', 'assignee_id' => null, 'snooze_until' => null];
            if (($state['status'] ?? 'open') === 'assigned' && (int) ($state['assignee_id'] ?? 0) !== $staffAccountId) {
                continue;
            }
            $row['state'] = (string) ($state['status'] ?? 'open');
            $row['assignee_id'] = $state['assignee_id'] !== null ? (int) $state['assignee_id'] : null;
            $row['snooze_until'] = $state['snooze_until'] !== null ? $this->iso8601((string) $state['snooze_until']) : null;
            if ($row['state'] !== $params['state']) {
                continue;
            }
            if ($params['age_min_hours'] !== null && $row['age_seconds'] < $params['age_min_hours'] * 3600) {
                continue;
            }
            unset($row['department_id'], $row['creator_id']);
            if ($row['age_seconds'] >= $this->longPendingHours() * 3600 && ($state['status'] ?? 'open') === 'open') {
                $longPending[] = [
                    ...$row,
                    'source_type' => 'long_pending',
                    'id' => 'long_pending:' . $row['source_key'],
                    'title' => '长期未处理：' . $row['title'],
                    'weight' => self::SOURCE_WEIGHTS['long_pending'],
                    'suggested_action' => self::SOURCE_ACTIONS['long_pending'],
                    'state' => 'open',
                    'assignee_id' => null,
                    'snooze_until' => null,
                ];
            }
            if ($params['source_type'] !== 'long_pending' && !($row['age_seconds'] >= $this->longPendingHours() * 3600 && ($state['status'] ?? 'open') === 'open')) {
                $items[] = $row;
            }
        }
        if ($params['source_type'] === null || $params['source_type'] === 'long_pending') {
            $items = [...$items, ...$longPending];
        }

        usort($items, function (array $a, array $b) use ($params): int {
            $primary = $params['sort_by'] === 'age_seconds' ? 'age_seconds' : 'weight';
            $cmp = $a[$primary] <=> $b[$primary];
            if ($params['sort_dir'] === 'desc') {
                $cmp = -$cmp;
            }
            return $cmp !== 0 ? $cmp : (($b['weight'] <=> $a['weight']) ?: ($b['age_seconds'] <=> $a['age_seconds']));
        });

        $counts = [];
        foreach ($items as $item) {
            $counts[$item['source_type']] = ($counts[$item['source_type']] ?? 0) + 1;
        }
        foreach (array_keys(self::SOURCE_WEIGHTS) as $sourceType) {
            if ($this->canView($sourceType, $permissions) && !array_key_exists($sourceType, $counts)) {
                $counts[$sourceType] = 0;
            }
        }
        $page = $params['page'];
        $limit = $params['limit'];
        $elapsed = microtime(true) - $startedAt;
        if ($elapsed > 1.5) {
            $degraded = true;
            Logger::warning('ops_inbox.list.slow', ['staff_id' => $staffAccountId, 'duration_ms' => (int) round($elapsed * 1000)]);
        }
        return [
            'items' => array_values(array_slice($items, ($page - 1) * $limit, $limit)),
            'total' => count($items),
            'page' => $page,
            'limit' => $limit,
            'counts_by_source' => $counts,
            'degraded' => $degraded,
            'duration_ms' => (int) round($elapsed * 1000),
        ];
    }

    /**
     * @param list<string> $permissions
     * @return array<string,int>
     */
    public function countsBySource(int $staffAccountId, array $permissions): array
    {
        $result = [];
        $states = $this->stateMap($staffAccountId);
        foreach (self::SOURCE_WEIGHTS as $sourceType => $_) {
            if (!$this->canView($sourceType, $permissions)) {
                continue;
            }
            if ($sourceType === 'long_pending') {
                $result[$sourceType] = count($this->longPendingRows($staffAccountId, $states));
                continue;
            }
            $result[$sourceType] = count($this->querySource($sourceType, $staffAccountId));
        }
        return $result;
    }

    /**
     * @param array<string,mixed> $body
     * @return array{ok:bool,state:string,updated_at:string}
     */
    public function transitionState(int $staffAccountId, string $id, array $body): array
    {
        if ($staffAccountId <= 0) {
            throw new BusinessException('UNAUTHENTICATED', 'UNAUTHENTICATED');
        }
        if (preg_match('/^([a-z_]+):(\d+)$/', $id, $match) !== 1) {
            throw new BusinessException('VALIDATION_FAILED', 'OPS_NOT_FOUND');
        }
        $sourceType = $match[1];
        $sourceKey = $match[2];
        if (!isset(self::SOURCE_WEIGHTS[$sourceType])) {
            throw new BusinessException('VALIDATION_FAILED', 'OPS_SOURCE_INVALID');
        }
        $permissions = $this->permissions->effectiveCodes($staffAccountId);
        if (!$this->canView($sourceType, $permissions)) {
            throw new BusinessException('FORBIDDEN', 'OPS_FORBIDDEN');
        }
        if ($sourceType === 'long_pending') {
            $resolved = $this->findLongPendingSource($sourceKey, $staffAccountId, $permissions);
            if ($resolved === null) {
                throw new BusinessException('NOT_FOUND', 'OPS_NOT_FOUND');
            }
            $sourceType = $resolved['source_type'];
            $sourceKey = $resolved['source_key'];
            $source = $resolved['row'];
        } else {
            $source = $this->findSource($sourceType, $sourceKey, $staffAccountId);
        }
        if ($source === null) {
            throw new BusinessException('NOT_FOUND', 'OPS_NOT_FOUND');
        }
        $toState = (string) ($body['to_state'] ?? '');
        if (!array_key_exists($toState, self::ALLOWED_TRANSITIONS)) {
            throw new BusinessException('VALIDATION_FAILED', 'OPS_STATE_INVALID');
        }
        $existing = Db::name('ops_inbox_state')
            ->where('source_type', $sourceType)
            ->where('source_key', $sourceKey)
            ->where(function (Query $query) use ($staffAccountId): void {
                $query->where('actor_id', $staffAccountId)->whereOr('assignee_id', $staffAccountId);
            })
            ->find();
        $fromState = is_array($existing) ? (string) ($existing['status'] ?? 'open') : 'open';
        if ($fromState !== $toState && !in_array($toState, self::ALLOWED_TRANSITIONS[$fromState] ?? [], true)) {
            throw new BusinessException('CONFLICT', 'OPS_TRANSITION_FORBIDDEN');
        }
        $snoozeUntil = null;
        if ($toState === 'snoozed') {
            $raw = (string) ($body['snooze_until'] ?? '');
            $snoozeUntil = $this->parseSnooze($raw);
        }
        $assigneeId = null;
        if ($toState === 'assigned') {
            $assigneeId = (int) ($body['assignee_id'] ?? 0);
            if ($assigneeId <= 0 || $assigneeId === $staffAccountId || !$this->validAssignee($assigneeId)) {
                throw new BusinessException('VALIDATION_FAILED', 'OPS_ASSIGNEE_INVALID');
            }
        }
        $now = $this->nowDatetime();
        $values = [
            'status' => $toState,
            'assignee_id' => $assigneeId,
            'snooze_until' => $snoozeUntil,
            'last_actor_id' => $staffAccountId,
            'updated_at' => $now,
        ];
        if (is_array($existing)) {
            Db::name('ops_inbox_state')->where('id', (int) $existing['id'])->update($values);
        } else {
            Db::name('ops_inbox_state')->insert([
                'actor_id' => $staffAccountId,
                'source_type' => $sourceType,
                'source_key' => $sourceKey,
                'created_at' => $now,
                ...$values,
            ]);
        }
        if ($fromState !== $toState) {
            $action = match ($toState) {
                'resolved' => 'ops_inbox.acknowledge',
                'snoozed' => 'ops_inbox.snooze',
                'assigned' => 'ops_inbox.assign',
                'retrying' => 'ops_inbox.retry',
                default => 'ops_inbox.reopen',
            };
            $this->writeAudit($staffAccountId, $action, (int) $sourceKey, [
                'source_type' => $sourceType,
                'source_key' => $sourceKey,
                'from' => $fromState,
                'to' => $toState,
                'assignee_id' => $assigneeId,
                'snooze_until' => $snoozeUntil,
            ]);
        }
        return ['ok' => true, 'state' => $toState, 'updated_at' => $this->iso8601($now)];
    }

    /**
     * @param list<string> $permissions
     * @return array{ok:bool,state:string,retry_count:int,scheduled_at:?string}
     */
    public function retry(int $staffAccountId, string $sourceKey, array $permissions = []): array
    {
        if ($staffAccountId <= 0) {
            throw new BusinessException('UNAUTHENTICATED', 'UNAUTHENTICATED');
        }
        if (!$this->canView('queue_failed', $permissions !== [] ? $permissions : $this->permissions->effectiveCodes($staffAccountId))) {
            throw new BusinessException('FORBIDDEN', 'OPS_FORBIDDEN');
        }
        if (!ctype_digit($sourceKey) || (int) $sourceKey <= 0) {
            throw new BusinessException('NOT_FOUND', 'OPS_NOT_FOUND');
        }
        $row = Db::name('notification_dispatches')->alias('d')
            ->leftJoin('courses c', "c.id = d.resource_id AND d.resource_type = 'course'")
            ->leftJoin('staff_users s', 's.account_id = d.sender_staff_id')
            ->where('d.id', (int) $sourceKey)
            ->field('d.*, COALESCE(c.department_id, s.department_id) AS department_id, d.sender_staff_id AS creator_id')
            ->find();
        if (!is_array($row) || (string) ($row['fan_out_status'] ?? '') !== 'failed') {
            throw new BusinessException('CONFLICT', 'OPS_RETRY_NOT_RETRYABLE');
        }
        $this->assertScopeForRow($staffAccountId, 'queue_failed', $row);
        $count = (int) ($row['retry_count'] ?? 0) + 1;
        if ($count > $this->retryMaxAttempts()) {
            throw new BusinessException('CONFLICT', 'OPS_RETRY_NOT_RETRYABLE');
        }
        $this->upsertState($staffAccountId, 'queue_failed', $sourceKey, 'retrying', null, null);
        $this->writeAudit($staffAccountId, 'ops_inbox.retry', (int) $sourceKey, ['source_type' => 'queue_failed', 'retry_count' => $count]);
        Db::name('notification_dispatches')->where('id', (int) $sourceKey)->update(['retry_count' => $count]);
        $this->dispatch->retryFanOut((int) $sourceKey);
        $now = $this->nowDatetime();
        return ['ok' => true, 'state' => 'retrying', 'retry_count' => $count, 'scheduled_at' => $this->iso8601($now)];
    }

    public function maybeAutoRetry(int $dispatchId): string
    {
        $row = Db::name('notification_dispatches')->where('id', $dispatchId)->find();
        if (!is_array($row)) {
            return 'missing';
        }
        $error = strtoupper((string) ($row['fan_out_error'] ?? ''));
        foreach (self::UNRECOVERABLE_CODES as $code) {
            if (str_contains($error, $code)) {
                $this->resolveRetryingStates($dispatchId, 'open');
                $this->writeAudit(0, 'ops_inbox.retry_unrecoverable', $dispatchId, ['error_code' => $code]);
                return 'unrecoverable';
            }
        }
        $attempt = (int) ($row['retry_count'] ?? 0);
        $retryAt = $row['retry_at'] ?? null;
        if ($retryAt !== null && (string) $retryAt > $this->nowDatetime()) {
            return 'scheduled';
        }
        if ($attempt >= $this->retryMaxAttempts()) {
            $this->resolveRetryingStates($dispatchId, 'open');
            $this->writeAudit(0, 'ops_inbox.retry_exhausted', $dispatchId, ['retry_count' => $attempt]);
            return 'exhausted';
        }
        $nextAttempt = $attempt + 1;
        $backoff = $this->nextBackoff($nextAttempt);
        Db::name('notification_dispatches')->where('id', $dispatchId)->update([
            'retry_count' => $nextAttempt,
            'retry_at' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify('+' . $backoff . ' seconds')->format('Y-m-d H:i:s'),
        ]);
        try {
            $this->dispatch->retryFanOut($dispatchId);
        } catch (\Throwable $e) {
            Logger::warning('ops_inbox.auto_retry_enqueue_failed', ['dispatch_id' => $dispatchId, 'err' => $e->getMessage()]);
        }
        return 'scheduled';
    }

    /** Mark manual/automatic retry rows as resolved after a successful fan-out. */
    public function markRetrySucceeded(int $dispatchId): void
    {
        if ($this->resolveRetryingStates($dispatchId, 'resolved') > 0) {
            $this->writeAudit(0, 'ops_inbox.retry_succeeded', $dispatchId, []);
        }
    }

    /** Retry only rows whose persisted backoff has elapsed. */
    public function processDueRetries(int $limit = 50): int
    {
        $now = $this->nowDatetime();
        $rows = Db::name('notification_dispatches')
            ->where('fan_out_status', 'failed')
            ->whereNotNull('retry_at')
            ->where('retry_at', '<=', $now)
            ->order('retry_at', 'asc')
            ->limit(max(1, min(200, $limit)))
            ->select()
            ->toArray();
        $processed = 0;
        foreach (is_array($rows) ? $rows : [] as $row) {
            $dispatchId = (int) ($row['id'] ?? 0);
            if ($dispatchId <= 0) {
                continue;
            }
            try {
                $this->dispatch->retryFanOut($dispatchId);
                $processed++;
            } catch (\Throwable $e) {
                Logger::warning('ops_inbox.retry_schedule_failed', ['dispatch_id' => $dispatchId, 'err' => $e->getMessage()]);
            }
        }
        return $processed;
    }

    public function sweep(): int
    {
        $now = $this->nowDatetime();
        $affected = (int) Db::name('ops_inbox_state')
            ->where('status', 'snoozed')
            ->where('snooze_until', '<=', $now)
            ->update(['status' => 'open', 'snooze_until' => null, 'last_actor_id' => 0, 'updated_at' => $now]);
        if ($affected > 0) {
            $this->writeAudit(0, 'ops_inbox.sweep', 0, ['affected' => $affected]);
        }
        $this->processDueRetries();
        return $affected;
    }

    /** @param list<string> $permissions */
    private function canView(string $sourceType, array $permissions): bool
    {
        if (in_array('*', $permissions, true)) {
            return true;
        }
        $required = self::SOURCE_PERMISSIONS[$sourceType] ?? null;
        if ($required === '*') {
            return $permissions !== [];
        }
        // Feedback and queue use their existing concrete admin permissions.
        return $required !== null && (in_array($required, $permissions, true)
            || ($sourceType === 'feedback_pending' && in_array('course_feedback.manage', $permissions, true)));
    }

    /** @return list<array<string,mixed>> */
    private function querySource(string $sourceType, int $staffAccountId): array
    {
        $scope = $this->scope->resolveForCourses($staffAccountId);
        $rows = match ($sourceType) {
            'course_unpublished' => $this->queryCourseUnpublished($scope, $staffAccountId),
            'map_anomaly' => $this->queryMapAnomaly($scope, $staffAccountId),
            'question_pending' => $this->queryQuestionPending($scope, $staffAccountId),
            'feedback_pending' => $this->queryFeedbackPending($scope, $staffAccountId),
            'payment_unknown' => $this->queryPaymentUnknown($scope, $staffAccountId),
            'queue_failed' => $this->queryQueueFailed($scope, $staffAccountId),
            default => [],
        };
        return array_map(fn (array $row): array => $this->shapeException($row, $sourceType), $rows);
    }

    /**
     * @param array<string,mixed> $scope
     * @return list<array<string,mixed>>
     */
    private function queryCourseUnpublished(array $scope, int $staffAccountId): array
    {
        $query = Db::name('courses')->alias('c')->whereIn('c.status', ['draft', 'unpublished']);
        $this->applyScope($query, $scope, $staffAccountId, 'c.department_id', 'c.created_by_staff_id');
        $rows = $query->field('c.id,c.title,c.created_at,c.department_id,c.created_by_staff_id')->order('c.created_at', 'asc')->order('c.id', 'asc')->limit(50)->select()->toArray();
        return array_map(function (array $row): array {
            $learners = (int) Db::name('course_enrollments')->where('course_id', (int) $row['id'])->count();
            return ['source_key' => (string) $row['id'], 'title' => '课程「' . (string) $row['title'] . '」待发布', 'created_at' => (string) $row['created_at'], 'impact' => ['learners' => $learners], 'deep_link' => ['name' => 'courses', 'query' => ['id' => (string) $row['id']]], 'department_id' => (int) $row['department_id'], 'creator_id' => (int) $row['created_by_staff_id']];
        }, is_array($rows) ? $rows : []);
    }

    /**
     * @param array<string,mixed> $scope
     * @return list<array<string,mixed>>
     */
    private function queryMapAnomaly(array $scope, int $staffAccountId): array
    {
        $query = Db::name('learning_maps')->alias('m')->join('map_stages ms', 'ms.map_id = m.id')->join('map_stage_courses msc', 'msc.stage_id = ms.id')->join('courses c', 'c.id = msc.course_id')->where('m.status', 'published')->where('c.status', '<>', 'published');
        $this->applyScope($query, $scope, $staffAccountId, 'm.department_id', 'm.created_by_staff_id');
        $rows = $query->field('m.id,m.title,m.created_at,m.department_id,m.created_by_staff_id,COUNT(DISTINCT c.id) AS broken_courses')->group('m.id,m.title,m.created_at,m.department_id,m.created_by_staff_id')->order('m.created_at', 'asc')->order('m.id', 'asc')->limit(50)->select()->toArray();
        return array_map(function (array $row): array {
            $learners = (int) Db::name('map_enrollments')->where('map_id', (int) $row['id'])->count();
            return ['source_key' => (string) $row['id'], 'title' => '学习地图「' . (string) $row['title'] . '」存在异常课程', 'created_at' => (string) $row['created_at'], 'impact' => ['learners' => $learners, 'courses' => (int) $row['broken_courses']], 'deep_link' => ['name' => 'maps', 'query' => ['id' => (string) $row['id']]], 'department_id' => (int) $row['department_id'], 'creator_id' => (int) $row['created_by_staff_id']];
        }, is_array($rows) ? $rows : []);
    }

    /**
     * @param array<string,mixed> $scope
     * @return list<array<string,mixed>>
     */
    private function queryQuestionPending(array $scope, int $staffAccountId): array
    {
        $query = Db::name('questions')->alias('q')->join('courses c', 'c.id = q.course_id')->where('q.status', 'pending');
        $this->applyScope($query, $scope, $staffAccountId, 'c.department_id', 'c.created_by_staff_id');
        $rows = $query->field('q.id,q.title,q.created_at,q.learner_id,c.department_id,c.created_by_staff_id')->order('q.created_at', 'asc')->order('q.id', 'asc')->limit(50)->select()->toArray();
        return array_map(static fn (array $row): array => ['source_key' => (string) $row['id'], 'title' => '待回答问题：' . (string) ($row['title'] ?? '学员提问'), 'created_at' => (string) $row['created_at'], 'impact' => ['learners' => 1], 'deep_link' => ['name' => 'qa', 'query' => ['id' => (string) $row['id']]], 'department_id' => (int) $row['department_id'], 'creator_id' => (int) $row['created_by_staff_id']], is_array($rows) ? $rows : []);
    }

    /**
     * @param array<string,mixed> $scope
     * @return list<array<string,mixed>>
     */
    private function queryFeedbackPending(array $scope, int $staffAccountId): array
    {
        $query = Db::name('course_feedbacks')->alias('f')->join('courses c', 'c.id = f.course_id')->where('f.status', 'pending');
        $this->applyScope($query, $scope, $staffAccountId, 'c.department_id', 'c.created_by_staff_id');
        $rows = $query->field('f.id,f.created_at,f.learner_id,f.course_id,c.title AS course_title,c.department_id,c.created_by_staff_id')->order('f.created_at', 'asc')->order('f.id', 'asc')->limit(50)->select()->toArray();
        return array_map(static fn (array $row): array => ['source_key' => (string) $row['id'], 'title' => '课程「' . (string) $row['course_title'] . '」有待处理反馈', 'created_at' => (string) $row['created_at'], 'impact' => ['learners' => 1, 'replies' => 0], 'deep_link' => ['name' => 'course-feedback', 'query' => ['course_id' => (string) $row['course_id'], 'feedback_id' => (string) $row['id']]], 'department_id' => (int) $row['department_id'], 'creator_id' => (int) $row['created_by_staff_id']], is_array($rows) ? $rows : []);
    }

    /**
     * @param array<string,mixed> $scope
     * @return list<array<string,mixed>>
     */
    private function queryPaymentUnknown(array $scope, int $staffAccountId): array
    {
        $query = Db::name('orders')->alias('o')->join('courses c', 'c.id = o.course_id')->where('o.status', 'unknown');
        $this->applyScope($query, $scope, $staffAccountId, 'c.department_id', 'c.created_by_staff_id');
        $rows = $query->field('o.id,o.created_at,o.paid_amount,o.learner_id,c.department_id,c.created_by_staff_id')->order('o.created_at', 'asc')->order('o.id', 'asc')->limit(50)->select()->toArray();
        return array_map(static fn (array $row): array => ['source_key' => (string) $row['id'], 'title' => '订单 #' . (string) $row['id'] . ' 支付状态未知', 'created_at' => (string) $row['created_at'], 'impact' => ['learners' => 1, 'orders_amount_cents' => (int) round((float) $row['paid_amount'] * 100)], 'deep_link' => ['name' => 'orders', 'query' => ['status' => 'unknown', 'id' => (string) $row['id']]], 'department_id' => (int) $row['department_id'], 'creator_id' => (int) $row['created_by_staff_id']], is_array($rows) ? $rows : []);
    }

    /**
     * @param array<string,mixed> $scope
     * @return list<array<string,mixed>>
     */
    private function queryQueueFailed(array $scope, int $staffAccountId): array
    {
        $query = Db::name('notification_dispatches')->alias('d')->leftJoin('courses c', "c.id = d.resource_id AND d.resource_type = 'course'")->leftJoin('staff_users s', 's.account_id = d.sender_staff_id')->where('d.fan_out_status', 'failed')->where(function (Query $retry): void {
            $retry->whereNull('d.retry_at')->whereOr('d.retry_at', '<=', $this->nowDatetime())->whereOr('d.retry_count', '>=', $this->retryMaxAttempts());
        });
        if (!$scope['all']) {
            $this->applyScope($query, $scope, $staffAccountId, 'COALESCE(c.department_id, s.department_id)', 'd.sender_staff_id');
        }
        $rows = $query->field('d.id,d.title,d.created_at,d.fan_out_error,d.recipient_count,d.sender_staff_id,d.retry_count,COALESCE(c.department_id, s.department_id) AS department_id')->order('d.created_at', 'asc')->order('d.id', 'asc')->limit(50)->select()->toArray();
        return array_map(function (array $row): array {
            return [
                'source_key' => (string) $row['id'],
                'title' => '通知队列失败：' . (string) $row['title'],
                'created_at' => (string) $row['created_at'],
                'impact' => ['learners' => (int) $row['recipient_count'], 'retries' => (int) ($row['retry_count'] ?? 0)],
                'deep_link' => ['name' => 'notifications', 'query' => ['id' => (string) $row['id']]],
                'last_error_code' => $row['fan_out_error'] !== null ? (string) $row['fan_out_error'] : null,
                'subtype' => $this->retrySubtype((string) ($row['fan_out_error'] ?? ''), (int) ($row['retry_count'] ?? 0)),
                'retry_count' => (int) ($row['retry_count'] ?? 0),
                'department_id' => $row['department_id'] !== null ? (int) $row['department_id'] : null,
                'creator_id' => (int) $row['sender_staff_id'],
            ];
        }, is_array($rows) ? $rows : []);
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function shapeException(array $row, string $sourceType): array
    {
        $created = new DateTimeImmutable((string) ($row['created_at'] ?? $this->nowDatetime()), new DateTimeZone('UTC'));
        $age = max(0, (new DateTimeImmutable('now', new DateTimeZone('UTC')))->getTimestamp() - $created->getTimestamp());
        $weight = self::SOURCE_WEIGHTS[$sourceType];
        $subtype = $row['subtype'] ?? null;
        $suggested = (string) ($row['suggested_action'] ?? self::SOURCE_ACTIONS[$sourceType]);
        if ($sourceType === 'queue_failed' && $subtype === 'unrecoverable') $suggested = '检查账户状态或通知参数';
        return ['id' => $sourceType . ':' . (string) $row['source_key'], 'source_type' => $sourceType, 'source_key' => (string) $row['source_key'], 'title' => (string) $row['title'], 'severity' => $weight >= 80 || $age >= $this->longPendingHours() * 3600 ? 'critical' : ($weight >= 30 ? 'warning' : 'info'), 'age_seconds' => $age, 'age_label' => $this->ageLabel($age), 'weight' => $weight, 'impact' => ['learners' => 0, ...($row['impact'] ?? [])], 'suggested_action' => $suggested, 'deep_link' => $row['deep_link'], 'state' => 'open', 'assignee_id' => null, 'snooze_until' => null, 'last_error_code' => $row['last_error_code'] ?? null, 'retry_count' => (int) ($row['retry_count'] ?? 0), 'subtype' => $subtype, 'department_id' => $row['department_id'] ?? null, 'creator_id' => $row['creator_id'] ?? null];
    }

    /** @return array<string,mixed>|null */
    private function findSource(string $sourceType, string $sourceKey, int $staffAccountId): ?array
    {
        foreach ($this->querySource($sourceType, $staffAccountId) as $row) {
            if ((string) $row['source_key'] === $sourceKey) {
                return $row;
            }
        }
        return null;
    }

    /**
     * @param list<string> $permissions
     * @return array{source_type:string,source_key:string,row:array<string,mixed>}|null
     */
    private function findLongPendingSource(string $sourceKey, int $staffAccountId, array $permissions): ?array
    {
        $states = $this->stateMap($staffAccountId);
        foreach (array_keys(self::SOURCE_WEIGHTS) as $sourceType) {
            if ($sourceType === 'long_pending' || !$this->canView($sourceType, $permissions)) {
                continue;
            }
            foreach ($this->querySource($sourceType, $staffAccountId) as $row) {
                if ((string) $row['source_key'] !== $sourceKey || $row['age_seconds'] < $this->longPendingHours() * 3600) {
                    continue;
                }
                $state = $states[$sourceType . ':' . $sourceKey] ?? null;
                if (($state['status'] ?? 'open') !== 'open') {
                    continue;
                }
                return ['source_type' => $sourceType, 'source_key' => $sourceKey, 'row' => $row];
            }
        }
        return null;
    }

    /**
     * @param array<string,array<string,mixed>> $states
     * @return list<array<string,mixed>>
     */
    private function longPendingRows(int $staffAccountId, array $states): array
    {
        $rows = [];
        foreach (array_keys(self::SOURCE_WEIGHTS) as $sourceType) {
            if ($sourceType === 'long_pending') {
                continue;
            }
            foreach ($this->querySource($sourceType, $staffAccountId) as $row) {
                $key = $sourceType . ':' . $row['source_key'];
                if ($row['age_seconds'] >= $this->longPendingHours() * 3600 && ($states[$key]['status'] ?? 'open') === 'open') {
                    $rows[] = $row;
                }
            }
        }
        return $rows;
    }

    /** @return array<string,array<string,mixed>> */
    private function stateMap(int $staffAccountId): array
    {
        $rows = Db::name('ops_inbox_state')->where(function (Query $q) use ($staffAccountId): void { $q->where('actor_id', $staffAccountId)->whereOr('assignee_id', $staffAccountId); })->select()->toArray();
        $map = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            $map[(string) $row['source_type'] . ':' . (string) $row['source_key']] = $row;
        }
        return $map;
    }

    /** @param array<string,mixed> $row */
    private function assertScopeForRow(int $staffAccountId, string $sourceType, array $row): void
    {
        $scope = $this->scope->resolveForCourses($staffAccountId);
        if ($scope['all']) {
            return;
        }
        $departmentId = $row['department_id'] ?? null;
        if ($departmentId !== null) {
            DataScopeService::assertCourseAccessibleFromScope($scope, (int) $departmentId, (int) ($row['creator_id'] ?? $row['sender_staff_id'] ?? 0), $staffAccountId);
            return;
        }
        if (!$scope['include_self'] || (int) ($row['creator_id'] ?? $row['sender_staff_id'] ?? 0) !== $staffAccountId) {
            throw new BusinessException('FORBIDDEN', 'OPS_FORBIDDEN');
        }
    }

    /** @param array<string,mixed> $scope */
    private function applyScope(Query $query, array $scope, int $staffAccountId, string $departmentField, string $creatorField): void
    {
        if ($scope['all']) return;
        if ($scope['department_ids'] === [] && !$scope['include_self']) { $query->where($departmentField, -1); return; }
        $query->where(function (Query $where) use ($scope, $staffAccountId, $departmentField, $creatorField): void {
            if ($scope['department_ids'] !== []) $where->where($departmentField, 'in', $scope['department_ids']);
            if ($scope['include_self']) {
                if ($scope['department_ids'] !== []) $where->whereOr($creatorField, $staffAccountId); else $where->where($creatorField, $staffAccountId);
            }
        });
    }

    /**
     * @param array<string,mixed> $params
     * @return array{source_type:?string,state:string,age_min_hours:?int,sort_by:string,sort_dir:string,page:int,limit:int}
     */
    private function normalizeListParams(array $params): array
    {
        $source = $params['source_type'] ?? null;
        if ($source !== null && !isset(self::SOURCE_WEIGHTS[$source])) throw new BusinessException('VALIDATION_FAILED', 'OPS_SOURCE_INVALID');
        $state = (string) ($params['state'] ?? 'open');
        if (!array_key_exists($state, self::ALLOWED_TRANSITIONS)) throw new BusinessException('VALIDATION_FAILED', 'OPS_STATE_INVALID');
        $sortBy = (string) ($params['sort_by'] ?? 'weight'); if (!in_array($sortBy, ['weight', 'age_seconds'], true)) throw new BusinessException('VALIDATION_FAILED', 'OPS_SORT_INVALID');
        $sortDir = (string) ($params['sort_dir'] ?? 'desc'); if (!in_array($sortDir, ['asc', 'desc'], true)) throw new BusinessException('VALIDATION_FAILED', 'OPS_SORT_INVALID');
        $page = max(1, (int) ($params['page'] ?? 1)); $limit = (int) ($params['limit'] ?? 20); if ($limit < 1 || $limit > self::MAX_PAGE_LIMIT) throw new BusinessException('VALIDATION_FAILED', 'OPS_LIMIT_TOO_LARGE');
        $age = $params['age_min_hours'] ?? null; if ($age !== null && ((int) $age < 0 || (int) $age > 720)) throw new BusinessException('VALIDATION_FAILED', 'OPS_AGE_INVALID');
        return ['source_type' => $source, 'state' => $state, 'age_min_hours' => $age !== null ? (int) $age : null, 'sort_by' => $sortBy, 'sort_dir' => $sortDir, 'page' => $page, 'limit' => $limit];
    }

    private function parseSnooze(string $raw): string
    {
        try { $date = new DateTimeImmutable($raw); } catch (\Throwable) { throw new BusinessException('VALIDATION_FAILED', 'OPS_SNOOZE_INVALID'); }
        $now = new DateTimeImmutable('now', new DateTimeZone(self::TIMEZONE));
        if ($date <= $now->modify('+60 seconds')) throw new BusinessException('VALIDATION_FAILED', 'OPS_SNOOZE_TOO_SHORT');
        if ($date > $now->modify('+30 days')) throw new BusinessException('VALIDATION_FAILED', 'OPS_SNOOZE_TOO_LONG');
        return $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    private function validAssignee(int $id): bool { return (bool) Db::name('accounts')->where('id', $id)->where('kind', 'staff')->where('status', 'active')->count(); }
    private function upsertState(int $actorId, string $sourceType, string $sourceKey, string $status, ?int $assigneeId, ?string $snoozeUntil): void
    {
        $now = $this->nowDatetime();
        $existing = Db::name('ops_inbox_state')->where('actor_id', $actorId)->where('source_type', $sourceType)->where('source_key', $sourceKey)->find();
        $values = ['status' => $status, 'assignee_id' => $assigneeId, 'snooze_until' => $snoozeUntil, 'last_actor_id' => $actorId, 'updated_at' => $now];
        if (is_array($existing)) {
            Db::name('ops_inbox_state')->where('id', (int) $existing['id'])->update($values);
        } else {
            Db::name('ops_inbox_state')->insert(['actor_id' => $actorId, 'source_type' => $sourceType, 'source_key' => $sourceKey, 'created_at' => $now, ...$values]);
        }
    }

    private function resolveRetryingStates(int $dispatchId, string $status): int
    {
        $rows = Db::name('ops_inbox_state')->where('source_type', 'queue_failed')->where('source_key', (string) $dispatchId)->where('status', 'retrying')->select()->toArray();
        if (!is_array($rows)) {
            return 0;
        }
        $now = $this->nowDatetime();
        foreach ($rows as $row) {
            Db::name('ops_inbox_state')->where('id', (int) $row['id'])->update(['status' => $status, 'updated_at' => $now, 'last_actor_id' => 0]);
        }
        return count($rows);
    }
    private function retryMaxAttempts(): int { return max(1, (int) (getenv('OPS_RETRY_MAX_ATTEMPTS') ?: self::RETRY_MAX_ATTEMPTS)); }
    private function retryInitialBackoff(): int { return max(1, (int) (getenv('OPS_RETRY_INITIAL_BACKOFF_SECONDS') ?: self::RETRY_INITIAL_BACKOFF_SECONDS)); }
    private function retryMaxBackoff(): int { return max($this->retryInitialBackoff(), (int) (getenv('OPS_RETRY_MAX_BACKOFF_SECONDS') ?: self::RETRY_MAX_BACKOFF_SECONDS)); }
    private function longPendingHours(): int { return max(1, (int) (getenv('OPS_LONG_PENDING_HOURS') ?: self::LONG_PENDING_HOURS)); }
    private function retrySubtype(string $error, int $retryCount): string { foreach (self::UNRECOVERABLE_CODES as $code) if (str_contains(strtoupper($error), $code)) return 'unrecoverable'; return $retryCount >= $this->retryMaxAttempts() ? 'retry_exhausted' : 'retrying'; }
    private function nextBackoff(int $attempt): int { return min($this->retryMaxBackoff(), $this->retryInitialBackoff() * (self::RETRY_BACKOFF_MULTIPLIER ** max(0, $attempt - 1))); }
    private function ageLabel(int $seconds): string { if ($seconds >= 86400) return intdiv($seconds, 86400) . ' 天'; if ($seconds >= 3600) return intdiv($seconds, 3600) . ' 小时'; return max(1, intdiv($seconds, 60)) . ' 分钟'; }
    private function nowDatetime(): string { return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s'); }
    private function iso8601(string $datetime): string { return (new DateTimeImmutable($datetime, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone(self::TIMEZONE))->format('Y-m-d\TH:i:sP'); }
    /** @param array<string,mixed> $payload */
    private function writeAudit(int $actorId, string $action, int $targetId, array $payload): void { Db::name('audit_log')->insert(['actor_id' => $actorId, 'action' => $action, 'target_type' => 'ops_inbox', 'target_id' => $targetId > 0 ? $targetId : null, 'payload_json' => json_encode($payload, JSON_UNESCAPED_UNICODE), 'created_at' => $this->nowDatetime()]); }
}
