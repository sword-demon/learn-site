# Data Model: 课程发布核验清单

**Feature**: [spec.md](./spec.md)
**Date**: 2026-09-06

## Overview

The publish checklist is **computed**, not stored as its own table. Each GET or POST rebuilds it from live catalog, asset, map, entitlement, enrollment, and account rows. The only persistence is `audit_log` after a publish attempt (see [research.md](./research.md) D2).

No Phinx migration is required. `learner_notifications.kind` is already `VARCHAR(32)`, so `progress_catalog_changed` is a new value, not a schema change.

## Entity: PublishChecklist (computed)

| Field | Type | Description |
|---|---|---|
| `course_id` | int | Course being verified |
| `course_title` | string | Display title |
| `course_status` | `draft` \| `published` \| `unpublished` | Status **before** this action |
| `generated_at` | string | ISO 8601 with `+08:00` (`Asia/Shanghai`) |
| `content_fingerprint` | string | SHA-256 of the catalog snapshot; excludes live counts and probe findings |
| `hard_error_count` | int | Count of findings with `severity = hard` |
| `warning_count` | int | Count of findings with `severity = warning` |
| `can_publish` | bool | `hard_error_count === 0` AND NOT `impact.notification.recipient_unavailable` AND (`course_status === 'published'` is still true for idempotent POST, but UI treats it as "already published") |
| `catalog` | object | Deterministic inventory (below) |
| `findings` | Finding[] | Graded list; may be empty |
| `impact` | ImpactSummary | Four required bars |

`can_publish` for a **status-changing** publish is `hard_error_count === 0 && !impact.notification.recipient_unavailable`. Already-published GET still returns the list; POST does not require `can_publish` to no-op.

### catalog

| Field | Type | Description |
|---|---|---|
| `category_id` | int \| null | |
| `category_name` | string \| null | |
| `category_enabled` | bool | false if missing or `disabled` |
| `intro_present` | bool | Sanitized intro HTML non-empty |
| `price_mode` | `free` \| `paid` | |
| `price_valid` | bool | Same rules as current `assertPublishable` sale window |
| `effective_chapter_count` | int | Enabled chapters that contain ≥1 有效课节 |
| `effective_lesson_count` | int | 有效课节 |
| `trial_lesson_count` | int | Enabled 有效 or not, `is_preview = true` |
| `chapters` | CatalogChapter[] | **All** chapters, including disabled, in `sort` order |

### CatalogChapter / CatalogLesson

| Field | Type | Description |
|---|---|---|
| `id` | int | |
| `title` | string | |
| `sort` | int | |
| `status` | `enabled` \| `disabled` | Disabled = 归档 for this feature |
| `is_effective_chapter` | bool | Chapter only |
| `lessons` | CatalogLesson[] | Chapter only, `sort` order |
| `content_type` | `markdown` \| `pdf` \| `video` | Lesson |
| `is_preview` | bool | Lesson; contract field name stays `is_preview` |
| `is_effective` | bool | D5 predicate |
| `asset_id` | int \| null | |
| `asset_status` | `processing` \| `ready` \| `missing` \| `broken` \| null | null when no asset |

## Entity: Finding (computed)

| Field | Type | Description |
|---|---|---|
| `code` | string | Stable code from the table below |
| `severity` | `hard` \| `warning` \| `info` | Exactly one |
| `message` | string | Chinese operator copy |
| `scope` | `course` \| `chapter` \| `lesson` | |
| `chapter_id` | int \| null | |
| `lesson_id` | int \| null | |

### Finding codes

**Hard** (block status flip and fan-out):

| Code | When |
|---|---|
| `CATEGORY_NOT_FOUND` | `category_id` missing |
| `CATEGORY_DISABLED` | Category not `enabled` |
| `INTRO_REQUIRED` | Sanitized intro empty |
| `SALE_WINDOW_EXPIRED` | `sale_price > 0` and window invalid or not containing now |
| `NO_PUBLISHABLE_CHAPTER` | No enabled chapter |
| `NO_PUBLISHABLE_LESSON` | No 有效课节 |
| `NOTIFICATION_IMPACT_UNAVAILABLE` | Active-learner snapshot failed |

**Warning** (publish allowed after `acknowledge_warnings`):

| Code | When |
|---|---|
| `LESSON_INCOMPLETE` | Enabled lesson without complete payload, and at least one 有效课节 exists |
| `ASSET_PROCESSING` | Bound asset `processing` |
| `ASSET_MISSING` | Bound asset `missing` or `asset_id` required but 0 |
| `ASSET_BROKEN` | Bound asset `broken` |
| `ASSET_UNREACHABLE` | Ready asset probe failed or timed out |
| `ASSET_PROBE_SKIPPED` | Ready asset not probed because the 5s budget ended |
| `PROGRESS_DENOMINATOR_CHANGES` | `impact.progress.will_recalculate === true` |

