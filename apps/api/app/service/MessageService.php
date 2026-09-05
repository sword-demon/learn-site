<?php
declare(strict_types=1);

namespace App\service;

use support\think\Db;
use App\service\PushNotificationService;

/**
 * MessageService — learner inbox dispatcher (Phase 21 / US18, T104).
 */
final class MessageService
{
    public const KIND_QUESTION_UPDATE = 'question_update';
    public const KIND_PROGRESS_RESET = 'progress_reset';
    public const KIND_ENTITLEMENT_REVOKED = 'entitlement_revoked';
    public const KIND_ANNOUNCEMENT = 'announcement';
    public const KIND_INTERNAL_MESSAGE = 'internal_message';
    public const KIND_LEARNING_REMINDER = 'learning_reminder';

    public function __construct(
        private readonly ?PushNotificationService $push = null,
        private readonly UnreadCounterService $unread = new UnreadCounterService(),
    ) {
    }

    /**
     * Append an inbox row for the learner. The learner/key unique index
     * collapses concurrent retries and the existing row id is returned.
     */
    /** @param array<string, mixed> $payload */
    public function emit(
        string $kind,
        int $learnerId,
        string $title,
        ?string $body = null,
        array $payload = [],
        ?string $resourceType = null,
        ?int $resourceId = null,
        ?string $idempotencyKey = null,
    ): int {
        $now = date('Y-m-d H:i:s');
        $idempotencyKey ??= $kind . ':' . bin2hex(random_bytes(16));
        $inserted = false;
        try {
            Db::name('learner_notifications')->insertGetId([
                'learner_id' => $learnerId,
                'kind' => $kind,
                'title' => $title,
                'body' => $body,
                'payload_json' => $payload === [] ? null : json_encode($payload, JSON_UNESCAPED_UNICODE),
                'resource_type' => $resourceType,
                'resource_id' => $resourceId,
                'idempotency_key' => $idempotencyKey,
                'read_at' => null,
                'created_at' => $now,
            ]);
            $inserted = true;
        } catch (\Throwable) {
            // The unique learner/key index resolves concurrent retries.
        }
        $id = Db::name('learner_notifications')
            ->where('learner_id', $learnerId)
            ->where('idempotency_key', $idempotencyKey)
            ->value('id');
        if ($id === null) {
            throw new \RuntimeException('NOTIFICATION_WRITE_FAILED');
        }
        $notificationId = (int) $id;
        if ($inserted) {
            $unread = $this->unread->increment($learnerId);
            $this->push?->triggerNotification($learnerId, $notificationId, $kind, $title, $unread);
        }
        return $notificationId;
    }
}
