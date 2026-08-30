# 学习端每日签到 API

前缀 `/api/learner/v1`。写操作与列表需学员访问令牌（`LearnerAuth`）。

## 查询今日签到状态

| 方法 | 路径 | 说明 |
|------|------|------|
| GET | `/checkins/today` | 当日是否已签到 |

**成功 `200`**:

```json
{
  "ok": true,
  "data": {
    "server_date": "2026-08-30",
    "checked_in": false,
    "record": null
  }
}
```

已签到时 `checked_in: true`，`record` 为当日 `LearnerCheckinDTO`。

**错误**:
- `401 UNAUTHORIZED` — 未登录

---

## 提交签到

| 方法 | 路径 | 说明 |
|------|------|------|
| POST | `/checkins` | 当日签到并提交每日计划 |

**请求体**:

```json
{
  "plan_html": "<p>今日计划：<strong>完成第 3 章</strong></p><ul><li>视频 1</li></ul>"
}
```

**校验**:
- `plan_html`: 非空；经服务端净化后须有可见文本；长度 1–10000 字符（净化后）
- 仅允许签到「站点今日」；客户端不得传日期字段

**成功 `201`**:

```json
{
  "ok": true,
  "data": {
    "id": 42,
    "checkin_date": "2026-08-30",
    "plan_html": "<p>今日计划：<strong>完成第 3 章</strong></p><ul><li>视频 1</li></ul>",
    "checked_in_at": "2026-08-30T09:15:00+08:00"
  }
}
```

**错误**:
- `401 UNAUTHORIZED` — 未登录
- `403 ACCOUNT_DISABLED` — 学员已停用
- `409 ALREADY_CHECKED_IN` — 今日已签到
- `422 VALIDATION_FAILED` — 计划为空、仅空富文本壳或超长

---

## 查询本人签到历史

| 方法 | 路径 | 说明 |
|------|------|------|
| GET | `/checkins` | 分页列表（仅本人） |

**查询参数**:

| 参数 | 类型 | 默认 | 说明 |
|------|------|------|------|
| page | int | 1 | ≥1 |
| limit | int | 20 | 1–100 |

**成功 `200`**:

```json
{
  "ok": true,
  "data": {
    "items": [
      {
        "id": 42,
        "checkin_date": "2026-08-30",
        "plan_html": "<p>...</p>",
        "checked_in_at": "2026-08-30T09:15:00+08:00"
      }
    ],
    "total": 15,
    "page": 1,
    "limit": 20
  }
}
```

**排序**: `checkin_date DESC`

**错误**:
- `401 UNAUTHORIZED` — 未登录

---

## 查询单条本人记录（可选）

| 方法 | 路径 | 说明 |
|------|------|------|
| GET | `/checkins/{id}` | 详情 |

**成功 `200`**: `LearnerCheckinDTO`

**错误**:
- `404 NOT_FOUND` — 不存在或不属于当前学员

---

## DTO 摘要（Zod 见 `packages/contracts/src/dailyCheckin.ts`）

```ts
LearnerCheckinDTO = {
  id: number
  checkin_date: string      // YYYY-MM-DD
  plan_html: string
  checked_in_at: string     // ISO8601
}

LearnerTodayCheckinDTO = {
  server_date: string
  checked_in: boolean
  record: LearnerCheckinDTO | null
}

CreateCheckinInput = {
  plan_html: string
}
```

---

## 审计

学员签到成功写入 `audit_log`：

- `action`: `checkin.create`
- `actor_id`: 学员 `account_id`
- `target_type`: `learner_daily_checkin`
- `target_id`: 记录 `id`

同一学员重复提交当日签到被唯一约束拒绝时，写入 `audit_log`：

- `action`: `checkin.duplicate_rejected`
- `actor_id`: 学员 `account_id`
- `target_type`: `learner_daily_checkin`
- `target_id`: 已存在的当日签到记录 `id`
- `payload_json`: `{ "checkin_date": "2026-08-30" }`
