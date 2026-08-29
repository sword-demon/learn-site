# 管理端通知 API

前缀 `/api/admin/v1`。需管理端访问令牌；权限点 `notification.manage`。

## 发送公告

| 方法 | 路径 | 说明 |
|------|------|------|
| POST | `/notifications/announcements` | 向全体在册学员广播 |

**请求体**:

```json
{
  "title": "系统维护通知",
  "body": "本周六 2:00–4:00 进行维护，期间可能无法访问。"
}
```

**校验**:
- `title`: 非空，1–200 字符
- `body`: 非空，1–10000 字符

**成功 `201`**:

```json
{
  "ok": true,
  "data": {
    "id": 12,
    "type": "announcement",
    "title": "系统维护通知",
    "body": "本周六 2:00–4:00 进行维护，期间可能无法访问。",
    "sender_staff_id": 3,
    "sender_login": "admin",
    "recipient_summary": "全体学员",
    "recipient_count": 128,
    "created_at": "2026-08-29T14:00:00+08:00"
  }
}
```

**错误**:
- `403 FORBIDDEN` — 无 `notification.manage`
- `422 VALIDATION_FAILED` — 标题/正文为空或超长

---

## 发送站内信

| 方法 | 路径 | 说明 |
|------|------|------|
| POST | `/notifications/internal-messages` | 向指定学员发送 |

**请求体**:

```json
{
  "title": "学习提醒",
  "body": "您有一门课程已超过一周未继续学习。",
  "learner_ids": [101, 205]
}
```

**校验**:
- `title` / `body`: 同公告
- `learner_ids`: 非空数组；每项为正整数；须为 `status=active` 的在册学员；去重后至少 1 人

**成功 `201`**: 同上，`type=internal_message`，`recipient_summary` 如 `"2 名学员"`。

**错误**:
- `422 VALIDATION_FAILED` / `INVALID_RECIPIENTS` — 无有效收件人

---

## 查询发送记录

| 方法 | 路径 | 说明 |
|------|------|------|
| GET | `/notifications` | 分页列表 |
| GET | `/notifications/{id}` | 详情（含正文；站内信含收件人摘要列表） |

**列表查询参数**:

| 参数 | 类型 | 说明 |
|------|------|------|
| type | `announcement` \| `internal_message` | 可选筛选 |
| from | ISO8601 或 `YYYY-MM-DD` | 发送时间起 |
| to | ISO8601 或 `YYYY-MM-DD` | 发送时间止 |
| page | int | 默认 1 |
| limit | int | 默认 20，最大 100 |

**列表项字段**（不含 `body`）:

```json
{
  "items": [
    {
      "id": 12,
      "type": "announcement",
      "title": "系统维护通知",
      "sender_staff_id": 3,
      "sender_login": "admin",
      "recipient_summary": "全体学员",
      "recipient_count": 128,
      "created_at": "2026-08-29T14:00:00+08:00"
    }
  ],
  "total": 1,
  "page": 1,
  "limit": 20
}
```

**详情额外字段**:
- `body`: 完整正文
- `recipients`（仅站内信）: `[{ "id": 101, "login": "13800138000", "display_name": "学员甲" }]`

**错误**:
- `404 NOT_FOUND` — 记录不存在

---

## 审计

发送成功写入 `audit_log`:
- `action`: `notification.send`
- `target_type`: `notification_dispatch`
- `target_id`: dispatch id
- `payload_json`: `{ "type", "recipient_count", "title" }`

---

## Zod 契约位置（实现阶段）

- `packages/contracts/src/adminNotification.ts` — 请求/响应 DTO
- `packages/contracts/src/index.ts` — 导出

---

## 路由与鉴权

`Authorize` 中间件映射:

| 路径前缀 | 权限 |
|----------|------|
| `POST /api/admin/v1/notifications` | `notification.manage` |
| `GET /api/admin/v1/notifications` | `notification.manage` |

学习端令牌调用上述路径必须返回 `403`。
