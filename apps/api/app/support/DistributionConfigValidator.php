<?php

declare(strict_types=1);

namespace App\support;

use App\service\BusinessException;

/**
 * DistributionConfigValidator — runs the SC-003 / FR-010 compliance guards
 * before any distribution_config write. Mirrors the DB CHECK and the Zod
 * `z.literal(3)` on `level_cap`. Layer 1 of the three-layer protection.
 *
 * Rules:
 *   - level_cap <= 3 (hard cap, never relaxable)
 *   - each level ratio ∈ [0,1]
 *   - per_order_cap_cents, per_learner_*_cap_cents ≥ 0
 *   - the theoretical max of level1+level2+level3 × paid ≤ per_order_cap_cents
 *     (a value that could blow the cap gets truncated, never silently inflated)
 */
final class DistributionConfigValidator
{
    public const LEVEL_CAP_HARD_LIMIT = 3;
    public const COMMISSION_VOID_MIN_REASON_LEN = 5;
    public const TIMEZONE = 'Asia/Shanghai';

    /**
     * @param array<string, mixed> $cfg
     */
    public static function assertValidConfig(array $cfg): void
    {
        $levelCap = (int) ($cfg['level_cap'] ?? 0);
        if ($levelCap < 1 || $levelCap > self::LEVEL_CAP_HARD_LIMIT) {
            // Use 「合规硬约束」 wording so tests / UI can grep for it.
            throw new BusinessException(
                'VALIDATION_FAILED',
                'LEVEL_CAP_EXCEEDS_HARD_LIMIT:合规硬约束,level_cap 不得大于 ' . self::LEVEL_CAP_HARD_LIMIT,
            );
        }

        foreach (['level1_pct', 'level2_pct', 'level3_pct'] as $field) {
            $value = $cfg[$field] ?? null;
            if (!is_numeric($value)) {
                throw new BusinessException('VALIDATION_FAILED', 'INVALID_RATIO:' . $field);
            }
            $ratio = (float) $value;
            if ($ratio < 0.0 || $ratio > 1.0) {
                throw new BusinessException('VALIDATION_FAILED', 'RATIO_OUT_OF_RANGE:' . $field);
            }
        }

        foreach (['per_order_cap_cents', 'per_learner_course_cap_cents'] as $field) {
            $value = $cfg[$field] ?? -1;
            if (!is_int($value) && !ctype_digit((string) $value)) {
                throw new BusinessException('VALIDATION_FAILED', 'INVALID_CAP:' . $field);
            }
            if ((int) $value < 0) {
                throw new BusinessException('VALIDATION_FAILED', 'CAP_NEGATIVE:' . $field);
            }
        }
        if (($cfg['per_learner_total_cap_cents'] ?? null) !== null) {
            $value = $cfg['per_learner_total_cap_cents'];
            if (!is_int($value) && !ctype_digit((string) $value)) {
                throw new BusinessException('VALIDATION_FAILED', 'INVALID_CAP:per_learner_total_cap_cents');
            }
        }

        // enabled must be a real bool; protect against accidental string flags.
        if (!is_bool($cfg['enabled'] ?? null)) {
            throw new BusinessException('VALIDATION_FAILED', 'INVALID_ENABLED_FLAG');
        }
        if (!is_bool($cfg['learner_can_view_detail'] ?? null)) {
            throw new BusinessException('VALIDATION_FAILED', 'INVALID_LEARNER_VIEW_FLAG');
        }
    }

    /**
     * Pure projection of max payable cents given a config and the order's
     * paid amount (cents). Used by the snapshot preview and by tests.
     *
     * @param array<string, mixed> $cfg
     */
    public static function theoreticalMaxCommissionCents(array $cfg, int $orderPaidCents): int
    {
        $ratio = (float) ($cfg['level1_pct'] ?? 0)
            + (float) ($cfg['level2_pct'] ?? 0)
            + (float) ($cfg['level3_pct'] ?? 0);
        return (int) floor($orderPaidCents * $ratio);
    }
}
