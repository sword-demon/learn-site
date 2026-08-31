<?php

declare(strict_types=1);

namespace App\service;

use App\queue\QueueNames;
use App\support\queue\JobDispatcher;
use support\think\Db;

/**
 * Admin-authored announcements and internal messages.
 */
final class NotificationDispatchService
{
    public const TYPE_ANNOUNCEMENT = 'announcement';
    public const TYPE_INTERNAL = 'internal_message';
    public const KIND_ANNOUNCEMENT = 'announcement';
    public const KIND_INTERNAL = 'internal_message';

    private const TITLE_MAX = 200;
    private const BODY_MAX = 10000;

    public function __construct(private readonly JobDispatcher $jobs = new JobDispatcher())
    {
    }

    /** @return array<string, mixed> */
    public function sendAnnouncement(int $staffId, string $title, string $body): array
    {
        $this->assertStaff($staffId);
        [$title, $body] = $this->validateContent($title, $body);
        $learnerIds = $this->activeLearnerIds();
        $now = date('Y-m-d H:i:s');

        $dispatchId = (int) Db::name('notification_dispatches')->insertGetId([
            'type' => self::TYPE_ANNOUNCEMENT,
            'title' => $title,
            'body' => $body,
            'sender_staff_id' => $staffId,
            'recipient_mode' => 'all',
            'recipient_count' => count($learnerIds),
            'fan_out_status' => 'pending',
            'fan_out_done_count' => 0,
            'created_at' => $now,
        ]);

        $this->enqueueFanOut($dispatchId);
        $this->auditSend($staffId, $dispatchId, self::TYPE_ANNOUNCEMENT, $title, count($learnerIds));

        return $this->shapeDispatch($this->loadDispatch($dispatchId), true);
    }

