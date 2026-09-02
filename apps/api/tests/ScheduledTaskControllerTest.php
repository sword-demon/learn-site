<?php

declare(strict_types=1);

namespace Tests;

use App\controller\admin\ScheduledTaskController;
use App\middleware\Authorize;
use App\service\BusinessException;
use App\service\ScheduledTaskExecutor;
use App\service\ScheduledTaskService;
use PHPUnit\Framework\TestCase;
use support\App;
use support\Request;
use support\think\Db;
use Webman\ThinkOrm\ThinkOrm;

final class ScheduledTaskControllerTest extends TestCase
{
    private int $staffId;
    private int $taskId;

    public static function setUpBeforeClass(): void
    {
        App::loadAllConfig(['route', 'container']);
        ThinkOrm::start(null);
    }

    protected function setUp(): void
    {
        Db::startTrans();
        $this->staffId = $this->insertStaff();
        $this->taskId = $this->insertTask();
    }

    protected function tearDown(): void
    {
        Db::rollback();
    }

    public function testListAndShowReturnTaskDto(): void
    {
        $service = new ScheduledTaskService();
        $list = $service->list();
        self::assertNotEmpty($list);
        $task = array_values(array_filter(
            $list,
            fn (array $item): bool => (int) ($item['id'] ?? 0) === $this->taskId,
        ))[0] ?? null;
        self::assertIsArray($task);
        self::assertSame('notification.cleanup', $task['handler_code']);

        $detail = $service->show($this->taskId);
        self::assertSame($this->taskId, $detail['id']);
        self::assertSame('available', $detail['handler_status']);
    }

    public function testUpdateRejectsInvalidExpression(): void
    {
        $service = new ScheduledTaskService();
        $this->expectException(BusinessException::class);
        $this->expectExceptionMessage('INVALID_SCHEDULE_EXPRESSION');
        $service->update($this->taskId, $this->staffId, ['schedule_expression' => 'bad cron']);
    }

    public function testManualRunCreatesLogAndAudit(): void
    {
        $service = new ScheduledTaskService();
        $result = $service->runNow($this->taskId, $this->staffId);
        self::assertSame('manual', $result['trigger_type']);
        self::assertSame(
            1,
            Db::name('scheduled_task_runs')->where('task_id', $this->taskId)->count(),
        );
        self::assertSame(
            1,
            Db::name('audit_log')->where('action', 'scheduled_task.run')->count(),
        );
    }

    public function testConcurrentManualRunRejected(): void
    {
        Db::name('scheduled_task_runs')->insert([
            'task_id' => $this->taskId,
            'trigger_type' => 'manual',
            'status' => 'success',
            'started_at' => date('Y-m-d H:i:s'),
            'finished_at' => null,
            'duration_ms' => null,
            'error_message' => null,
            'context_json' => null,
            'actor_staff_id' => $this->staffId,
        ]);

        $service = new ScheduledTaskService();
        $this->expectException(BusinessException::class);
        $this->expectExceptionMessage('TASK_ALREADY_RUNNING');
        $service->runNow($this->taskId, $this->staffId);
    }

    public function testRoutesRequireManagePermission(): void
    {
        self::assertSame(
            'scheduled_task.manage',
            Authorize::permissionFor('/api/admin/v1/scheduled-tasks', 'GET'),
        );
        self::assertSame(
            'scheduled_task.manage',
            Authorize::permissionFor('/api/admin/v1/scheduled-tasks/1/run', 'POST'),
        );
    }

    public function testControllerIndexReturnsOk(): void
    {
        $request = new Request("GET /api/admin/v1/scheduled-tasks HTTP/1.1\r\nHost: test\r\n\r\n");
        /** @phpstan-ignore-next-line */
        $request->account_id = $this->staffId;
        $response = (new ScheduledTaskController(new ScheduledTaskService(), new \App\service\ScheduledTaskRunService()))->index($request);
        $payload = json_decode((string) $response->rawBody(), true);
        self::assertSame(200, $response->getStatusCode());
        self::assertTrue($payload['ok'] ?? false);
    }

    public function testOrderExpiryTaskAppearsAsAvailableInControllerList(): void
    {
        $taskId = $this->insertTask(
            'order.cancel_expired',
            '超时未支付订单自动取消',
            '0 * * * * *',
            ['batch_size' => 200],
        );
        $request = new Request("GET /api/admin/v1/scheduled-tasks HTTP/1.1\r\nHost: test\r\n\r\n");
        /** @phpstan-ignore-next-line */
        $request->account_id = $this->staffId;
        $response = (new ScheduledTaskController(
            new ScheduledTaskService(),
            new \App\service\ScheduledTaskRunService(),
        ))->index($request);
        $payload = json_decode((string) $response->rawBody(), true);
        $items = is_array($payload['data'] ?? null) ? $payload['data'] : [];
        $task = array_values(array_filter(
            $items,
            static fn (array $item): bool => (int) ($item['id'] ?? 0) === $taskId,
        ))[0] ?? null;

        self::assertIsArray($task);
        self::assertSame('order.cancel_expired', $task['handler_code']);
        self::assertSame('超时未支付订单自动取消', $task['name']);
        self::assertSame('available', $task['handler_status']);
    }

    private function insertStaff(): int
    {
        $now = date('Y-m-d H:i:s');
        $id = (int) Db::name('accounts')->insertGetId([
            'kind' => 'staff',
            'login' => 'sched-admin-' . bin2hex(random_bytes(4)),
            'password_hash' => 'hash',
            'must_change_password' => 0,
            'status' => 'active',
            'last_login_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        Db::name('staff_users')->insert([
            'account_id' => $id,
            'is_super_admin' => 1,
            'department_id' => null,
            'display_name' => 'Sched Admin',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        return $id;
    }

    /** @param array<string, mixed> $params */
    private function insertTask(
        string $handlerCode = 'notification.cleanup',
        string $name = '测试清理',
        string $expression = '0 30 3 * * *',
        array $params = ['batch_size' => 500],
    ): int {
        $now = date('Y-m-d H:i:s');
        Db::name('scheduled_tasks')->where('handler_code', $handlerCode)->delete();
        return (int) Db::name('scheduled_tasks')->insertGetId([
            'handler_code' => $handlerCode,
            'name' => $name,
            'description' => '测试',
            'schedule_expression' => $expression,
            'enabled' => 1,
            'params_json' => json_encode($params, JSON_UNESCAPED_UNICODE),
            'last_run_at' => null,
            'last_run_status' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
}
