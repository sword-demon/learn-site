# 课程发布通知契约 (扩展 003 / 008)

前缀与鉴权沿用 `specs/003-admin-notifications/contracts/`. 本文件只记录增量.

## 学习端消息列表

`GET /api/learner/v1/messages` 的 `kind` 增加:

| kind | 展示标签 | resource |
|------|----------|----------|
| `course_published` | 新课 | `resource_type=course`, `resource_id=课程 id` |

点击: 学习端已有 `resource_type=course` → `/courses/{id}`. `resource_available` 规则不变: 课程 `status=published` 才为 true; 否则展示「关联内容已不可用」.

Push 事件 `notification` 载荷 `kind` 同样可取 `course_published`. 未读计数仍走 `UnreadCounterService`.

## 管理端发送记录

`GET /api/admin/v1/notifications` 的 `type` 增加 `course_published`.

列表/详情增量字段:

```json
{
  "type": "course_published",
  "resource_type": "course",
  "resource_id": 42,
  "fan_out_status": "pending",
  "fan_out_done_count": 0,
  "fan_out_error": null
}
```

`course_published` 不可通过「发公告/站内信」表单创建. 由 `POST /api/admin/v1/courses/{id}/publish` 副作用生成.

筛选 `type=course_published` 必须可用. `recipient_summary` 为「全体在册学员」.

## 补投

| 方法 | 路径 | 权限 |
|------|------|------|
| POST | `/api/admin/v1/notifications/{id}/retry` | `notification.manage` |

仅当 `fan_out_status` 为 `failed` 或 `pending` 时成功, 重新入队 `notification.fan_out`. 已 `completed` / `running` → `409 DISPATCH_NOT_RETRYABLE`.

**成功 `200`**: 该条 dispatch 的详情 (status 回到 pending 或 running).

## 发布副作用 (非独立资源)

`POST /api/admin/v1/courses/{id}/publish` (既有, 权限 `course.publish`):

- 状态实际从非 `published` 变为 `published` 时, 必须创建 `course_published` dispatch 并入队.
- 已是 `published` 的幂等返回 **不得** 再建 dispatch.
- 入队失败: 课程保持已发布; dispatch `fan_out_status=failed`; 发布 API 仍 `200`.
- 响应不必等待 fan-out 完成. 可选在课程树响应中带 `publish_dispatch_id` (非必须, 实现阶段若加则写入 contracts Zod).

## Zod (`packages/contracts`)

`LearnerNotificationKind` 增加 `course_published`.

`NotificationType` 增加 `course_published`.

`AdminNotificationListItemDTO` 增加可空 `resource_type`, `resource_id`, `fan_out_status`, `fan_out_done_count`.