    /**
     * @param list<int> $learnerIds
     * @return array<string, mixed>
     */
    public function sendInternalMessage(int $staffId, string $title, string $body, array $learnerIds): array
    {
        $this->assertStaff($staffId);
        [$title, $body] = $this->validateContent($title, $body);
        $learnerIds = $this->normalizeRecipientIds($learnerIds);
        if ($learnerIds === []) {
            throw new BusinessException('VALIDATION_FAILED', 'INVALID_RECIPIENTS');
        }
        $now = date('Y-m-d H:i:s');

        $dispatchId = (int) Db::name('notification_dispatches')->insertGetId([
            'type' => self::TYPE_INTERNAL,
            'title' => $title,
            'body' => $body,
            'sender_staff_id' => $staffId,
            'recipient_mode' => 'selected',
            'recipient_count' => count($learnerIds),
            'fan_out_status' => 'pending',
            'fan_out_done_count' => 0,
            'created_at' => $now,
        ]);

        $recipientRows = array_map(
            static fn (int $learnerId): array => ['dispatch_id' => $dispatchId, 'learner_id' => $learnerId],
            $learnerIds,
        );
        Db::name('notification_dispatch_recipients')->insertAll($recipientRows);

        $this->enqueueFanOut($dispatchId);
        $this->auditSend($staffId, $dispatchId, self::TYPE_INTERNAL, $title, count($learnerIds));

        return $this->shapeDispatch($this->loadDispatch($dispatchId), true);
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{items:list<array<string,mixed>>,total:int,page:int,limit:int}
     */
    public function list(array $filters): array
    {
        $type = (string) ($filters['type'] ?? '');
        if ($type !== '' && !in_array($type, [self::TYPE_ANNOUNCEMENT, self::TYPE_INTERNAL], true)) {
            throw new BusinessException('VALIDATION_FAILED', 'INVALID_TYPE');
        }
        $from = $this->parseDateFilter((string) ($filters['from'] ?? ''), true);
        $to = $this->parseDateFilter((string) ($filters['to'] ?? ''), false);
        $page = max(1, (int) ($filters['page'] ?? 1));
        $limit = max(1, min(100, (int) ($filters['limit'] ?? 20)));

        $query = Db::name('notification_dispatches')
            ->alias('d')
            ->join('accounts a', 'a.id = d.sender_staff_id')
            ->field('d.*, a.login AS sender_login');
        if ($type !== '') {
            $query->where('d.type', $type);
        }
        if ($from !== null) {
            $query->where('d.created_at', '>=', $from);
        }
        if ($to !== null) {
            $query->where('d.created_at', '<=', $to);
        }
        $total = (int) (clone $query)->count();
        $rows = $query->order('d.id', 'desc')->page($page, $limit)->select()->toArray();

        return [
            'items' => array_map(
                fn (array $row): array => $this->shapeDispatch($row, false),
                is_array($rows) ? $rows : [],
            ),
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
        ];
    }

    /** @return array<string, mixed> */
    public function show(int $dispatchId): array
    {
        $row = $this->loadDispatch($dispatchId);
        if ($row === null) {
            throw new BusinessException('NOT_FOUND', 'DISPATCH_NOT_FOUND');
        }
        $shaped = $this->shapeDispatch($row, true);
        if ($row['type'] === self::TYPE_INTERNAL) {
            $shaped['recipients'] = $this->loadRecipients($dispatchId);
        }
        return $shaped;
    }

    private function enqueueFanOut(int $dispatchId): void
    {
        try {
            $this->jobs->dispatch(QueueNames::NOTIFICATION_FAN_OUT, ['dispatch_id' => $dispatchId]);
        } catch (\Throwable $e) {
            Db::name('notification_dispatches')->where('id', $dispatchId)->update([
                'fan_out_status' => 'failed',
                'fan_out_error' => mb_substr($e->getMessage(), 0, 500),
                'fan_out_finished_at' => date('Y-m-d H:i:s'),
            ]);
            throw new BusinessException('INTERNAL', 'NOTIFICATION_FAN_OUT_QUEUE_FAILED');
        }
    }

    /** @return list<int> */
    private function activeLearnerIds(): array
    {
        $ids = Db::name('accounts')
            ->alias('a')
            ->join('learners l', 'l.account_id = a.id')
            ->where('a.status', 'active')
            ->order('a.id', 'asc')
            ->column('a.id');
        return array_values(array_filter(array_map('intval', is_array($ids) ? $ids : []), static fn (int $id): bool => $id > 0));
    }

    /**
     * @param list<int> $learnerIds
     * @return list<int>
     */
    private function normalizeRecipientIds(array $learnerIds): array
    {
        $unique = [];
        foreach ($learnerIds as $id) {
            $id = (int) $id;
            if ($id > 0) {
                $unique[$id] = $id;
            }
        }
        if ($unique === []) {
            return [];
        }
        $active = Db::name('accounts')
            ->alias('a')
            ->join('learners l', 'l.account_id = a.id')
            ->where('a.status', 'active')
            ->whereIn('a.id', array_values($unique))
            ->column('a.id');
        return array_values(array_filter(array_map('intval', is_array($active) ? $active : []), static fn (int $id): bool => $id > 0));
    }

    /** @return array{0:string,1:string} */
    private function validateContent(string $title, string $body): array
    {
        $title = trim($title);
        $body = trim($body);
        if ($title === '' || $body === '') {
            throw new BusinessException('VALIDATION_FAILED', 'TITLE_AND_BODY_REQUIRED');
        }
        if (mb_strlen($title) > self::TITLE_MAX || mb_strlen($body) > self::BODY_MAX) {
            throw new BusinessException('VALIDATION_FAILED', 'CONTENT_TOO_LONG');
        }
        return [$title, $body];
    }

    private function assertStaff(int $staffId): void
    {
        if ($staffId <= 0) {
            throw new BusinessException('UNAUTHENTICATED', 'UNAUTHENTICATED');
        }
    }

    /** @return array<string, mixed>|null */
    private function loadDispatch(int $dispatchId): ?array
    {
        $row = Db::name('notification_dispatches')
            ->alias('d')
            ->join('accounts a', 'a.id = d.sender_staff_id')
            ->where('d.id', $dispatchId)
            ->field('d.*, a.login AS sender_login')
            ->find();
        return is_array($row) ? $row : null;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function shapeDispatch(array $row, bool $includeBody): array
    {
        $type = (string) $row['type'];
        $count = (int) $row['recipient_count'];
        $summary = $type === self::TYPE_ANNOUNCEMENT
            ? '全体学员'
            : ($count . ' 名学员');
        $shaped = [
            'id' => (int) $row['id'],
            'type' => $type,
            'title' => (string) $row['title'],
            'sender_staff_id' => (int) $row['sender_staff_id'],
            'sender_login' => (string) ($row['sender_login'] ?? ''),
            'recipient_summary' => $summary,
            'recipient_count' => $count,
            'fan_out_status' => (string) ($row['fan_out_status'] ?? 'completed'),
            'fan_out_done_count' => (int) ($row['fan_out_done_count'] ?? 0),
            'fan_out_error' => isset($row['fan_out_error']) && $row['fan_out_error'] !== null
                ? (string) $row['fan_out_error']
                : null,
            'fan_out_started_at' => isset($row['fan_out_started_at']) && $row['fan_out_started_at'] !== null
                ? (string) $row['fan_out_started_at']
                : null,
            'fan_out_finished_at' => isset($row['fan_out_finished_at']) && $row['fan_out_finished_at'] !== null
                ? (string) $row['fan_out_finished_at']
                : null,
            'created_at' => (string) $row['created_at'],
        ];
        if ($includeBody) {
            $shaped['body'] = (string) $row['body'];
        }
        return $shaped;
    }

    /** @return list<array{id:int,login:string,display_name:string|null}> */
    private function loadRecipients(int $dispatchId): array
    {
        $rows = Db::name('notification_dispatch_recipients')
            ->alias('r')
            ->join('accounts a', 'a.id = r.learner_id')
            ->leftJoin('learners l', 'l.account_id = r.learner_id')
            ->where('r.dispatch_id', $dispatchId)
            ->field('a.id, a.login, l.nickname AS display_name')
            ->order('a.id', 'asc')
            ->select()
            ->toArray();
        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'login' => (string) $row['login'],
            'display_name' => $row['display_name'] !== null ? (string) $row['display_name'] : null,
        ], is_array($rows) ? $rows : []);
    }

    private function parseDateFilter(string $value, bool $startOfDay): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1) {
            return $startOfDay ? $value . ' 00:00:00' : $value . ' 23:59:59';
        }
        $ts = strtotime($value);
        if ($ts === false) {
            throw new BusinessException('VALIDATION_FAILED', 'INVALID_DATE');
        }
        return date('Y-m-d H:i:s', $ts);
    }

    private function auditSend(int $staffId, int $dispatchId, string $type, string $title, int $recipientCount): void
    {
        Db::name('audit_log')->insert([
            'actor_id' => $staffId,
            'action' => 'notification.send',
            'target_type' => 'notification_dispatch',
            'target_id' => $dispatchId,
            'payload_json' => json_encode([
                'type' => $type,
                'title' => $title,
                'recipient_count' => $recipientCount,
            ], JSON_UNESCAPED_UNICODE),
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }
}
