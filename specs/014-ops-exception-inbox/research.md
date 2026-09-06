# Research: 运营异常收件箱 (Ops Inbox)

**Feature**: [spec.md](./spec.md)
**Branch**: `014-ops-exception-inbox`
**Date**: 2026-09-05

## Codebase Context (existing patterns to reuse)

| Layer | Canonical pattern | Reference |
|---|---|---|
| Backend service | `final class`, namespace `App\service`, one per domain | `app/service/DashboardService.php` |
| Backend controller | `final class` with DI, `wrap()` + `mapApiCode()` helpers | `app/controller/admin/OrderController.php` |
| Data scope | `DataScopeService::allowedDepartmentIds($staffId, $code)` | `app/service/DataScopeService.php:53` |
| Audit | Service-private `writeAudit()` method, `actor_id` + `action` + `payload_json` | `app/service/CourseFeedbackService.php:281` |
| Query | `support\think\Db` chain, `applyScope()` for scope filter | `app/service/DashboardService.php:117` |
| Queue retry | DB state enum (`pending/running/completed/failed`), service.reset + re-dispatch | `app/service/NotificationDispatchService.php:159` |
| Frontend list view | Filter state object, `reload()` rebuild params, `loadInbox()` mirror | `views/qa/QuestionListView.vue` |
| Frontend aggregate view | Tile pattern with `null` sentinel for missing permission | `views/dashboard/DashboardView.vue:111` |
| Routing guard | Pure `resolveAdminNavigation()` reading `meta.permission` | `router/access.ts:26` |
| Permission code | `<resource>.<verb>` dotted convention | `apps/api/database/seeds/PermissionSeeder.php` |

## Decisions

### D1. Service + Controller mirror DashboardService / DashboardController

**Decision**: New `OpsInboxService` + `OpsInboxController` reusing `DashboardService::applyScope()` shape and `OrderController::wrap()` + `mapApiCode()` shape.

**Rationale**: DashboardService is the only existing aggregate-style service and already covers 4 of the 7 inbox sources (unanswered_questions, pending_reviews, abnormal_learning_maps, unpublished_courses). The OpsInbox layer extends this aggregation with derived fields (积压年龄, 影响范围, 建议动作, 跳转路径).

**Alternatives considered**:
- Direct SQL UNION across 7 tables — rejected: loses per-source scope filtering, hard to surface heterogeneous shapes, can't reuse existing indexers
- Cron-driven materialised table refreshed every N seconds — rejected: scope filter becomes stale, adds a new sync seam, more failure modes

### D2. New `ops_inbox_state` table tracks user-driven transitions; inbox items are computed, not stored

**Decision**: Source items are derived live from existing tables; only user-state (acknowledge / snooze / assignee) is persisted.

**Rationale**: Storing 7 different item types would require either (a) duplicate writes on every source mutation (high regression risk) or (b) a sync cron (new failure mode). Per-source SELECTs with scope filtering already exist in DashboardService; the OpsInbox layer just calls them.

**Schema** (`ops_inbox_state`):
```
id, actor_id, source_type (enum: course_unpublished | map_anomaly | question_pending | feedback_pending | payment_unknown | queue_failed | long_pending), source_key (varchar(64)), status (enum: open | snoozed | resolved | assigned), snooze_until (datetime, nullable), assignee_id (bigint, nullable), created_at, updated_at
UNIQUE (actor_id, source_type, source_key)  -- one row per user per source item
INDEX (status, snooze_until)                -- sweep job for snooze expiry
INDEX (actor_id, status)                    -- per-user inbox fetch
```

**Alternative**: separate `ops_inbox_snooze` table — rejected: 3NF nit, no domain value, the state table already encodes snooze_until.

### D3. Auto-retry extends NotificationDispatchService; no new queue framework

**Decision**: New retry layer for notification fan-out reuses `NotificationDispatchService::retryFanOut()`. For non-notification queue failures (e.g. payment consumer crash), wrap the existing consumer in a per-source retry policy stored in `ops_inbox_state`.

**Rationale**: H1 rule — don't add a new framework when existing infrastructure covers it. webman/redis-queue already retries 5× with 5s backoff at the vendor level; OpsInbox adds the "post-vendor-retry → human" seam, not a parallel retry mechanism.

**Auto-retry policy** (private const on `OpsInboxService`):
- `RETRY_MAX_ATTEMPTS = 3` (env-overridable)
- `RETRY_INITIAL_BACKOFF_SECONDS = 60`
- `RETRY_BACKOFF_MULTIPLIER = 2`
- `RETRY_MAX_BACKOFF_SECONDS = 1800`
- `LONG_PENDING_HOURS = 72` (env-overridable)

**Alternative**: per-queue custom retry — rejected: vendor defaults already work; OpsInbox doesn't need to reimplement the loop.

