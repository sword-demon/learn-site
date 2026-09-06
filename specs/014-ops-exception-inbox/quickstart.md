# Quickstart: 运营异常收件箱

**Feature**: [spec.md](./spec.md)
**Date**: 2026-09-05

This is a validation guide — it walks through the end-to-end flow once each piece is wired. Implementation details belong in `tasks.md` and the actual implementation phase.

## Prerequisites

- All apps start via the standard `make up` (or `docker compose up`) flow described in repo `README.md`
- Database migrations applied: `make migrate`
- At least one of each anomaly type seeded (see "Seed data" below)
- `apps/admin` dev server running; login as a regular staff account to verify data-scope filtering

## Seed data (development fixtures)

The fixtures below are what the manual validation steps assume. They are inserted via the existing admin UI, not via custom seeds:

| Source | How to seed |
|---|---|
| `course_unpublished` | Create a course, do not publish it. Wait 1 minute so `age_seconds > 60`. |
| `map_anomaly` | Create a published map whose stage contains a draft course. |
| `question_pending` | Have a learner post a question; do not answer. |
| `feedback_pending` | Have a learner post a feedback; do not reply for 24h (or backdate via SQL fixture). |
| `payment_unknown` | Trigger a ZPay "unknown" webhook (or backdate via SQL fixture). |
| `queue_failed` | Kill a redis-queue consumer mid-job, leaving `fan_out_status=failed`. |
| `long_pending` | The above with `created_at` backdated > 72h. |

## Validation scenarios

#### V1. Empty state

**Given**: A fresh staff account with no anomalies in scope.
**When**: Navigate to `http://admin.local/ops-inbox`.
**Then**:
- Page renders the empty state "暂无待处理事项" (per FR empty-state rule)
- No `listError` banner
- Polling interval is 30s (check `Network` tab)

#### V2. Single source visibility

**Given**: A staff account has access to `course.view` and `qa.view` but not `order.view`.
**And**: Seed data includes 1 unpublished course + 1 unanswered question + 1 payment-unknown order.
**When**: Load the inbox page.
**Then**:
- `course_unpublished` and `question_pending` items appear
- `payment_unknown` row is **omitted** from `counts_by_source` and the list
- `null` count sentinel in `counts_by_source` for `payment_unknown` (mirrors Dashboard tile rule)

#### V3. Sort order

**Given**: 1 payment-unknown (age 1h, weight 100) + 10 unpublished courses (age 30d, weight 40).
**When**: Load the inbox page with default sort.
**Then**:
- First row is the payment-unknown row, not the course with the longest age
- Tiebreaker: within `payment_unknown` (1 item) then within `course_unpublished` (10 items), sorted by age DESC

#### V4. Deep link

**Given**: An inbox list with at least one row.
**When**: Click the "跳转处理" button on a row.
**Then**:
- Router navigates to `deep_link.name` with `deep_link.query` as query string
- Target page renders without errors (e.g., for `payment_unknown` it goes to `/orders?status=unknown`)
- The target page's filter is pre-applied (e.g., status=unknown shown in dropdown)

#### V5. Snooze

**Given**: 1 open row.
**When**: Click "搁置到明天", confirm the dialog (ElMessageBox prompt with inputValidator).
**Then**:
- Row disappears from default view within 1 second (optimistic update)
- 30s later, polling fetches the row's new `state=snoozed` from server
- `audit_log` has a new row with `action='ops_inbox.snooze'`, `actor_id=<staff_id>`, `payload_json` contains the snooze_until timestamp
- After `snooze_until`, the row returns to `open` (verify by manual DB inspection or wait until cron sweep runs)

#### V6. Assign

**Given**: 1 open row, assignee_id = 42 (valid admin account).
**When**: Click "指派给某人", enter ID 42 in the prompt.
**Then**:
- Row disappears from my default view
- Logging in as account 42, the row appears in their default view with `state='assigned'`
- `audit_log` has `action='ops_inbox.assign'` with `payload_json.assignee_id=42`

#### V7. Auto-retry exhausts