**Info** (never blocks, no checkbox):

| Code | When |
|---|---|
| `NO_TRIAL_LESSON` | `trial_lesson_count = 0` |
| `ALL_LESSONS_TRIAL` | Every listed enabled lesson is preview |
| `MAP_REFERENCE` | Published map cites this course and it is already published (no recovery) |
| `MAP_STEP_WILL_RECOVER` | Published map cites this course and current status ≠ published |
| `NOTIFICATION_WILL_SEND` | Status will change to published |
| `NOTIFICATION_SKIPPED_ALREADY_PUBLISHED` | Status already published |

One finding per (code, lesson_id or chapter_id or course). Do not collapse all incomplete lessons into a single row.

## Entity: ImpactSummary (computed)

### maps

| Field | Type | Description |
|---|---|---|
| `published_count` | int | Length of `items` |
| `draft_count` | int | Draft maps citing this course; not in `items` |
| `items` | `{ id, title, will_recover_abnormal_step }[]` | Published maps only |

Zero published maps → `published_count = 0`, `items = []` (never omit the bar).

### entitlements

| Field | Type | Description |
|---|---|---|
| `active_count` | int | `course_entitlements` with `status = active` |

### progress

| Field | Type | Description |
|---|---|---|
| `enrollment_count` | int | `course_enrollments` rows for this course |
| `current_denominator` | int | Enabled lesson count (legacy percent formula) |
| `next_denominator` | int | 有效课节 count |
| `will_recalculate` | bool | `enrollment_count > 0 && current_denominator !== next_denominator` |
| `completed_preserved` | `true` | Constant; documents FR-026 |

### notification

| Field | Type | Description |
|---|---|---|
| `will_dispatch` | bool | True only when this POST would change status to `published` |
| `recipient_count` | int \| null | Active learner snapshot count; null if unavailable |
| `recipient_unavailable` | bool | Snapshot threw; forces hard finding |

`recipient_count` is **not** `entitlements.active_count`.

## State transitions (course)

Unchanged from `001`:

```
draft ──(checklist hard=0)──► published
unpublished ──(checklist hard=0)──► published
published ──unpublish──► unpublished
published ──POST publish──► published   (idempotent, no dispatch)
```

New gates on the two arrows into `published`:

1. Build checklist at confirm time.
2. If `hard_error_count > 0` or `recipient_unavailable`: stay, no dispatch.
3. If `warning_count > 0` and `acknowledge_warnings !== true`: stay, no dispatch.
4. Else flip, audit, then existing `notifyCoursePublished`, then maybe progress recalc.

GET never transitions.

## Persistence: audit_log

| Column | Value |
|---|---|
| `actor_id` | Staff account |
| `action` | `course.publish` or `course.publish.rejected` |
| `target_type` | `course` |
| `target_id` | Course id |
| `payload_json` | `{ content_fingerprint, hard_error_count, warning_count, finding_codes, will_dispatch, recipient_count, acknowledge_warnings }` |
| `created_at` | Shanghai datetime |

`CourseService` currently logs `course.published` via `Logger` only; 015 adds `writeAudit()`.

## Persistence: learner_notifications (progress only)

Emitted **after** a status-changing publish when `will_recalculate` is true, one row per enrollment learner:

| Column | Value |
|---|---|
| `kind` | `progress_catalog_changed` |
| `resource_type` | `course` |
| `resource_id` | Course id |
| `idempotency_key` | `progress_catalog_changed:{courseId}:{fingerprint}` |
| `title` / `body` | 目录有效课节已更新; 进度已重算; 已完成课节保留 |

Not sent to the all-learners fan-out.

## Unchanged entities (must not be rewritten)

- `orders` snapshot columns: `list_price_snapshot`, `sale_price_snapshot`, `coupon_discount_snapshot`, `paid_amount` (and payment timestamps). There is no `title_snapshot`.
- `lesson_progresses.completed` once set to 1
- `course_enrollments.completed_at` once set (sticky; percent may still drop)
- `course_entitlements` rows (publish does not grant or revoke)
- `notification_dispatches` creation rules from `010` (still after successful flip; enqueue failure does not roll back). Map `available` remains derived from `courses.status`.

## Validation rules (service)

1. Data scope: same `DataScopeService::assertCourseAccessibleFromScope` as `publishCourse`.
2. 有效课节 predicate is the public, pure `CoursePublishChecklistService::isEffectiveLesson` method shared by checklist, publish-time recalculation and ordinary progress updates. A private method cannot be shared across these services.
3. Probe never mutates `is_effective`.
4. Fingerprint ignores `generated_at`, probe findings, entitlement/map/learner counts.
5. No super-admin bypass of hard findings.