### D4. Permission code `ops_inbox.view`; data-scope via DataScopeService

**Decision**: Register `ops_inbox.view` in `PermissionSeeder.php` and `Authorize.php` PATH map. Each OpsInbox source delegates to its existing scope helper (e.g. question scope → existing `QuestionService::scope*`).

**Rationale**: H7 + H1 — trust-boundary gating must use existing scope model, not invent a new one. The 7 source services already enforce scope in their public methods; OpsInbox calls those public methods rather than re-running scope.

**Per-source permission map** (`OpsInboxService::SOURCE_PERMISSIONS` private const):
```php
[
    'course_unpublished' => 'course.view',
    'map_anomaly'        => 'map.view',
    'question_pending'   => 'qa.view',
    'feedback_pending'   => 'review.view',
    'payment_unknown'    => 'order.view',
    'queue_failed'       => 'notification.view',
    'long_pending'       => '*', // derived from any of the above
]
```

A source is excluded from the actor's inbox if `!hasPermission($sourcePermission)`. The list returned uses `null` sentinel in `counts.<source>` (mirrors DashboardView's tile hide rule).

### D5. Sort weights as a private const map; not configurable from UI

**Decision**: `OpsInboxService::SOURCE_WEIGHTS` (private const, env-overridable for ops tuning only):
```
payment_unknown    = 100
queue_failed       =  80
question_pending   =  60
course_unpublished =  40
map_anomaly        =  30
feedback_pending   =  20
long_pending       =  10  // lowest — it's a "stuck everywhere" backstop
```

Within a source, sort by `(now - source_created_at)` desc — i.e. 积压年龄 multiplies weight.

**Rationale**: FR-002 requires unified sort; weights must be centralised so any future source can plug in without re-engineering the page.

**Alternative**: per-source UI sort — rejected: degrades into noise list (user's stated failure mode).

### D6. Frontend page mirrors QuestionListView filter + Dashboard tile deep-link

**Decision**: `OpsInboxView.vue` follows `QuestionListView.vue` for filter state + `AdminListPager`, and `DashboardView.vue` for tile click → `router.push(name, query)` deep-link. No new component library.

**Rationale**: H6 — reuse existing pattern. AdminListPager already supports the page/limit shape we need.

**New components needed**: zero. The OpsInbox page renders `el-table` rows inline (matches the "6 inline sections > abstracted component" rule when only one consumer).

### D7. Polling, not WebSocket

**Decision**: Frontend polls `/api/admin/v1/ops-inbox` every 30s; manual state transitions trigger immediate optimistic update.

**Rationale**: H7 (lazy) — WebSocket push needs new gateway / pubsub config; existing webman/push server is for learner chat, not for admin notifications. 30s polling is acceptable for human-driven inbox; sub-30s updates are user-initiated.

**Alternative**: SSE — rejected: requires composer change, push server is for the other direction.

### D8. No new dependencies; reuse existing stack

**Decision**: PHP backend uses existing webman + think-orm + redis-queue. Vue frontend uses existing Element Plus + Pinia + Tailwind. Contracts in existing `packages/contracts/src/`.

**Rationale**: Constitution II — stable + reproducible. Constitution V — security/observability already in place. Adding deps for an aggregation page is unnecessary.

## NEEDS CLARIFICATION (all resolved by existing patterns)

| Originally unclear | Resolution |
|---|---|
| How to aggregate 7 sources with one scope filter | Delegate to each source's existing scoped query (per D4) |
| Where to store acknowledge/snooze/assignee | New `ops_inbox_state` table (per D2) |
| Retry policy lives where | Extend `NotificationDispatchService`, env-overridable constants (per D3) |
| Realtime vs polling | Polling 30s + optimistic update (per D7) |
| How to gate a source the actor can't see | `SOURCE_PERMISSIONS` map + `null` counts sentinel (per D4) |

## Risks

| Risk | Mitigation |
|---|---|
| 7 SELECT queries per inbox load exceed P95 800ms | Each source query is `LIMIT 50`; load in parallel via separate controller methods if needed; cache "no change" result for 15s |
| `ops_inbox_state` table grows unbounded | UNIQUE (actor_id, source_type, source_key) — one row per user per item; deleted `resolved` rows older than 90 days (cron) |
| New menu entry in AdminMenu.ts breaks layout | Reuse existing `Monitor`-class icon; keep label short ("运营收件箱") |
| Permission map misses a source | Source list is exhaustive (all 7 in spec); if any source is added later, both SOURCE_PERMISSIONS and SOURCE_WEIGHTS must be extended |
| Long_pending recursion (long_pending items include themselves?) | LONG_PENDING_HOURS is global threshold; a long_pending item is "any source item that's been open > 72h"; not recursive |