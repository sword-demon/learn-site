# Data Model: 008-api-scale-100k

## 概览

本特性以 **Redis 数据结构** 与 **配置调参** 为主；MySQL 仅扩展 `notification_dispatches` 进度字段。无新表。

```text
notification_dispatches (+ fan_out_*)
        │
        │ dispatch_id
        ▼
learner_notifications (不变，幂等 idempotency_key)

Redis:
  {redis-queue}-*          队列插件内部
  unread:{learnerId}       未读计数
  cache:home:*             首页读缓存
  account:{id}:families    Token 索引
  family:{id}:access_keys / refresh_keys
  access:{hash} / refresh:{hash}  (既有)
```

---

## notification_dispatches（扩展）

在 `003` 表上增加 fan-out 进度列。

| 字段 | 类型 | 规则 |
|------|------|------|
| fan_out_status | ENUM(`pending`,`running`,`completed`,`failed`) | 默认 `pending`；HTTP 创建后为 `pending` |
| fan_out_done_count | INT UNSIGNED | 已写入 `learner_notifications` 的行数；完成时等于 `recipient_count` |
| fan_out_error | VARCHAR(500) NULL | 失败摘要；成功为 NULL |
| fan_out_started_at | TIMESTAMP NULL | consumer 开始时间 |
| fan_out_finished_at | TIMESTAMP NULL | 完成或失败时间 |

**状态机**:

```text
pending → running → completed
              └→ failed (可人工重试 → pending)
```

**索引**: 现有索引保留；可选 `(fan_out_status, created_at)` 供运维查询积压。

**校验**:
- `fan_out_done_count <= recipient_count`
- `completed` 时 `fan_out_done_count = recipient_count`
- HTTP 返回时 `fan_out_status` 为 `pending` 或 `running`（入队后立即返回）

---

## Redis: 未读计数

| Key | 类型 | 说明 |
|-----|------|------|
| `unread:{learnerId}` | STRING (integer) | 未读消息数；不存在时从 DB 回填 |

**操作约定**:
- `emit`: `INCR`；新学员首条 `SET` 或 INCR from 0
- `markRead` / `markAllRead`: `DECR` 或 `SET 0`
- TTL: 无（长期）；学员删除时 DEL

---

## Redis: Token 索引

| Key | 类型 | 说明 |
|-----|------|------|
| `account:{accountId}:families` | SET | family_id 列表 |
| `family:{familyId}:access_keys` | SET | `access:{hash}` 完整 key 名 |
| `family:{familyId}:refresh_keys` | SET | `refresh:{hash}` 完整 key 名 |

**生命周期**:
- `mint` / `rotate`: SADD 对应 sets；TTL 与 token TTL 对齐（refresh TTL）
- `kick` / `revokeFamily`: 按 set 成员 DEL token keys + SET `family:{id}:revoked`
- `rotate` 删除旧 refresh: 从 set 移除并 DEL

**兼容**: 无索引的旧 token 仍可通过 `access:{hash}` GET 校验。

---

## Redis: 首页缓存

| Key | 内容 | TTL |
|-----|------|-----|
| `cache:home:site_intro` | JSON `siteIntro()` | 300s |
| `cache:home:category_tree` | JSON `categoryTree()` | 300s |
| `cache:home:banners` | JSON `banners()` | 60s |

**失效**: 对应 Service 写路径 `DEL` 键（SiteProfile、Category、Banner admin services）。

---

## 队列 Job 载荷（逻辑模型，非 DB）

### NotificationFanOutJob

```json
{
  "dispatch_id": 123
}
```

### PushNotificationJob

```json
{
  "learner_id": 1,
  "notification_id": 456,
  "kind": "announcement",
  "title": "标题"
}
```

### PaymentNotifyJob

```json
{
  "order_id": 1,
  "status": "succeeded",
  "provider_ref": "wx-xxx"
}
```

### ScheduledTaskJob

```json
{
  "task_id": 1,
  "trigger_type": "schedule",
  "actor_staff_id": null
}
```

队列名（实现常量）:
- `notification.fan_out`
- `notification.push`
- `payment.notify`
- `scheduled.task`

---

## 迁移

单文件 Phinx 迁移 `20260901000001_add_fan_out_to_notification_dispatches.php`:

- ADD columns with defaults: `fan_out_status='completed'` for **existing rows**（历史已同步完成）或 `completed` + `fan_out_done_count=recipient_count`
- 新列 DEFAULT `pending` 仅对新 insert 生效（迁移 SQL 分两步：加列 → backfill 历史 → 改 default）
