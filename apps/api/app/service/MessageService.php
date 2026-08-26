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
    public const KIND_ENTITLEMENT_REVOKED = 'entitlement_revoked';

    /**
     * Append an inbox row for the learner. Idempotency is best-effort:
     * a duplicate call writes a second row. Callers should not retry the
     * same (learner, kind, target) combination without external dedup.
     */
    public function emit(string $kind, int $learnerId, string $title, ?string $body = null, array $payload = []): int
    {
        $now = date('Y-m-d H:i:s');
        return (int) Db::name('learner_notifications')->insertGetId([
            'learner_id' => $learnerId,
            'kind' => $kind,
            'title' => $title,
            'body' => $body,
            'payload_json' => $payload === [] ? null : json_encode($payload, JSON_UNESCAPED_UNICODE),
            'read_at' => null,
            'created_at' => $now,
        ]);
    }
}
