# 管理端每日签到 API

前缀 `/api/admin/v1`。需管理端访问令牌；权限点 `checkin.manage`。

## 查询签到记录列表

| 方法 | 路径 | 说明 |
|------|------|------|
| GET | `/checkins` | 全部学员签到记录分页 |

**查询参数**:

| 参数 | 类型 | 默认 | 说明 |
|------|------|------|------|
| page | int | 1 | ≥1 |
| limit | int | 20 | 1–100 |
| learner_id | int | — | 可选，筛选指定学员 |
| date_from | string | — | 可选，`YYYY-MM-DD`，含当日 |
| date_to | string | — | 可选，`YYYY-MM-DD`，含当日 |

**成功 `200`**:

```json
{
  "ok": true,
  "data": {
    "items": [
      {
        "id": 42,
        "learner_id": 101,
        "learner_display_name": "小明",
        "learner_phone_masked": "138****5678",
        "checkin_date": "2026-08-30",
        "plan_summary": "今日计划：完成第 3 章…",
        "checked_in_at": "2026-08-30T09:15:00+08:00"
      }
    ],
    "total": 128,
    "page": 1,
    "limit": 20
  }
}
```

**排序**: `checked_in_at DESC`

**错误**:
- `403 FORBIDDEN` — 无 `checkin.manage`

---

## 查询签到详情

| 方法 | 路径 | 说明 |
|------|------|------|
| GET | `/checkins/{id}` | 单条完整记录 |

**成功 `200`**:

```json
{
  "ok": true,
  "data": {
    "id": 42,
    "learner_id": 101,
    "learner_display_name": "小明",
    "learner_phone_masked": "138****5678",
    "checkin_date": "2026-08-30",
    "plan_html": "<p>今日计划：<strong>完成第 3 章</strong></p>",
    "checked_in_at": "2026-08-30T09:15:00+08:00"
  }
}
```

**错误**:
- `403 FORBIDDEN`
- `404 NOT_FOUND`

---

## 删除签到记录

| 方法 | 路径 | 说明 |
|------|------|------|
| DELETE | `/checkins/{id}` | 物理删除 |

**成功 `204`**: 无 body

**副作用**:
- 记录对学员与管理端不可见
- 对应学员在该 `checkin_date` 可重新签到
- 写入 `audit_log`（`checkin.delete`）

**错误**:
- `403 FORBIDDEN`
- `404 NOT_FOUND`

---

## DTO 摘要（Zod 见 `packages/contracts/src/dailyCheckin.ts`）

```ts
AdminCheckinListItemDTO = {
  id: number
  learner_id: number
  learner_display_name: string | null
  learner_phone_masked: string
  checkin_date: string
  plan_summary: string
  checked_in_at: string
}

AdminCheckinDetailDTO = AdminCheckinListItemDTO & {
  plan_html: string
}
```

---

## 审计

管理员删除写入 `audit_log`：

- `action`: `checkin.delete`
- `actor_id`: 后台 `staff_id`
- `target_type`: `learner_daily_checkin`
- `target_id`: 被删记录 `id`
- `payload_json`: `{ "learner_id": 101, "checkin_date": "2026-08-30" }`
