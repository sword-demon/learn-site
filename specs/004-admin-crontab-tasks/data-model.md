# Data Model: 004-admin-crontab-tasks

## 概览

```text
scheduled_tasks (1) ──< scheduled_task_runs (N)
         │
         │ handler_code (逻辑 FK → 代码注册表)
         ▼
   ScheduledTaskHandlerRegistry (非表)
```

- **自动任务**（`scheduled_tasks`）：可持久化配置的调度单元；首版由种子创建，管理员不可新建类型。
- **执行日志**（`scheduled_task_runs`）：每次运行一条；长期保留、分页查询。
- **处理器注册表**（代码）：`handler_code` 与 PHP Handler 类绑定；不在 DB。

---

## scheduled_tasks

| 字段 | 类型 | 规则 |
|------|------|------|
| id | BIGINT UNSIGNED PK | 自增 |
| handler_code | VARCHAR(64) | 唯一；须命中 `ScheduledTaskHandlerRegistry` |
| name | VARCHAR(120) | 非空；展示名 |
| description | VARCHAR(500) | 可空；类型说明 |
| schedule_expression | VARCHAR(128) | 非空；六段式 cron |
| enabled | TINYINT(1) | 0/1；默认 1 |
| params_json | JSON | 可空；handler 约定参数 |
| last_run_at | TIMESTAMP NULL | 最近一次开始时间（冗余，便于列表） |
| last_run_status | ENUM(`success`,`failed`,`skipped`) NULL | 最近一次结果摘要 |
| created_at | TIMESTAMP | |
| updated_at | TIMESTAMP | runner 轮询依据 |

**索引**: UNIQUE `(handler_code)`、`(enabled)`、`(updated_at)`

**校验**:
- `schedule_expression` 通过 `Parser::isValid` 且必须为 6 段；最短间隔 ≥ 60s
- `enabled=1` 时 `handler_code` 必须在注册表且 `handler_status=available`
- `params_json` 由 handler 的 JSON Schema / 服务端校验（首版 `notification.cleanup`: `batch_size` 1–2000）

**种子数据**（首版）:

| handler_code | name | schedule_expression | params_json |
|--------------|------|---------------------|-------------|
| notification.cleanup | 学员消息收件箱过期清理 | `0 30 3 * * *` | `{"batch_size":500}` |

---

## scheduled_task_runs

| 字段 | 类型 | 规则 |
|------|------|------|
| id | BIGINT UNSIGNED PK | 自增 |
| task_id | BIGINT UNSIGNED | FK → `scheduled_tasks.id` ON DELETE CASCADE |
| trigger_type | ENUM(`schedule`,`manual`) | 自动 / 手动 |
| status | ENUM(`success`,`failed`,`skipped`) | |
| started_at | TIMESTAMP | 非空 |
| finished_at | TIMESTAMP NULL | 结束时写入 |
| duration_ms | INT UNSIGNED NULL | 派生或存储 |
| error_message | VARCHAR(2000) NULL | 失败或跳过原因 |
| context_json | JSON NULL | 如 `deleted_count`、`batch_size` |
| actor_staff_id | BIGINT UNSIGNED NULL | 手动触发时操作者；自动为 NULL |

**索引**: `(task_id, started_at DESC)`、`(status, started_at DESC)`、`(started_at DESC)`

**校验**:
- `finished_at >= started_at`（若均非空）
- `status=success` ⇒ `error_message` 为空
- `trigger_type=manual` ⇒ `actor_staff_id` 非空

---

## 状态转换

### scheduled_tasks.enabled

```text
enabled=1 ──(管理员停用)──> enabled=0
enabled=0 ──(管理员启用且 handler 可用)──> enabled=1
```

停用后 runner 下次 `reload()` 销毁对应 `Crontab` 实例。

### scheduled_task_runs（单次运行）

```text
(start) ──> running (内存，非表字段)
running ──success──> status=success
running ──exception──> status=failed
running ──overlap──> status=skipped (不进入 running 二次)
```

---

## 客户端 DTO 映射

### AdminScheduledTaskListItemDTO

| 字段 | 来源 |
|------|------|
| id | `scheduled_tasks.id` |
| handler_code | `handler_code` |
| name | `name` |
| description | `description` |
| schedule_expression | `schedule_expression` |
| enabled | `enabled` |
| params | `params_json` 解析 |
| handler_status | 派生：`available` \| `unavailable` |
| last_run_at | `last_run_at` |
| last_run_status | `last_run_status` |
| next_run_at | 实时计算（`ScheduleExpressionService`） |
| updated_at | `updated_at` |

### AdminScheduledTaskRunDTO

| 字段 | 来源 |
|------|------|
| id | `scheduled_task_runs.id` |
| task_id | `task_id` |
| task_name | JOIN `scheduled_tasks.name` |
| trigger_type | `trigger_type` |
| status | `status` |
| started_at | `started_at` |
| finished_at | `finished_at` |
| duration_ms | `duration_ms` |
| error_message | `error_message` |
| context | `context_json` |
| actor_staff_id | `actor_staff_id` |
| actor_login | JOIN `accounts.login`（手动时） |

### ValidateExpressionResultDTO

| 字段 | 类型 |
|------|------|
| valid | `boolean` |
| next_run_at | `string \| null` ISO8601 |
| error | `string \| null` |

---

## 与既有表关系

- `notification.cleanup` 执行仍删除 `learner_notifications` 并可能写 `audit_log`（`notification.cleanup`）；本模块额外写 `scheduled_task_runs`。
- 手动触发 `actor_staff_id` → `accounts.id`（后台账号）。

---

## 迁移顺序

1. `create_scheduled_tasks`
2. `create_scheduled_task_runs`
3. 数据种子（清理任务行）— 可在 migration 或 `ScheduledTaskSeeder`
