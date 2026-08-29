# 学习端通知 API（扩展）

前缀 `/api/learner/v1`。在 [001 learner-api](../../001-personal-learning-site/contracts/learner-api.md) 既有消息接口上扩展。

## 消息列表（扩展）

| 方法 | 路径 | 鉴权 | 说明 |
|------|------|------|------|
| GET | `/messages` | 学员 | 分页列表；含系统通知 + 公告 + 站内信 |

**查询参数**: `page`（默认 1）、`limit`（默认 20，最大 100）

**响应项扩展** — `kind` 新增取值:

| kind | 展示标签 | 说明 |
|------|----------|------|
| `question_update` | 问答 | 既有 |
| `progress_reset` | 学习进度 | 既有 |
| `entitlement_revoked` | 课程授权 | 既有 |
| `announcement` | 公告 | 管理员广播 |
| `internal_message` | 站内信 | 管理员定向 |

**新增字段**:

```json
{
  "id": 501,
  "kind": "announcement",
  "title": "系统维护通知",
  "body": "...",
  "dispatch_id": 12,
  "resource_type": null,
  "resource_id": null,
  "resource_available": false,
  "payload": null,
  "read": false,
  "created_at": "2026-08-29T14:00:00+08:00"
}
```

- `dispatch_id`: 运营消息关联发送记录；系统事件为 `null`
- 公告/站内信无 `resource_type` 跳转链接（首版不展示「查看关联内容」）

**错误**: 未登录 `401 UNAUTHENTICATED`

---

## 未读数量（新增）

| 方法 | 路径 | 鉴权 | 说明 |
|------|------|------|------|
| GET | `/messages/unread-count` | 学员 | 当前未读总数 |

**成功 `200`**:

```json
{
  "ok": true,
  "data": { "count": 3 }
}
```

计数规则: `learner_notifications` 中 `learner_id = 当前学员` 且 `read_at IS NULL`。

---

## 标记已读（既有）

| 方法 | 路径 | 说明 |
|------|------|------|
| POST | `/messages/{id}/read` | 单条幂等标记已读 |

**成功**: `{ "read": true }`

**错误**:
- `404 MESSAGE_NOT_FOUND` — 非本人或不存在
- `401 UNAUTHENTICATED`

---

## 实时推送（WebSocket）

**非 REST**；基于 `webman/push`，Pusher 兼容协议。

| 项 | 值 |
|----|-----|
| 连接 URL | 开发: `ws://{host}:3131`；生产: `wss://{learner-host}/app/{app_key}`（Nginx 反代） |
| 频道 | `private-learner-{learnerId}` |
| 鉴权 | `POST /plugin/webman/push/auth`（扩展：校验学员 access token，`channel_name` 中 id 须等于 token 的 `account_id`） |
| 事件名 | `notification` |
| 载荷 | `{ "id": 501, "kind": "announcement", "title": "...", "unread_count": 3 }` |

**客户端行为**:
1. 登录后建立 Push 连接并订阅 `private-learner-{accountId}`
2. 收到 `notification` 事件 → 更新导航未读角标；若当前在消息页则 prepend 或触发列表刷新
3. 连接失败时静默降级；打开消息页或调用 `GET /messages/unread-count` 兜底

**推送触发时机**:
- `MessageService::emit`（系统事件）写入 DB 后
- 管理员发送公告/站内信 fan-out 每块完成后

---

## Zod 契约变更（实现阶段）

扩展 `packages/contracts/src/notification.ts`:

```ts
kind: z.enum([
  'question_update',
  'progress_reset',
  'entitlement_revoked',
  'announcement',
  'internal_message',
])
dispatch_id: z.number().int().positive().nullable().optional()

// 新增
LearnerUnreadCountDTO = z.object({ count: z.number().int().nonnegative() })
```

---

## 安全边界

- 学员只能 `GET /messages`、`GET /messages/unread-count`、`POST /messages/{id}/read` 访问本人数据
- Push 私有频道鉴权失败返回 `403`；不得订阅 `private-learner-{他人id}`
- 管理端令牌不得调用学习端消息接口（既有隔离规则不变）
