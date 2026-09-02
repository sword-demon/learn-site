# 管理端课程意见反馈 API

前缀 `/api/admin/v1`. 需管理端访问令牌. 权限点 `course_feedback.manage` + 课程数据范围.

## 列表

| 方法 | 路径 |
|------|------|
| GET | `/courses/{courseId}/feedback` |

**查询**: `page`, `limit` (默认 20, 最大 100), `status?` = `pending` \| `processed`

排序: `created_at DESC`.

**成功 `200`**: `{ items, total, page, limit }`

item:

```json
{
  "id": 7,
  "course_id": 42,
  "learner": { "account_id": 101, "nickname": "小明" },
  "body_excerpt": "希望增加练习…",
  "status": "pending",
  "created_at": "2026-09-02T11:00:00+08:00",
  "processed_at": null
}
```

`body_excerpt` 为去标签后最多 80 字, 不是原始 HTML.

## 详情

| 方法 | 路径 |
|------|------|
| GET | `/courses/{courseId}/feedback/{feedbackId}` |

**成功 `200`**:

```json
{
  "id": 7,
  "course_id": 42,
  "learner": { "account_id": 101, "nickname": "小明" },
  "body_html": "<p>希望增加练习</p>",
  "status": "pending",
  "created_at": "2026-09-02T11:00:00+08:00",
  "processed_at": null,
  "processed_by_staff_id": null
}
```

`body_html` 已是服务端消毒结果, 管理端可用 `v-html` 展示.

**错误**: `404 FEEDBACK_NOT_FOUND`, `403 FORBIDDEN`.

## 更新处理状态

| 方法 | 路径 |
|------|------|
| PATCH | `/courses/{courseId}/feedback/{feedbackId}` |

**Body**: `{ "status": "processed" }` 或 `"pending"`

**成功 `200`**: 详情 DTO.

写审计 `course_feedback.status_change`.
