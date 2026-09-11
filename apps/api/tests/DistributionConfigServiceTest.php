<?php

declare(strict_types=1);

namespace Tests;

use App\service\BusinessException;
use App\service\DistributionConfigService;
use PHPUnit\Framework\TestCase;
use support\App;
use support\think\Db;
use Webman\ThinkOrm\ThinkOrm;

final class DistributionConfigServiceTest extends TestCase
{
    private int $staffId;
    private DistributionConfigService $service;

    public static function setUpBeforeClass(): void
    {
        App::loadAllConfig(['route', 'container']);
        ThinkOrm::start(null);
    }

    protected function setUp(): void
    {
        Db::startTrans();
        $now = date('Y-m-d H:i:s');
        $this->staffId = (int) Db::name('accounts')->insertGetId([
            'kind' => 'staff',
            'login' => 'dist-cfg-' . bin2hex(random_bytes(4)),
            'password_hash' => 'x',
            'must_change_password' => 0,
            'status' => 'active',
            'last_login_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        Db::name('staff_users')->insert([
            'account_id' => $this->staffId,
            'is_super_admin' => 1,
            'department_id' => null,
            'display_name' => 'Distribution Admin',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $this->service = new DistributionConfigService();
    }

    protected function tearDown(): void
    {
        Db::rollback();
    }

    private function input(array $overrides = []): array
    {
        return array_merge([
            'enabled' => true,
            'level_cap' => 3,
            'level1_pct' => 0.10,
            'level2_pct' => 0.05,
            'level3_pct' => 0.02,
            'base' => 'order_paid',
            'per_order_cap_cents' => 5000,
            'per_learner_course_cap_cents' => 50000,
            'per_learner_total_cap_cents' => null,
            'settlement' => 'order_settled_after_refund_window',
            'refund_void_rule' => 'void_all',
            'payout_form' => 'cash_record_only',
            'learner_can_view_detail' => true,
        ], $overrides);
    }

    public function testUpdatePersistsNormalizedConfigAndWritesAudit(): void
    {
        $result = $this->service->updateConfig($this->staffId, $this->input());
        self::assertSame(3, (int) $result['level_cap']);
        self::assertTrue($result['enabled']);
        self::assertSame($this->staffId, (int) $result['updated_by']);
        self::assertIsString($result['updated_at']);

        $row = Db::name('site_settings')->where('key', 'distribution_config')->find();
        self::assertIsArray($row);
        $decoded = json_decode((string) $row['value'], true);
        self::assertSame(3, (int) $decoded['level_cap']);

        self::assertSame(
            1,
            (int) Db::name('distribution_audit_log')->where('action', 'config.update')->count(),
        );
    }

    public function testUpdateLevelCap4IsRejectedBeforeWrite(): void
    {
        $this->expectException(BusinessException::class);
        $this->expectExceptionMessageMatches('/LEVEL_CAP_EXCEEDS_HARD_LIMIT/');
        try {
            $this->service->updateConfig($this->staffId, $this->input(['level_cap' => 4]));
        } finally {
            // Audit count must not advance when validation fails.
            self::assertSame(
                0,
                (int) Db::name('distribution_audit_log')->where('action', 'config.update')->count(),
            );
        }
    }

    public function testUpdateLevelCap0IsRejected(): void
    {
        $this->expectException(BusinessException::class);
        $this->service->updateConfig($this->staffId, $this->input(['level_cap' => 0]));
    }

    public function testGetConfigReturnsBaselineWhenRowMissing(): void
    {
        // After rollback, no distribution_config row should exist. Service
        // must still return a usable baseline.
        $cfg = $this->service->getConfig();
        self::assertSame(3, (int) $cfg['level_cap']);
        self::assertFalse($cfg['enabled']);
    }

    public function testUpdateBustsReadCache(): void
    {
        // First read populates cache, second update must be visible to a
        // subsequent read — i.e. no stale enabled=false leaks through.
        $this->service->updateConfig($this->staffId, $this->input(['enabled' => true]));
        $cached = $this->service->getConfig();
        self::assertTrue($cached['enabled']);

        $this->service->updateConfig($this->staffId, $this->input(['enabled' => false]));
        $fresh = $this->service->getConfig();
        self::assertFalse($fresh['enabled']);
    }
}