**Given**: 1 queue-failed row at `retry_count=2` (max is 3).
**When**: Trigger an automatic retry via the consumer (or manually call the service in a test).
**Then**:
- After retry 3 fails, `audit_log` writes `ops_inbox.retry_exhausted` with `actor_id=0` (system)
- The row appears in default view as `state=open` with `retry_count=3`
- `suggested_action` is "手动重发或转人工" (not "等待自动重试")

#### V8. Permission denial

**Given**: A staff account whose data scope excludes a particular source row.
**When**: Manually craft a request to `POST /api/admin/v1/ops-inbox/{id}/transition` for that row.
**Then**:
- Server returns HTTP 403 with `code='FORBIDDEN'`
- No `audit_log` entry written
- The row's state is unchanged in `ops_inbox_state` (no row was even created)

#### V9. Polling freshness

**Given**: User has the inbox page open in tab A.
**When**: In tab B, an admin transitions one of their rows to `resolved`.
**Then**:
- Within 30s, tab A's row is removed from the list
- Network tab shows a `GET /ops-inbox?state=open` call every ~30s

#### V10. Optimistic update

**Given**: User has a row in `state=open`.
**When**: Click "标记为已处理".
**Then**:
- Row disappears from list **immediately** (< 100ms)
- Polling confirms the server-side state change
- On failure, row reappears with `listError` banner

## Quick smoke tests (CI / pre-merge)

The following unit/integration tests must pass before merging:

#### PHP (apps/api/tests/)

- `OpsInboxServiceTest::testScopeFilter_appliesDepartmentFilter`: staff with `SCOPE_DEPT` sees only own-dept anomalies
- `OpsInboxServiceTest::testSourcePermissions_excludesMissing`: missing `order.view` omits `payment_unknown` row
- `OpsInboxServiceTest::testSortByWeightAndAge`: weight=100 before weight=40; within tie, older first
- `OpsInboxServiceTest::testSnoozeValidation_rejectsTooShort`: snooze_until ≤ now+60s throws
- `OpsInboxServiceTest::testTransition_writesAudit`: snooze writes audit_log row
- `OpsInboxServiceTest::testAutoRetry_exhaustion`: 3 fails → state=open, audit_log action=retry_exhausted
- `OpsInboxStateMigrationTest::testUniqueConstraint`: duplicate (actor_id, source_type, source_key) fails

#### Vue (apps/admin/tests/)

- `OpsInboxView.spec.ts::testEmptyState_rendersEmpty`: when `items.length === 0`
- `OpsInboxView.spec.ts::testPolling_stopsOnUnmount`: clearInterval called on unmount
- `opsInbox.contract.spec.ts::testListResponse_parses`: valid response passes Zod
- `opsInbox.contract.spec.ts::testListResponse_rejectsBadSource`: unknown source_type throws

## Performance budget (manual check)

- Backend: a staff account with full scope should see first page in < 800ms P95 (timing via APM trace).
- Frontend: inbox page should be interactive in < 1.5s from navigation.
- 7 source queries run in parallel via separate DB connections (or sequentially if connection pool is small).

## Rollback

| Failure | Rollback |
|---|---|
| Inbox page crashes for any user | Revert menu entry in `AdminLayout.vue` / `AdminMenu.ts`; route still exists but inaccessible |
| Migration failure | Drop `ops_inbox_state` table; no source-table data is touched |
| Scope filter leaks data | The new service's `applyScope` reuses the existing `DashboardService::applyScope` shape; if a regression appears, the same fix applies to dashboard which has been audited |
| Audit log volume too high | Each transition writes one audit row — well within the existing audit budget |

## Not in this quickstart

- Auto-retry cron sweep worker for snooze expiry — implemented by `apps/api/app/queue/redis/OpsInboxSweepConsumer.php` (separate task)
- Migration file (`apps/api/database/migrations/20260905000001_create_ops_inbox_state.php`) — generated as part of implementation, not validation
- Real cron schedule for the sweep worker — added to existing `cron/` config; see existing scheduled-task patterns