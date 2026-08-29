<?php
declare(strict_types=1);

namespace App\service;

use support\think\Db;

/**
 * MessageService — learner inbox dispatcher (Phase 21 / US18, T104).
 *
 * Transport today: a `learner_notifications` row. The mailer / push side
 * is intentionally absent — a future phase will sweep unread rows on a
 * schedule. The dispatcher only writes the inbox; callers stay thin.
 *
 * Kinds are stable, free-form strings (no enum table). Today's inventory:
 *   - entitlement_revoked
 *
 * Payload is a JSON-encoded blob kept opaque so the dispatcher doesn't
 * couple to specific event shapes.
 */
final class MessageService
{
    public const KIND_QUESTION_UPDATE = 'question_update';
    public const KIND_PROGRESS_RESET = 'progress_reset';
    public const KIND_ENTITLEMENT_REVOKED = 'entitlement_revoked';

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
        return (int) $id;
    }
}
