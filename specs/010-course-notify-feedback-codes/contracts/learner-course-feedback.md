# 学习端课程意见反馈 API

前缀 `/api/learner/v1`. 需学员访问令牌.

## 提交

| 方法 | 路径 |
|------|------|
| POST | `/courses/{courseId}/feedback` |

**Body**: `{ "body_html": "<p>建议…</p>" }`

服务端 `HtmlSanitizer::sanitize`. 消毒后可见文本为空 → `422 FEEDBACK_BODY_REQUIRED`. 原始长度 > 20_000 → `422 FEEDBACK_BODY_TOO_LONG`.

学员必须对该课有 **active** 课程访问权, 否则 `403 COURSE_ACCESS_REQUIRED`. 课程须存在; 非已发布且学员已无权打开时按既有课程可见性返回 `404 COURSE_NOT_FOUND` (不泄露草稿).

**成功 `201`**:

```json
{
  "id": 7,
  "course_id": 42,
  "status": "pending",
  "created_at": "2026-09-02T11:00:00+08:00"
}
```

同一学员同一课允许多条. 响应不含回显 HTML 以外的管理字段.

首版不提供学员侧反馈列表或编辑删除.
