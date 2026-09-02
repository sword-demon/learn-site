<?php

declare(strict_types=1);

namespace Tests;

use App\scheduled\ScheduledTaskHandler;
use App\scheduled\ScheduledTaskHandlerRegistry;
use App\service\ScheduledTaskExecutor;
use PHPUnit\Framework\TestCase;
use support\App;
use support\think\Db;
use Webman\ThinkOrm\ThinkOrm;

final class ScheduledTaskRunnerTest extends TestCase
{
    private int $taskId;

    public static function setUpBeforeClass(): void
    {
        App::loadAllConfig(['route', 'container']);
        ThinkOrm::start(null);
    }

    protected function setUp(): void
    {
        Db::startTrans();
        $now = date('Y-m-d H:i:s');
        $this->taskId = (int) Db::name('scheduled_tasks')->insertGetId([
            'handler_code' => 'notification.cleanup',
            'name' => 'Runner 测试',
            'description' => '测试',
            'schedule_expression' => '0 30 3 * * *',
            'enabled' => 1,
            'params_json' => json_encode(['batch_size' => 500], JSON_UNESCAPED_UNICODE),
            'last_run_at' => null,
            'last_run_status' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    protected function tearDown(): void
    {
        Db::rollback();
    }

    public function testScheduleRunWritesSuccessLog(): void
    {
        $executor = new ScheduledTaskExecutor();
        $result = $executor->run($this->taskId, 'schedule', null);
        self::assertSame('success', $result['status']);
        self::assertSame(
            1,
            Db::name('scheduled_task_runs')
                ->where('task_id', $this->taskId)
                ->where('trigger_type', 'schedule')
                ->count(),
        );
    }

    public function testOverlapScheduleRunIsSkipped(): void
    {
        $now = date('Y-m-d H:i:s');
        Db::name('scheduled_task_runs')->insert([
            'task_id' => $this->taskId,
            'trigger_type' => 'schedule',
            'status' => 'success',
            'started_at' => $now,
            'finished_at' => null,
            'duration_ms' => null,
            'error_message' => null,
            'context_json' => null,
            'actor_staff_id' => null,
        ]);

        $executor = new ScheduledTaskExecutor();
        $result = $executor->run($this->taskId, 'schedule', null);
        self::assertSame('skipped', $result['status']);
    }

    public function testHandlerExceptionClosesRunRowWithFailedStatus(): void
    {
        // ponytail: before the fix, a thrown handler left a row with
        // finished_at=NULL — hasActiveRun() then guarded every future tick
        // and the run logs piled up as ghosts. The fix writes the terminal
        // row in its own try/catch and asserts status='failed' here.
        $registry = new ScheduledTaskHandlerRegistry();
        $registry->register(new class implements ScheduledTaskHandler {
            public function code(): string
            {
                return 'notification.cleanup';
            }

            public function execute(array $params): array
            {
                throw new \RuntimeException('boom');
            }

            public function normalizeParams(array $params): array
            {
                return $params;
            }
        });

        $executor = new ScheduledTaskExecutor($registry);
        $result = $executor->run($this->taskId, 'schedule', null);

        self::assertSame('failed', $result['status']);

        $row = Db::name('scheduled_task_runs')
            ->where('task_id', $this->taskId)
            ->where('trigger_type', 'schedule')
            ->order('id', 'desc')
            ->find();
        self::assertIsArray($row);
        self::assertNotNull($row['finished_at'], 'finished_at must be set even when handler throws');
        self::assertSame('failed', $row['status']);
        self::assertSame(0, (int) Db::name('scheduled_task_runs')
            ->where('task_id', $this->taskId)
            ->whereNull('finished_at')
            ->count(), 'no zombie running row should remain');
    }

    public function testHandlerFailureDoesNotPreventSecondRun(): void
    {
        $registry = new ScheduledTaskHandlerRegistry();
        $registry->register(new class implements ScheduledTaskHandler {
            public function code(): string
            {
                return 'notification.cleanup';
            }

            public function execute(array $params): array
            {
                throw new \RuntimeException('boom');
            }

            public function normalizeParams(array $params): array
            {
                return $params;
            }
        });

        $executor = new ScheduledTaskExecutor($registry);
        $first = $executor->run($this->taskId, 'schedule', null);
        self::assertSame('failed', $first['status']);

        $registry->register(new \App\scheduled\handler\NotificationCleanupHandler());
        $executor = new ScheduledTaskExecutor($registry);
        $second = $executor->run($this->taskId, 'schedule', null);
        self::assertSame('success', $second['status']);
    }
}
