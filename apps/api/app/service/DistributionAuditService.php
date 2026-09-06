<?php

declare(strict_types=1);

namespace App\service;

use support\think\Db;

use function nowDatetime;

/**
 * DistributionAuditService — single entry point for every distribution_audit_log
 * write. Routes through `record()` so the actor_type / actor_id / created_at
 * contract is uniform across admin and system actions.
 *
 * Per CLAUDE.md, audit is a "forgettable side-effect" that must not be
 * scattered across controllers. Every write path that mutates distribution
 * state goes through here, then forgets about it.
 */
final class DistributionAuditService
{
    /**
     * @param array<string, mixed>|null $before
     * @param array<string, mixed>|null $after
     * @param string|null $reason
     */
    public function record(
        string $actorType,
        ?int $actorId,
        string $action,
        string $subjectType,
        ?int $subjectId,
        ?array $before = null,
        ?array $after = null,
        ?string $reason = null,
    ): int {
        $now = nowDatetime();
        $beforeJson = $before === null ? null : json_encode($before, JSON_UNESCAPED_UNICODE);
        $afterJson = $after === null ? null : json_encode($after, JSON_UNESCAPED_UNICODE);
        $id = (int) Db::name('distribution_audit_log')->insertGetId([
            'actor_type' => $actorType,
            'actor_id' => $actorId,
            'action' => $action,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'before_json' => $beforeJson,
            'after_json' => $afterJson,
            'reason' => $reason,
            'created_at' => $now,
        ]);
        return $id;
    }
}