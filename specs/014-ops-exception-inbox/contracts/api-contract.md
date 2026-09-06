# HTTP API Contract: 运营异常收件箱

**Feature**: [spec.md](../spec.md)
**Date**: 2026-09-05

All endpoints are mounted under `/api/admin/v1/ops-inbox`. Authentication via existing `AdminAuth` middleware. Authorization via existing `Authorize` middleware (path → `ops_inbox.view`).

## Conventions

- All responses use the `ApiResponse::ok()` envelope: `{ok: true, data: T, request_id: string}`
- Error envelope: `{ok: false, code: string, message: string, request_id: string}`
- Server-side validation throws `BusinessException(VALIDATION_FAILED, <STABLE_CODE>)` → mapped to `ApiResponse::VALIDATION_FAILED` (HTTP 422)
- All `datetime` strings are ISO 8601 with `+08:00` offset (Asia/Shanghai)
- All money fields are integer cents (no floats)
- Stable error codes: see `contracts/ops-inbox-contract.ts` `OPS_ERROR_CODES`

## Endpoints

### GET /api/admin/v1/ops-inbox

List inbox rows for the authenticated actor, filtered by data scope + permissions.

**Query parameters** (validated server-side):

| Name | Type | Required | Default | Notes |
|---|---|---|---|---|
| `source_type` | enum | no | — | One of the 7 enum values |
| `state` | enum | no | `open` | Filter by state |
| `age_min_hours` | int | no | — | Only items older than this |
| `sort_by` | `weight` \| `age_seconds` | no | `weight` | |
| `sort_dir` | `asc` \| `desc` | no | `desc` | |
| `page` | int | no | 1 | min 1 |
| `limit` | int | no | 20 | min 1, max 50 |

**Response 200** (`OpsInboxListResponse`):

```json
{
  "ok": true,
  "data": {
    "items": [
      {
        "id": "payment_unknown:12345",
        "source_type": "payment_unknown",
        "source_key": "12345",
        "title": "订单 #12345 支付状态未知",
        "severity": "critical",
        "age_seconds": 7200,
        "age_label": "2 小时",
        "weight": 100,
        "impact": { "learners": 1, "orders_amount_cents": 9900 },
        "suggested_action": "核对 ZPay 回调并手动标记订单",
        "deep_link": { "name": "orders", "query": { "status": "unknown" } },
        "state": "open",
        "assignee_id": null,
        "snooze_until": null,
        "last_error_code": "ZPay_TIMEOUT",
        "retry_count": 0
      }
    ],
    "total": 27,
    "page": 1,
    "limit": 20,
    "counts_by_source": {
      "course_unpublished": 5,
      "map_anomaly": 3,
      "question_pending": 12,
      "feedback_pending": 2,
      "payment_unknown": 1,
      "queue_failed": 4,
      "long_pending": 0
    }
  },
  "request_id": "..."
}
```

**Behaviour notes**:
- `counts_by_source.<source>` is `null` (omitted) if the actor lacks that source's permission
- The `total` field counts items matching the filter (not the source-by-source breakdown)
- Items are sorted server-side per `(sort_by, sort_dir)` then `(weight DESC, age_seconds DESC)` as tiebreaker
- Each item's `state` is read from `ops_inbox_state` joined on `(actor_id, source_type, source_key)`; absent state row → `open`

**Errors**:

| Code | HTTP | When |
|---|---|---|
| `UNAUTHENTICATED` | 401 | No actor_id in request |
| `FORBIDDEN` | 403 | Actor lacks `ops_inbox.view` |
| `VALIDATION_FAILED` + `OPS_SOURCE_INVALID` | 422 | `source_type` not in enum |
| `VALIDATION_FAILED` | 422 | Pagination out of range |

### POST /api/admin/v1/ops-inbox/{id}/transition

Transition a single inbox row's state. Path `{id}` is the composite `${source_type}:${source_key}`.

**Path parameters**:

