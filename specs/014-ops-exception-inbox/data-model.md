# Data Model: 运营异常收件箱

**Feature**: [spec.md](./spec.md)
**Date**: 2026-09-05

## Overview

The Ops Inbox is **derived** data — each row in the inbox is computed by reading an existing source table (courses, learning_maps, questions, reviews, orders, notification_dispatches) and re-shaping it into a uniform OpsException shape. Only user-driven state (acknowledge / snooze / assign) is persisted.

## Entity: OpsException (computed, not stored)

> The inbox row shape itself is not stored; it is computed per request from existing tables.

| Field | Type | Description |
|---|---|---|
| `id` | string (composite) | Composite key `${source_type}:${source_key}` — used by frontend for React-style keys, not a primary key |
| `source_type` | enum | `course_unpublished` \| `map_anomaly` \| `question_pending` \| `feedback_pending` \| `payment_unknown` \| `queue_failed` \| `long_pending` |
| `source_key` | string | Original primary key in the source table (e.g. course id) |
| `title` | string | Human-readable title for the row (e.g. "课程《Vue 入门》待发布") |
| `severity` | enum | `info` \| `warning` \| `critical` — derived from source_type weight + age |
| `age_seconds` | int | `now - source_created_at`, computed at query time |
| `age_label` | string | Formatted: `"3 天"` / `"12 小时"` / `"45 分钟"` |
| `weight` | int | From `SOURCE_WEIGHTS` map (private const on service) |
| `impact` | object | `{learners: int, orders_amount_cents?: int, courses: int, ...}` — derived per source |
| `suggested_action` | string | Pre-defined Chinese label, e.g. "审核发布课程" |
| `deep_link` | object | `{name: string, query?: Record<string, string\|number>}` for `router.push` |
| `state` | enum | `open` \| `retrying` \| `snoozed` \| `resolved` \| `assigned` — read from `ops_inbox_state` |
| `assignee_id` | int \| null | FK to admin accounts; null unless state = `assigned` |
| `snooze_until` | string \| null | ISO8601 with `Asia/Shanghai` offset; null unless state = `snoozed` |
| `last_error_code` | string \| null | For `queue_failed` items: vendor error code (e.g. `ZPay_TIMEOUT`) |
| `retry_count` | int | For `queue_failed` items: 0..N (N ≤ RETRY_MAX_ATTEMPTS) |

### Per-source impact derivation

| source_type | impact fields |
|---|---|
| `course_unpublished` | `{learners: int}` — count of learners with this course in active enrollment |
| `map_anomaly` | `{learners: int, courses: int}` — learners stuck on map + broken-stage courses |
| `question_pending` | `{learners: int}` — distinct learners asking similar questions |
| `feedback_pending` | `{learners: int, replies: int}` — learners affected + reply backlog |
| `payment_unknown` | `{orders_amount_cents: int, learners: int}` — sum amount + distinct learners |
| `queue_failed` | `{learners: int, retries: int}` — affected recipients + retry count |
| `long_pending` | `{sources: Record<source_type, int>}` — count per underlying source type |

## Entity: OpsInboxState (persisted)

> One row per (actor, source_type, source_key) tuple. Created when an operator first interacts with the inbox row.

```sql
CREATE TABLE ops_inbox_state (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    actor_id        BIGINT UNSIGNED NOT NULL COMMENT 'operator account id',
    source_type     VARCHAR(32) NOT NULL,
    source_key      VARCHAR(64) NOT NULL COMMENT 'original PK in source table',
    status          VARCHAR(16) NOT NULL DEFAULT 'open'
                    COMMENT 'open | snoozed | resolved | assigned',
    assignee_id     BIGINT UNSIGNED NULL COMMENT 'FK to accounts when assigned',
    snooze_until    DATETIME NULL,
    last_actor_id   BIGINT UNSIGNED NULL COMMENT 'who last changed status',
    created_at      DATETIME NOT NULL,
    updated_at      DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uk_actor_source (actor_id, source_type, source_key),
    KEY idx_status_snooze (status, snooze_until),
    KEY idx_actor_status (actor_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### State transitions

```
            acknowledge         assign
   open ───────────────► resolved ───────────► assigned
    │                      ▲                      │
    │ snooze                │ resolve              │ unassign
    ▼                      │                      ▼
  snoozed ─────────────────┘                    open
    │                                             ▲
    │ (cron: status='snoozed' AND               │
    │  snooze_until <= now                       │
    │  → status='open')                          │
    └─────────────────────────────────────────────┘

   (any state except resolved) ── retry ──► retrying
                                                    │
                                              (auto: success|exhausted)
                                                    ▼
                                                 open | resolved
```

State transition rules enforced by `OpsInboxService::transitionState()`:
- `open → snoozed`: requires `snooze_until > now + 60s`, max 30 days ahead
- `* → resolved`: any state; writes `audit_log` with action `ops_inbox.resolve`
- `open|retrying → assigned`: requires `assignee_id != null` and assignee ≠ actor
- `assigned|snoozed → open`: re-opens the row; clears `snooze_until` / `assignee_id`
- `open|retrying|snoozed|assigned → retrying`: only for `source_type=queue_failed`; resets `retry_count` from persisted dispatch state

## Entity: AuditLog entries (existing table, new action codes)

Reuses `audit_log` table; no schema change.

| action | when |
|---|---|
| `ops_inbox.acknowledge` | state transitions to `resolved` |
| `ops_inbox.snooze` | state transitions to `snoozed` (payload: snooze_until) |
| `ops_inbox.assign` | state transitions to `assigned` (payload: assignee_id) |
| `ops_inbox.retry` | manual retry trigger; sets state to `retrying` |
| `ops_inbox.retry_exhausted` | auto-retry exhausts retries; sets state to `open` from `retrying` |
| `ops_inbox.retry_succeeded` | auto-retry succeeds; sets state to `resolved` |

`actor_id` is the human operator; `actor_id = 0` for system actions (`retry_exhausted`, `retry_succeeded`, snooze-expiry sweep).

## Migration notes

- New migration: `apps/api/database/migrations/20260905000001_create_ops_inbox_state.php`
- Follows the Phinx shape used by `20260823000010_create_site.php` (no `updated_at` default, `datetime` not `timestamp`, `utf8mb4_unicode_ci` collation, `biginteger identity` PK)
- Down migration drops the table; up migration creates indexes in the same statement (Phinx handles inside the same `->create()`)
- No backfill needed — table starts empty

## Validation rules

| Rule | Where enforced |
|---|---|
| `snooze_until > now + 60s` | `OpsInboxService::transitionState()` |
| `snooze_until <= now + 30 days` | same |
| `assignee_id` must be a valid admin account id | `OpsInboxService::assertValidAssignee()` (private) |
| `source_type` must be one of the 7 enum values | Service private const, server-side `BusinessException('VALIDATION_FAILED', 'OPS_SOURCE_INVALID')` |
| Status transition must follow the allowed map | Service private const `ALLOWED_TRANSITIONS`; throws `CONFLICT` on invalid move |