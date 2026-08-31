# Queue Jobs & Infra Contracts: 008-api-scale-100k

内部契约（非 REST）；供 consumer、测试与运维对齐。

## 环境变量

| 变量 | 默认 | 说明 |
|------|------|------|
| `WEBMAN_WORKERS` | `2`（生产建议 `CPU×2`） | HTTP worker 数 |
| `QUEUE_CONSUMERS` | `2`（生产建议 `4`） | redis-queue consumer 进程数 |
| `DB_POOL_MAX` | `5`（生产建议 `30`） | 覆盖 think-orm pool max |
| `REDIS_POOL_MAX` | `5`（生产建议 `20`） | 覆盖 redis pool max |
| `HOME_CACHE_TTL_SITE` | `300` | 站点介绍缓存秒 |
| `HOME_CACHE_TTL_CATEGORY` | `300` | 分类树缓存秒 |
| `HOME_CACHE_TTL_BANNERS` | `60` | 轮播缓存秒 |
| `PAYMENT_NOTIFY_ASYNC` | `1` | `0` 时 testing 同步 dispatch |
| `TOKEN_KICK_ALLOW_SCAN_FALLBACK` | `0` | `1` 仅迁移期 kick 无索引 account |

## 队列名与 Job 类

| 队列名 | Consumer 类 | 投递方 |
|--------|-------------|--------|
| `notification.fan_out` | `App\queue\NotificationFanOutConsumer` | `NotificationDispatchService` |
| `notification.push` | `App\queue\PushNotificationConsumer` | FanOut consumer / `MessageService` |
| `payment.notify` | `App\queue\PaymentNotifyConsumer` | `PaymentNotifyController` |
| `scheduled.task` | `App\queue\ScheduledTaskConsumer` | `ScheduledTaskRunner` |

## NotificationFanOutConsumer

**输入**: `{ "dispatch_id": int }`

**行为**:
1. 加载 dispatch；若 `fan_out_status=completed` → ack 跳过
2. SET `running`, `fan_out_started_at`
3. 解析 recipient_ids（公告 active 全量；站内信 join recipients）
4. 每 chunk 250: `insertAll learner_notifications`（幂等 key）→ 更新 `fan_out_done_count` → 投递 Push jobs
5. SET `completed`, `fan_out_finished_at`；异常 → `failed` + `fan_out_error`

**重试**: 最多 3 次；幂等靠 `idempotency_key`

## PushNotificationConsumer

**输入**: `{ "learner_id", "notification_id", "kind", "title" }`

**行为**:
1. `INCR unread:{learner_id}`（emit 路径若已 INCR 则跳过 double — 实现二选一：仅 emit INCR 或仅 push INCR，文档锁定「emit INCR」）
2. `PushNotificationService::triggerOnly()` 不发 COUNT
3. 失败记 warning 日志，可重试

## PaymentNotifyConsumer

**输入**: `{ "order_id", "status", "provider_ref" }`

**行为**: 调用 `OrderService::markSucceeded` / `markFailed`（现有幂等）

## ScheduledTaskConsumer

**输入**: `{ "task_id", "trigger_type", "actor_staff_id" }`

**行为**: `ScheduledTaskExecutor::run(...)`

## Admin API 扩展（notification dispatch show）

`GET /api/admin/v1/notifications/dispatches/{id}` 响应增加:

```json
{
  "fan_out_status": "running",
  "fan_out_done_count": 12000,
  "fan_out_error": null,
  "fan_out_started_at": "2026-09-01T10:00:00+08:00",
  "fan_out_finished_at": null
}
```

Zod 契约扩展 `packages/contracts` `adminNotifications` schema（可选字段，向后兼容）。

## Health 扩展

`GET /health` checks 增加:

```json
{
  "queue": true | "queue_down"
}
```

检测 redis-queue 连接或 Redis 队列 key 可达（实现任选一种）。

## Nginx 媒体直出（compose / 文档）

学习端 `docker/web/nginx.conf` 将 `GET /api/media/covers|banners/...` 映射到共享卷 `api_runtime:/mnt/api-runtime/uploads/`（只读）。`GET /api/media/assets/{id}` 仍反代 API。
