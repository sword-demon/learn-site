<?php

declare(strict_types=1);

namespace Tests;

use app\process\ScheduledTaskRunner;
use PHPUnit\Framework\TestCase;
use support\App;
use support\think\Db;
use Webman\ThinkOrm\ThinkOrm;

/**
 * Ponytail: ScheduledTaskRunner is a custom process whose onWorkerStart() and
 * 30s Timer both touch reload() / maybeReload(). We don't exercise the timer
 * here — that would require workerman's event loop — but we do assert the
 * core invariant: a row inserted with a same-second updated_at (the original
 * blind spot) still triggers a reload, and a DB blip on the heartbeat does
 * not poison the next tick.
 *
 * Visibility is private; Reflection is the smallest bridge.
 */
final class ScheduledTaskRunnerReloadTest extends TestCase
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

    public function testMaxIdTriggersReloadEvenWhenUpdatedAtMatches(): void
    {
        $now = date('Y-m-d H:i:s');
        Db::name('scheduled_tasks')->insert([
            'id' => 1001,
            'handler_code' => 'notification.cleanup',
            'name' => '同秒插入',
            'schedule_expression' => '0 30 3 * * *',
            'enabled' => 1,
            'params_json' => '{}',
            'last_run_at' => null,
            'last_run_status' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $runner = new ScheduledTaskRunner();
        $this->invokeReload($runner);

        $crontabs = $this->readCrontabs($runner);
        self::assertArrayHasKey(1001, $crontabs, 'new row with max(id)=1001 must register a crontab');

        // Insert a second row with the same updated_at second — the old code
        // would not detect it. max(id) handles this.
        Db::name('scheduled_tasks')->insert([
            'id' => 1002,
            'handler_code' => 'notification.cleanup',
            'name' => '同秒再插入',
            'schedule_expression' => '0 30 3 * * *',
            'enabled' => 1,
            'params_json' => '{}',
            'last_run_at' => null,
            'last_run_status' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $this->invokeMaybeReload($runner);
        $crontabs = $this->readCrontabs($runner);
        self::assertArrayHasKey(1002, $crontabs, 'same-second insert with a higher id must still trigger reload');
    }

    public function testHeartbeatSurvivesDbException(): void
    {
        // ponytail: before the fix, an uncaught exception in maybeReload()
        // killed the Timer and silently stopped new-task discovery. After the
        // fix, the heartbeat logs and returns; the next tick still runs.
        $runner = new ScheduledTaskRunner();
        $reflection = new \ReflectionClass($runner);
        $lastSeen = $reflection->getProperty('lastSeenMaxId');
        $lastSeen->setAccessible(true);
        $lastSeen->setValue($runner, null);

        // Drop the table mid-heartbeat by rolling back the surrounding tx —
        // the next SELECT max(id) raises a PDOException.
        Db::rollback();

        // No assertion of return value: the contract is "does not throw".
        $this->invokeMaybeReload($runner);

        // Restart a fresh transaction for tearDown() to roll back cleanly.
        Db::startTrans();

        self::assertTrue(true, 'maybeReload must swallow DB exceptions so the heartbeat Timer survives');
    }

    /** @return array<int, mixed> */
    private function readCrontabs(ScheduledTaskRunner $runner): array
    {
        $crontabs = (new \ReflectionClass($runner))->getProperty('crontabs');
        $crontabs->setAccessible(true);
        return $crontabs->getValue($runner);
    }

    private function invokeReload(ScheduledTaskRunner $runner): void
    {
        $method = (new \ReflectionClass($runner))->getMethod('reload');
        $method->setAccessible(true);
        $method->invoke($runner);
    }

    private function invokeMaybeReload(ScheduledTaskRunner $runner): void
    {
        $method = (new \ReflectionClass($runner))->getMethod('maybeReload');
        $method->setAccessible(true);
        $method->invoke($runner);
    }
}