| Name | Type | Notes |
|---|---|---|
| `id` | string | Must match regex `^[a-z_]+:\d+$` |

**Body** (`OpsTransitionRequest`):

```json
{
  "to_state": "snoozed",
  "snooze_until": "2026-09-06T18:00:00+08:00"
}
```

Or for assignment:

```json
{
  "to_state": "assigned",
  "assignee_id": 42
}
```

**Response 200** (`OpsTransitionResponse`):

```json
{
  "ok": true,
  "data": {
    "ok": true,
    "state": "snoozed",
    "updated_at": "2026-09-05T16:00:00+08:00"
  },
  "request_id": "..."
}
```

**Errors**:

| Code | HTTP | When |
|---|---|---|
| `OPS_NOT_FOUND` | 404 | `{id}` doesn't resolve to an existing source row the actor can see |
| `OPS_FORBIDDEN` | 403 | Actor can't see this item by data scope |
| `OPS_TRANSITION_FORBIDDEN` | 422 | Transition not in `ALLOWED_TRANSITIONS` map |
| `OPS_SNOOZE_TOO_SHORT` | 422 | `snooze_until` ≤ now + 60s |
| `OPS_SNOOZE_TOO_LONG` | 422 | `snooze_until` > now + 30 days |
| `OPS_ASSIGNEE_INVALID` | 422 | `assignee_id` is not a valid admin account |
| `OPS_STATE_INVALID` | 422 | `to_state` not in enum |

All successful transitions write `audit_log` per [data-model.md](../data-model.md).

### POST /api/admin/v1/ops-inbox/queue-failed/{source_key}/retry

Manually trigger a retry of a queue-failed item. Only valid when `source_type = queue_failed`.

**Response 200** (`OpsRetryResponse`):

```json
{
  "ok": true,
  "data": {
    "ok": true,
    "state": "retrying",
    "retry_count": 1,
    "scheduled_at": "2026-09-05T16:01:00+08:00"
  },
  "request_id": "..."
}
```

The retry re-enqueues via the existing `NotificationDispatchService::retryFanOut()` (or analogous service for the failed consumer). After enqueue, the row's state is `retrying` until the consumer reports success (`retry_succeeded` audit) or exhaustion (`retry_exhausted` audit).

**Errors**:

| Code | HTTP | When |
|---|---|---|
| `OPS_RETRY_NOT_RETRYABLE` | 422 | Source row is not retryable (state ∈ {resolved} or vendor max-attempts reached) |
| `OPS_NOT_FOUND` | 404 | source_key not found |

### POST /api/admin/v1/ops-inbox/sweep (system, internal)

> Not exposed to the admin UI. Used by the cron sweep worker.

Sweeps snoozed items whose `snooze_until` has passed, returning them to `open`. Idempotent. Returns the count of items swept.

**Auth**: a system-internal token (separate from admin auth). Not in this spec's UI surface; implementation can defer until cron is wired.

## Wire-format summary

| Request | Response | Purpose |
|---|---|---|
| `GET /ops-inbox` | `OpsInboxListResponse` | List inbox rows |
| `POST /ops-inbox/{id}/transition` | `OpsTransitionResponse` | User-driven state change |
| `POST /ops-inbox/queue-failed/{key}/retry` | `OpsRetryResponse` | Manual retry trigger |
| `POST /ops-inbox/sweep` | `{count: number}` | System-only snooze expiry sweep |

## Boundary validation

- Server validates every query param and body field via PHP service private helpers (mirrors `OrderController::status()`, `OrderController::dateOrNull()`).
- Client validates every response via the Zod schemas in `contracts/ops-inbox-contract.ts` before assigning to refs.
- Strict TS — no `any`, no type assertion to bypass Zod.

## Idempotency

- `transition` is naturally idempotent on `(actor_id, source_type, source_key, to_state)` — repeated calls with same target state are no-ops.
- `retry` increments `retry_count`; calling on a `retrying` row is a no-op.
- `sweep` is idempotent (only flips rows whose `snooze_until <= now`).