<?php

declare(strict_types=1);

use Phinx\Seed\AbstractSeed;

/**
 * Default scheduled tasks for code-registered handlers.
 */
final class ScheduledTaskSeeder extends AbstractSeed
{
    public function run(): void
    {
        $pdo = $this->getAdapter()->getConnection();
        $exists = $pdo->prepare('SELECT 1 FROM scheduled_tasks WHERE handler_code = ?');
        $insert = $pdo->prepare(
            'INSERT INTO scheduled_tasks (
                handler_code, name, description, schedule_expression, enabled, params_json, created_at, updated_at
            ) VALUES (?, ?, ?, ?, 1, ?, NOW(), NOW())',
        );

        $tasks = [
            [
                'handler_code' => 'notification.cleanup',
                'name' => '学员消息收件箱过期清理',
                'description' => '删除创建时间超过 2 个月的学员收件箱记录',
                'schedule_expression' => '0 30 3 * * *',
                'params_json' => json_encode(['batch_size' => 500], JSON_UNESCAPED_UNICODE),
            ],
            [
                'handler_code' => 'order.cancel_expired',
                'name' => '超时未支付订单自动取消',
                'description' => '取消创建满 15 分钟仍未支付的订单，并释放锁定优惠券',
                'schedule_expression' => '0 * * * * *',
                'params_json' => json_encode(['batch_size' => 200], JSON_UNESCAPED_UNICODE),
            ],
        ];

        foreach ($tasks as $task) {
            $exists->execute([$task['handler_code']]);
            if ($exists->fetchColumn() !== false) {
                continue;
            }
            $insert->execute([
                $task['handler_code'],
                $task['name'],
                $task['description'],
                $task['schedule_expression'],
                $task['params_json'],
            ]);
        }
    }
}
