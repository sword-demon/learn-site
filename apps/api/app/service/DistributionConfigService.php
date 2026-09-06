<?php

declare(strict_types=1);

namespace App\service;

use App\support\DistributionConfigValidator;
use support\think\Db;

use function nowDatetime;
use function toIso8601;

/**
 * DistributionConfigService — single read/write path for the site-level
 * distribution_config blob stored in site_settings.key='distribution_config'.
 *
 * Layered SC-003 / FR-010 protection:
 *   1. App validator (DistributionConfigValidator::assertValidConfig) — rejects
 *      level_cap > 3, ratios out of [0,1], negative caps, non-bool flags.
 *   2. DB CHECK constraint chk_distribution_level_cap — same ceiling enforced
 *      at storage, so a bypass of the service still trips SQLSTATE 45000.
 *   3. Unit tests — see DistributionConfigValidatorTest / DistributionConfigServiceTest.
 *
 * Writes route through writeAudit() (private) so every change lands a row in
 * distribution_audit_log with the before/after JSON snapshot.
 */
final class DistributionConfigService
{
    private const SETTINGS_KEY = 'distribution_config';

    /** @var array<string, mixed>|null */
    private ?array $cached = null;
    private int $cachedAt = 0;
    private const CACHE_TTL = 60;

    public function __construct(
        private readonly DistributionAuditService $audit = new DistributionAuditService(),
    ) {
    }

    /**
     * Active distribution config. Returns the seeded baseline if the row
     * has been wiped, so callers never have to defend against a missing row.
     *
     * @return array<string, mixed>
     */
    public function getConfig(): array
    {
        if ($this->cached !== null && time() - $this->cachedAt < self::CACHE_TTL) {
            return $this->cached;
        }
        $row = Db::name('site_settings')->where('key', self::SETTINGS_KEY)->find();
        $value = [];
        if ($row && isset($row['value'])) {
            $decoded = is_string($row['value']) ? json_decode($row['value'], true) : $row['value'];
            $value = is_array($decoded) ? $decoded : [];
        }
        $this->cached = $this->normalize($value);
        $this->cachedAt = time();
        return $this->cached;
    }

    /**
     * Apply an update. The full desired config is passed in (not a partial
     * patch) so validation is holistic — ratios can be rebalanced without
     * a round-trip and the DB always reflects the intended shape.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function updateConfig(int $staffId, array $input): array
    {
        DistributionConfigValidator::assertValidConfig($input);
        $before = $this->getConfig();
        $after = $this->normalize($input);
        $after['updated_at'] = toIso8601(time());
        $after['updated_by'] = $staffId;

        $now = nowDatetime();
        Db::transaction(function () use ($after, $now): void {
            $exists = Db::name('site_settings')
                ->where('key', self::SETTINGS_KEY)
                ->lock(true)
                ->find();
            $payload = json_encode($after, JSON_UNESCAPED_UNICODE);
            if ($payload === false) {
                throw new BusinessException('VALIDATION_FAILED', 'INVALID_CONFIG_JSON');
            }
            if ($exists) {
                Db::name('site_settings')
                    ->where('key', self::SETTINGS_KEY)
                    ->update(['value' => $payload, 'updated_at' => $now]);
            } else {
                Db::name('site_settings')->insert([
                    'key' => self::SETTINGS_KEY,
                    'value' => $payload,
                    'updated_at' => $now,
                ]);
            }
        });

        $this->writeAudit($staffId, $before, $after);
        $this->cached = null; // bust cache after write
        return $this->getConfig();
    }

    /**
     * Fills default values for any key missing from a partial payload so the
     * normalized shape always satisfies the validator.
     *
     * @param array<string, mixed> $cfg
     * @return array<string, mixed>
     */
    private function normalize(array $cfg): array
    {
        return array_merge([
            'enabled' => false,
            'level_cap' => 3,
            'level1_pct' => 0.10,
            'level2_pct' => 0.05,
            'level3_pct' => 0.02,
            'base' => 'order_paid',
            'per_order_cap_cents' => 5000,
            'per_learner_course_cap_cents' => 50000,
            'per_learner_total_cap_cents' => null,
            'settlement' => 'order_settled',
            'refund_void_rule' => 'void_all',
            'payout_form' => 'cash_record_only',
            'learner_can_view_detail' => true,
            'updated_at' => toIso8601(time()),
            'updated_by' => 1,
        ], $cfg);
    }

    /**
     * @param array<string, mixed> $before
     * @param array<string, mixed> $after
     */
    private function writeAudit(int $staffId, array $before, array $after): void
    {
        $this->audit->record(
            'admin',
            $staffId,
            'config.update',
            'config',
            null,
            $before,
            $after,
            null,
        );
    }
}