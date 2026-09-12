<?php

declare(strict_types=1);

namespace Tests;

use App\service\CommissionService;
use PHPUnit\Framework\TestCase;
use support\App;
use support\think\Db;
use Webman\ThinkOrm\ThinkOrm;

/**
 * Downline walk stops at level 3: a learner sees children (1), grandchildren
 * (2), great-grandchildren (3); level 4 and beyond never appear.
 */
final class LearnerDownlineViewTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        App::loadAllConfig(['route', 'container']);
        ThinkOrm::start(null);
    }

    protected function setUp(): void
    {
        Db::startTrans();
    }

    protected function tearDown(): void
    {
        Db::rollback();
    }

    public function testDownlineCoversThreeLevelsOnly(): void
    {
        // A → B → C → D → E (referrer chain)
        $chain = $this->chain(5);
        $a = $chain[0];

        $items = (new CommissionService())->downline($a, null, true)['items'];
        $byLevel = [];
        foreach ($items as $item) {
            $byLevel[(int) $item['level']][] = (int) $item['learner_id'];
        }
        self::assertSame([$chain[1]], $byLevel[1] ?? []);
        self::assertSame([$chain[2]], $byLevel[2] ?? []);
        self::assertSame([$chain[3]], $byLevel[3] ?? []);
        self::assertArrayNotHasKey(4, $byLevel, 'level 4 must never be returned');
        self::assertCount(3, $items);

        // every returned phone is masked
        foreach ($items as $item) {
            self::assertMatchesRegularExpression('/^1[3-9]\d\*{4}\d{4}$/', (string) $item['masked_phone']);
        }
    }

    public function testDownlineLevelFilter(): void
    {
        $chain = $this->chain(4);
        $a = $chain[0];

        $level1 = (new CommissionService())->downline($a, 1, true)['items'];
        self::assertCount(1, $level1);
        self::assertSame($chain[1], (int) $level1[0]['learner_id']);

        $level2 = (new CommissionService())->downline($a, 2, true)['items'];
        self::assertCount(1, $level2);
        self::assertSame($chain[2], (int) $level2[0]['learner_id']);

        $level3 = (new CommissionService())->downline($a, 3, true)['items'];
        self::assertCount(1, $level3);
        self::assertSame($chain[3], (int) $level3[0]['learner_id']);
    }

    public function testDetailSwitchOffHidesDownline(): void
    {
        $chain = $this->chain(2);
        $hidden = (new CommissionService())->downline($chain[0], null, false);
        self::assertSame([], $hidden['items']);
    }

    /** @return list<int> account ids A→… */
    private function chain(int $n): array
    {
        $ids = [];
        $now = date('Y-m-d H:i:s');
        for ($i = 0; $i < $n; $i++) {
            $id = (int) Db::name('accounts')->insertGetId([
                'kind' => 'learner',
                'login' => '13' . ($i + 1) . random_int(10000000, 99999999),
                'password_hash' => 'x',
                'must_change_password' => 0,
                'status' => 'active',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            Db::name('learners')->insert([
                'account_id' => $id,
                'referrer_learner_id' => $ids[$i - 1] ?? null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $ids[] = $id;
        }
        return $ids;
    }
}
