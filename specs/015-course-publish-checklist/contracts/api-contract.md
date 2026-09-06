# HTTP API Contract: 课程发布核验清单

**Feature**: [spec.md](../spec.md)
**Date**: 2026-09-06

Authentication: existing `AdminAuth`. Authorization: existing `Authorize` (see permission column). Envelope: `{ ok, data, meta.request_id }` / `{ ok: false, error: { code, message, ...details } }`. Datetimes: ISO 8601 with `+08:00`. Zod source: [course-publish-checklist.ts](./course-publish-checklist.ts) and `packages/contracts/src/coursePublishChecklist.ts` (implement phase).

## Conventions

- Server validation throws `BusinessException(VALIDATION_FAILED, <STABLE_MESSAGE>, details)`.
- `CourseController::wrap` **must** forward `$e->details` into `ApiResponse::fail` so `error.checklist` is visible to the admin SPA.
- Stable messages used as `error.message`: `PUBLISH_CHECK_FAILED`, `WARNINGS_NOT_ACKNOWLEDGED`, `NOTIFICATION_IMPACT_UNAVAILABLE`, plus existing `COURSE_NOT_FOUND` / `FORBIDDEN`.

## Endpoints

### GET /api/admin/v1/courses/{id}/publish-checklist

Permission: `course.view` + course data scope.

Does not change course status, entitlements, progress, orders, or notifications.

**Response 200** (`PublishChecklistDTO`):

```json
{
  "ok": true,
  "data": {
    "course_id": 12,
    "course_title": "示例课",
    "course_status": "draft",
    "generated_at": "2026-09-06T21:00:00+08:00",
    "content_fingerprint": "sha256:…",
    "hard_error_count": 0,
    "warning_count": 1,
    "can_publish": true,
    "catalog": {},
    "findings": [],
    "impact": {}
  }
}
```

**Errors**:

| HTTP | `error.code` | `error.message` | When |
|---|---|---|---|
| 404 | `NOT_FOUND` | `COURSE_NOT_FOUND` | Unknown id |
| 403 | `FORBIDDEN` | `FORBIDDEN` | Out of data scope or missing `course.view` |

`Authorize` must register this path explicitly. It does not match `^/api/admin/v1/courses(?:/\d+)?$`.

### POST /api/admin/v1/courses/{id}/publish

Permission: `course.publish` + course data scope.

**Request body** (JSON, all optional):

```json
{ "acknowledge_warnings": true }
```

Zod: `PublishCourseInput`. Missing body is treated as `acknowledge_warnings: false`.

**Behaviour**:

1. Load course, assert scope.
2. If already `published`: return current tree; no dispatch; no audit beyond existing idempotent log if any.
3. `checklist = build()`.
4. If `recipient_unavailable` or `hard_error_count > 0`: 422, `error.checklist = checklist`, no status change, no dispatch.
5. If `warning_count > 0` and `acknowledge_warnings !== true`: 422 `WARNINGS_NOT_ACKNOWLEDGED`, `error.checklist = checklist`.
6. Else flip to `published`, `writeAudit(course.publish)`, `notifyCoursePublished` (existing, must not fail the HTTP success), recalculate enrollments when `enrollment_count > 0`.

**Response 200**: existing course tree shape (`CourseTreeDTO`) so current admin `publishCourse` and PHPUnit stay valid.

**Errors**:

| HTTP | `error.code` | `error.message` | `error.checklist` |
|---|---|---|---|
| 422 | `VALIDATION_FAILED` | `PUBLISH_CHECK_FAILED` | yes |
| 422 | `VALIDATION_FAILED` | `WARNINGS_NOT_ACKNOWLEDGED` | yes |
| 422 | `VALIDATION_FAILED` | `NOTIFICATION_IMPACT_UNAVAILABLE` | yes |
| 404 | `NOT_FOUND` | `COURSE_NOT_FOUND` | no |
| 403 | `FORBIDDEN` | `FORBIDDEN` | no |

Frontend: on 422 with `checklist`, replace dialog state; do not close.

### Unchanged

- `POST /courses/{id}/unpublish` — out of scope (FR-034).
- Learner APIs — no checklist exposure (FR-033).
- `010` fan-out completion timing — enqueue still happens after HTTP success.

## Permission map additions

```
GET  /api/admin/v1/courses/{id}/publish-checklist  → course.view
POST /api/admin/v1/courses/{id}/publish            → course.publish (existing)
```

No new permission seed row.

## Admin UI contract (non-HTTP)

Dialog `CoursePublishChecklistDialog`:

- Open → GET checklist.
- Hard findings: `el-alert type="error"`.
- Warnings: `el-alert type="warning"`.
- Info: `el-alert type="info"`.
- Impact: four blocks; notification block always visible; `recipient_count` emphasized.
- Checkbox "我已知晓以上警告与通知影响" shown when `warning_count > 0` or `will_dispatch`.
- Primary disabled when `hard_error_count > 0` or `recipient_unavailable` or (warnings and checkbox off).
- Confirm → POST `{ acknowledge_warnings }`.
- `v-if="hasPermission('course.publish')"` on the three publish buttons.
