<?php

declare(strict_types=1);

namespace App\service;

use App\queue\QueueNames;
use App\support\Logger;
use App\support\queue\JobDispatcher;
use support\think\Db;

/**
 * Chunked fan-out for notification dispatches (sync consumer + async queue).
 */
final class NotificationFanOutExecutor
{
    private const CHUNK_SIZE = 250;

    public function __construct(
        private readonly JobDispatcher $jobs = new JobDispatcher(),
        private readonly UnreadCounterService $unread = new UnreadCounterService(),
    ) {
    }

    /** @param array{dispatch_id?:int} $data */
    public function run(array $data): void
    {
        $dispatchId = (int) ($data['dispatch_id'] ?? 0);
        if ($dispatchId <= 0) {
            throw new \InvalidArgumentException('dispatch_id required');
        }

        $dispatch = Db::name('notification_dispatches')->where('id', $dispatchId)->find();
        if (!is_array($dispatch)) {
            throw new \RuntimeException('DISPATCH_NOT_FOUND');
        }

        $status = (string) ($dispatch['fan_out_status'] ?? 'pending');
        if ($status === 'completed') {
            return;
        }

        $now = date('Y-m-d H:i:s');
        Db::name('notification_dispatches')->where('id', $dispatchId)->update([
            'fan_out_status' => 'running',
            'fan_out_started_at' => $dispatch['fan_out_started_at'] ?? $now,
            'fan_out_error' => null,
        ]);

        try {
            $learnerIds = $this->recipientIds($dispatch);
            $kind = $this->kindForType((string) $dispatch['type']);
            $title = (string) $dispatch['title'];
            $body = (string) $dispatch['body'];
            $done = 0;

            foreach (array_chunk($learnerIds, self::CHUNK_SIZE) as $chunk) {
                $chunkNow = date('Y-m-d H:i:s');
                $rows = [];
                foreach ($chunk as $learnerId) {
                    $rows[] = [
                        'learner_id' => $learnerId,
                        'kind' => $kind,
                        'title' => $title,
                        'body' => $body,
                        'payload_json' => null,
                        'resource_type' => null,
                        'resource_id' => null,
                        'dispatch_id' => $dispatchId,
                        'idempotency_key' => $dispatchId . ':' . $learnerId,
                        'read_at' => null,
                        'created_at' => $chunkNow,
                    ];
                }
                try {
                    Db::name('learner_notifications')->insertAll($rows);
                } catch (\Throwable) {
                    // idempotency_key unique — safe on retry.
                }

                foreach ($chunk as $learnerId) {
                    $row = Db::name('learner_notifications')
                        ->where('learner_id', $learnerId)
                        ->where('idempotency_key', $dispatchId . ':' . $learnerId)
                        ->find();
                    if (!is_array($row)) {
                        continue;
                    }
                    if ((string) $row['created_at'] !== $chunkNow) {
                        continue;
                    }
                    $notificationId = (int) $row['id'];
                    $unread = $this->unread->increment($learnerId);
                    $this->jobs->dispatch(QueueNames::NOTIFICATION_PUSH, [
                        'learner_id' => $learnerId,
                        'notification_id' => $notificationId,
                        'kind' => $kind,
                        'title' => $title,
                        'unread_count' => $unread,
                    ]);
                    $done++;
                }

                Db::name('notification_dispatches')->where('id', $dispatchId)->update([
                    'fan_out_done_count' => $done,
                ]);
            }

            $finished = date('Y-m-d H:i:s');
            Db::name('notification_dispatches')->where('id', $dispatchId)->update([
                'fan_out_status' => 'completed',
                'fan_out_done_count' => $done,
                'fan_out_finished_at' => $finished,
                'fan_out_error' => null,
            ]);
        } catch (\Throwable $e) {
            Logger::error('notification.fan_out.failed', [
                'dispatch_id' => $dispatchId,
                'err' => $e->getMessage(),
            ]);
            Db::name('notification_dispatches')->where('id', $dispatchId)->update([
                'fan_out_status' => 'failed',
                'fan_out_error' => mb_substr($e->getMessage(), 0, 500),
                'fan_out_finished_at' => date('Y-m-d H:i:s'),
            ]);
            throw $e;
        }
    }

    /**
     * @param array<string, mixed> $dispatch
     * @return list<int>
     */
    private function recipientIds(array $dispatch): array
    {
        $type = (string) $dispatch['type'];
        if ($type === NotificationDispatchService::TYPE_ANNOUNCEMENT) {
            return $this->activeLearnerIds();
        }
        $dispatchId = (int) $dispatch['id'];
        $ids = Db::name('notification_dispatch_recipients')
            ->where('dispatch_id', $dispatchId)
            ->order('learner_id', 'asc')
            ->column('learner_id');
        return array_values(array_filter(array_map('intval', is_array($ids) ? $ids : []), static fn (int $id): bool => $id > 0));
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

    private function kindForType(string $type): string
    {
        return match ($type) {
            NotificationDispatchService::TYPE_ANNOUNCEMENT => NotificationDispatchService::KIND_ANNOUNCEMENT,
            NotificationDispatchService::TYPE_INTERNAL => NotificationDispatchService::KIND_INTERNAL,
            default => $type,
        };
    }
}
