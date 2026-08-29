# 管理端自动任务 API

前缀 `/api/admin/v1`。需管理端访问令牌；权限点 `scheduled_task.manage`。

## 查询任务列表

| 方法 | 路径 | 说明 |
|------|------|------|
| GET | `/scheduled-tasks` | 全部已注册任务 |

**成功 `200`**:

```json
{
  "ok": true,
  "data": [
    {
      "id": 1,
      "handler_code": "notification.cleanup",
      "name": "学员消息收件箱过期清理",
      "description": "删除创建时间超过 2 个月的学员收件箱记录",
      "schedule_expression": "0 30 3 * * *",
      "enabled": true,
      "params": { "batch_size": 500 },
      "handler_status": "available",
      "last_run_at": "2026-08-29T03:30:00+08:00",
      "last_run_status": "success",
      "next_run_at": "2026-08-30T03:30:00+08:00",
      "updated_at": "2026-08-28T10:00:00+08:00"
    }
  ]
}
```

**错误**:
- `403 FORBIDDEN` — 无 `scheduled_task.manage`

---

## 查询任务详情

| 方法 | 路径 | 说明 |
|------|------|------|
| GET | `/scheduled-tasks/{id}` | 单条任务 |

**成功 `200`**: 单条 `AdminScheduledTaskListItemDTO`（同上结构）。

**错误**:
- `404 NOT_FOUND`

---

## 更新任务配置

| 方法 | 路径 | 说明 |
|------|------|------|
| PATCH | `/scheduled-tasks/{id}` | 更新表达式、启用状态、参数 |

**请求体**（字段均可选，至少一项）:

```json
{
  "schedule_expression": "0 0 4 * * *",
  "enabled": true,
  "params": { "batch_size": 500 }
}
```

**校验**:
- `schedule_expression`: 六段式、 `Parser::isValid`、最短间隔 ≥ 60s
- `enabled=true` 要求 `handler_status=available`
- `params` 由 handler 校验

**成功 `200`**: 更新后的任务 DTO。

**错误**:
- `403 FORBIDDEN`
- `404 NOT_FOUND`
- `422 VALIDATION_FAILED` — 非法表达式
- `422 MIN_INTERVAL_VIOLATION` — 调度过于频繁
- `422 HANDLER_UNAVAILABLE` — 处理器已下线仍尝试启用

**副作用**: 写 `audit_log`（`scheduled_task.update`）；`updated_at` 变化触发 runner 30s 内 reload。

---

## 校验表达式并预览下次执行

| 方法 | 路径 | 说明 |
|------|------|------|
| POST | `/scheduled-tasks/validate-expression` | 保存前预览 |

**请求体**:

```json
{
  "schedule_expression": "0 30 3 * * *"
}
```

**成功 `200`**:

```json
{
  "ok": true,
  "data": {
    "valid": true,
    "next_run_at": "2026-08-30T03:30:00+08:00",
    "error": null
  }
}
```

非法示例:

```json
{
  "ok": true,
  "data": {
    "valid": false,
    "next_run_at": null,
    "error": "表达式不符合六段式 cron 语法"
  }
}
```

---

## 手动触发执行

| 方法 | 路径 | 说明 |
|------|------|------|
| POST | `/scheduled-tasks/{id}/run` | 立即运行一次 |

**请求体**: 空

**成功 `200`**:

```json
{
  "ok": true,
  "data": {
    "run_id": 42,
    "task_id": 1,
    "trigger_type": "manual",
    "status": "success",
    "started_at": "2026-08-29T15:00:00+08:00",
    "finished_at": "2026-08-29T15:00:02+08:00",
    "duration_ms": 2100
  }
}
```

**错误**:
- `403 FORBIDDEN`
- `404 NOT_FOUND`
- `409 TASK_ALREADY_RUNNING` — 该任务正在执行
- `422 HANDLER_UNAVAILABLE`

**副作用**: `audit_log`（`scheduled_task.run`）；写入 `scheduled_task_runs`。

---

## 查询执行日志列表

| 方法 | 路径 | 说明 |
|------|------|------|
| GET | `/scheduled-tasks/runs` | 分页列表 |

**查询参数**:
- `task_id` — 可选，整数
- `status` — 可选，`success` \| `failed` \| `skipped`
- `trigger_type` — 可选，`schedule` \| `manual`
- `started_from` / `started_to` — 可选，ISO8601 或 `YYYY-MM-DD`
- `page` — 默认 1
- `per_page` — 默认 20，最大 100

**成功 `200`**:

```json
{
  "ok": true,
  "data": {
    "items": [
      {
        "id": 42,
        "task_id": 1,
        "task_name": "学员消息收件箱过期清理",
        "trigger_type": "schedule",
        "status": "success",
        "started_at": "2026-08-29T03:30:00+08:00",
        "finished_at": "2026-08-29T03:30:02+08:00",
        "duration_ms": 1800,
        "error_message": null,
        "actor_staff_id": null,
        "actor_login": null
      }
    ],
    "page": 1,
    "per_page": 20,
    "total": 1
  }
}
```

---

## 查询执行日志详情

| 方法 | 路径 | 说明 |
|------|------|------|
| GET | `/scheduled-tasks/runs/{id}` | 单条日志 |

**成功 `200`**: 扩展 `AdminScheduledTaskRunDTO`，含 `context`（`context_json` 对象）。

**错误**:
- `404 NOT_FOUND`

---

## Zod 契约（`packages/contracts`）

新增 `adminScheduledTask.ts`:

- `AdminScheduledTaskSchema`
- `AdminScheduledTaskRunSchema`
- `ValidateExpressionResultSchema`
- `UpdateScheduledTaskBodySchema`
- `ScheduledTaskRunListSchema`（分页）

导出自 `packages/contracts/src/index.ts`。

---

## 鉴权映射（`Authorize.php`）

| 路径前缀 | 权限 |
|----------|------|
| `/api/admin/v1/scheduled-tasks` | `scheduled_task.manage` |
