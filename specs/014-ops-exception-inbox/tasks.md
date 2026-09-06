---
description: "Task list for Ops Inbox implementation"
---

# Tasks: 运营异常收件箱 (Ops Inbox)

**Input**: Design documents from `/specs/014-ops-exception-inbox/`
- [spec.md](./spec.md) — 5 user stories, P1 × 3, P2 × 2
- [plan.md](./plan.md) — backend (webman/think-orm) + admin SPA (Vue 3/Element Plus)
- [research.md](./research.md) — 8 design decisions D1-D8
- [data-model.md](./data-model.md) — OpsException (derived) + OpsInboxState (persisted)
- [contracts/](./contracts/) — Zod schema + HTTP API contract
- [quickstart.md](./quickstart.md) — 10 manual scenarios V1-V10

**Tests**: Project CLAUDE.md H4 mandates tests-same-commit. PHPUnit for backend, Vitest for frontend. Tests-first within each user story phase.

**Organization**: Tasks grouped by user story. Within each: tests → migration/model → service → endpoint → frontend → integration. Each story is independently testable.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: Can run in parallel (different files, no dependencies on incomplete tasks)
- **[Story]**: US1-US5 mapping to spec.md user stories
- Exact file paths included in every description

## Path Conventions

- Backend: `apps/api/app/{service,controller,model,middleware,queue}/...` and `apps/api/database/{migrations,seeds}/...`
- Frontend admin: `apps/admin/src/{views,api,components,router,layouts}/...`
- Shared contracts: `packages/contracts/src/...`
- Backend tests: `apps/api/tests/...`
- Frontend tests: `apps/admin/tests/...`

---

## Phase 1: Setup (Shared Infrastructure)

**Purpose**: Project-level scaffolding; permission code registration; menu + route placeholders.

- [X] T001 Register `ops_inbox.view` permission code in `apps/api/database/seeds/PermissionSeeder.php` (insert into `permissions` table; idempotent on re-run; assign to super-admin role seed)
- [X] T002 [P] Add PATH → permission map entry in `apps/api/app/middleware/Authorize.php` (`/api/admin/v1/ops-inbox` → `ops_inbox.view`)
- [X] T003 [P] Add top-level menu entry `{ path: '/ops-inbox', label: '运营收件箱', permission: 'ops_inbox.view' }` in `apps/admin/src/layouts/AdminMenu.ts`
- [X] T004 [P] Add `menuIcons['/ops-inbox']` icon mapping in `apps/admin/src/layouts/AdminLayout.vue` (pick unused icon, e.g. `Bell`)
- [X] T005 [P] Add empty route placeholder `{ path: '/ops-inbox', name: 'ops-inbox', component: () => import('@/views/ops-inbox/OpsInboxView.vue'), meta: { title: '运营收件箱', permission: 'ops_inbox.view' } }` in `apps/admin/src/router/index.ts`
- [X] T006 [P] Create empty `apps/admin/src/views/ops-inbox/OpsInboxView.vue` with `<el-empty description="运营收件箱占位" />` so the route resolves

**Checkpoint**: After Phase 1, navigating to `/ops-inbox` shows the placeholder. No backend code touched yet.

---

## Phase 2: Foundational (Blocking Prerequisites)

**Purpose**: Migration, contracts, service skeleton, controller skeleton, API client skeleton. All stories depend on this.

**⚠️ CRITICAL**: No user story work can begin until this phase is complete.

- [X] T007 Create Phinx migration `apps/api/database/migrations/20260905000001_create_ops_inbox_state.php` with `id / actor_id / source_type / source_key / status / assignee_id / snooze_until / last_actor_id / created_at / updated_at` plus `uk_actor_source` UNIQUE and `idx_status_snooze` + `idx_actor_status` indexes (down() drops)
- [X] T008 [P] Create Zod schemas in `packages/contracts/src/opsInbox.ts` with `OpsSourceTypeSchema`, `OpsStateSchema`, `OpsSeveritySchema`, `OpsImpactSchema`, `OpsExceptionSchema`, `OpsInboxListRequestSchema`, `OpsInboxListResponseSchema`, `OpsTransitionRequestSchema`, `OpsTransitionResponseSchema`, `OpsRetryResponseSchema`, `OPS_ERROR_CODES` const
- [X] T009 [P] Create Vitest contract tests in `packages/contracts/tests/opsInbox.contract.spec.ts` covering: valid list response parses; unknown source_type throws; transition body with `snooze_until` parses; invalid `to_state` throws
- [X] T010 [P] Add `OpsInboxState` think-orm model in `apps/api/app/model/OpsInboxState.php` (extends `support\think\Model`; `protected $name = 'ops_inbox_state'`; primary key `id`; types per data-model.md; no `$type` casts needed for INT/DATETIME)
- [X] T011 Create `apps/api/app/service/OpsInboxService.php` skeleton with: `final class`, namespace `App\service`; constructor injects `DataScopeService`, `NotificationDispatchService`, `PermissionService` (private readonly); private constants `TIMEZONE = 'Asia/Shanghai'`, `SOURCE_PERMISSIONS`, `SOURCE_WEIGHTS`, `RETRY_MAX_ATTEMPTS = 3`, `RETRY_INITIAL_BACKOFF_SECONDS = 60`, `RETRY_BACKOFF_MULTIPLIER = 2`, `RETRY_MAX_BACKOFF_SECONDS = 1800`, `LONG_PENDING_HOURS = 72`, `ALLOWED_TRANSITIONS`, `MAX_PAGE_LIMIT = 50`; private `writeAudit()` private `nowDatetime()` private `applyScope()` mirroring `DashboardService::applyScope()`; empty `list()` / `transitionState()` / `retry()` / `sweep()` method stubs that throw `BusinessException('NOT_IMPLEMENTED', 'OPS_NOT_FOUND')`
- [X] T012 Create `apps/api/app/controller/admin/OpsInboxController.php` skeleton with `final class`, namespace `App\controller\admin`; constructor injects `OpsInboxService`; private `wrap()` + `mapApiCode()` helpers mirroring `OrderController`; stub `index()`, `transition()`, `retry()`, `sweep()` actions
- [X] T013 Register `/api/admin/v1/ops-inbox` route group in `apps/api/app/route.php` with middleware `[AdminAuth, Authorize]`; sub-routes: `GET /` → `index`, `POST /{id}/transition` → `transition`, `POST /queue-failed/{source_key}/retry` → `retry`, `POST /sweep` → `sweep`
- [X] T014 [P] Create API client in `apps/admin/src/api/opsInbox.ts` with typed `fetchOpsInbox(params: OpsInboxListRequest): Promise<OpsInboxListResponse>`, `transitionOpsInbox(id: string, body: OpsTransitionRequest): Promise<OpsTransitionResponse>`, `retryOpsInbox(sourceKey: string): Promise<OpsRetryResponse>`; validate every response via Zod schemas
- [X] T015 Verify Phase 2 wiring: run `make up && make migrate`; curl `GET /api/admin/v1/ops-inbox` with admin auth header — expect empty list `{items:[], total:0, page:1, limit:20, counts_by_source:{}}`; navigation to `/ops-inbox` shows placeholder

**Checkpoint**: Foundation ready. User story work can begin.

---

## Phase 3: User Story 1 - 单点看到全部待处理异常 (Priority: P1) 🎯 MVP

**Goal**: Operator opens inbox and sees their scoped anomalies with 积压年龄 / 影响范围 / 建议动作 / 跳转路径; clicking deep-links to the source page.

**Independent Test**: Seed 1 unpublished course + 1 queue-failed dispatch for the actor's scope. Page shows 2 rows, both clickable deep-links to existing routes (`/courses` and `/notifications/{id}/retry`).

### Tests for User Story 1

> **NOTE: Write these FIRST, ensure they FAIL before implementation**

- [X] T016 [P] [US1] PHPUnit test in `apps/api/tests/Service/OpsInboxServiceTest.php::testList_returnsScopeFilteredItems` (asserts SCOPE_DEPT actor only sees own-dept rows)
- [X] T017 [P] [US1] PHPUnit test `OpsInboxServiceTest::testList_sortsByWeightDescThenAge` (weight=100 first; tie → age DESC)
- [X] T018 [P] [US1] PHPUnit test `OpsInboxServiceTest::testList_omitsMissingPermissionSources` (actor without `order.view` → `payment_unknown` absent)
- [X] T019 [P] [US1] PHPUnit test `OpsInboxServiceTest::testList_computesAgeLabelInAsiaShanghaiTimezone` (24h backdated row → `"1 天"`)
- [X] T020 [P] [US1] PHPUnit test `OpsInboxServiceTest::testList_computesImpactPerSource` (per-source impact shape)
- [X] T021 [P] [US1] PHPUnit test `OpsInboxServiceTest::testList_buildsDeepLinkPerSource` (deep_link.name + query match existing route names)
- [X] T022 [P] [US1] PHPUnit test `apps/api/tests/Controller/OpsInboxControllerTest.php::testIndex_returnsValidShape` (Zod-equivalent PHP validation: response matches `OpsInboxListResponseSchema` keys)

### Implementation for User Story 1

- [X] T023 [US1] Implement `OpsInboxService::list(int $staffAccountId, array $permissions, OpsInboxListRequest $params): array` in `apps/api/app/service/OpsInboxService.php` — runs 7 per-source SELECTs (one method each: `queryCourseUnpublished`, `queryMapAnomaly`, `queryQuestionPending`, `queryFeedbackPending`, `queryPaymentUnknown`, `queryQueueFailed`, `queryLongPending`) each respecting `applyScope` + permission filter; merges results, computes `age_seconds` + `age_label` + `weight` + `severity`; sorts by weight DESC then age_seconds DESC; paginates; joins `ops_inbox_state` for `state` field; returns `{items, total, page, limit, counts_by_source}`
- [X] T024 [US1] Implement `OpsInboxService::countsBySource(int $staffAccountId, array $permissions): array` (called by `list()` to fill `counts_by_source`); `null` (omitted) for sources actor lacks permission for; mirrors `DashboardService::SECTION_PERMISSIONS` shape
- [X] T025 [US1] Implement `OpsInboxService::shapeException(array $row, string $sourceType): array` private helper (normalizes per-source row into the `OpsException` shape)
- [X] T026 [US1] Implement `OpsInboxController::index(Request $request): Response` — reads `staff_id` + `permissions` from request; parses query params into `OpsInboxListRequest` via private validation helpers (`sourceType()`, `state()`, `ageMinHours()`, `sortBy()`, `sortDir()`, `page()`, `limit()`); throws `BusinessException(VALIDATION_FAILED, OPS_SOURCE_INVALID)` etc. on bad input; returns `$this->opsInbox->list(...)` wrapped in `ApiResponse::ok()`
- [X] T027 [US1] Implement `OpsInboxView.vue` table view in `apps/admin/src/views/ops-inbox/OpsInboxView.vue`: filter bar (source_type select, state select default `open`, age_min_hours input, sort_by/dir); `el-table` rows with title / source_type badge / severity badge / age_label / impact summary / suggested_action / state / action button "跳转处理"; pagination via `AdminListPager`; empty state `<el-empty description="暂无待处理事项" />`; error banner via `ElMessage` on failure; polling every 30s via `setInterval` (cleared in `onBeforeUnmount` per H6)
- [X] T028 [US1] Implement optimistic deep-link click in `OpsInboxView.vue`: action button calls `router.push({ name: row.deep_link.name, query: row.deep_link.query })`; on router error → `ElMessage.error('跳转失败')` and refresh list
- [X] T029 [US1] Create `apps/admin/src/composables/useOpsInboxPolling.ts` (composable per H5: `setInterval` set up in `onMounted`, cleared in `onBeforeUnmount`; signature `(request: () => OpsInboxListRequest) => { items, total, counts_by_source, loading, listError, reload }`)
- [X] T030 [US1] Vitest component test in `apps/admin/tests/views/ops-inbox/OpsInboxView.spec.ts::testRendersRowsAndDeepLink`: stub `fetchOpsInbox` returns 2 rows; assert rows render + click handler invoked with right deep_link
- [X] T031 [US1] Vitest composable test in `apps/admin/tests/composables/useOpsInboxPolling.spec.ts::testClearsIntervalOnUnmount`: mount + unmount, assert `clearInterval` called

**Checkpoint**: US1 fully functional. Manual quickstart V1, V2, V3, V4, V9 pass.

---

## Phase 4: User Story 2 - 统一排序规则抑制噪音 (Priority: P1)

**Goal**: List always sorted by `weight DESC, age_seconds DESC`. Default view never exceeds 50 items. `null` counts for missing permissions.

**Independent Test**: 1 payment-unknown (1h old) + 10 unpublished courses (30d old) → payment-unknown row first, regardless of insertion order.

### Tests for User Story 2

- [X] T032 [P] [US2] PHPUnit test `OpsInboxServiceTest::testSort_paymentUnknownBeatsOldCourse` (cross-source sort order)
- [X] T033 [P] [US2] PHPUnit test `OpsInboxServiceTest::testPagination_capsLimitAt50` (request limit=200 → truncated to 50)
- [X] T034 [P] [US2] PHPUnit test `OpsInboxServiceTest::testCounts_omitsMissingPermission` (counts_by_source omits key when permission missing)

### Implementation for User Story 2

- [X] T035 [US2] (Tune SOURCE_WEIGHTS in `OpsInboxService.php` based on test feedback from T032; verify existing implementation from T023 produces correct order) — if existing implementation already passes T032, mark task complete without code change
- [X] T036 [US2] Add `MAX_PAGE_LIMIT = 50` enforcement in `OpsInboxController::limit()` validator (`$raw > 50 → throw 'VALIDATION_FAILED' 'OPS_LIMIT_TOO_LARGE'`)
- [X] T037 [US2] Add "fold by source" UI affordance in `OpsInboxView.vue`: when `total > 50`, show top-level counts_by_source chips with click-to-filter (`router.replace({ query: { source_type: ... } })`); hide chip for sources with count=0

**Checkpoint**: US1 + US2 both functional. Manual quickstart V3 (sort), V5 (mixed noise) pass.

---

## Phase 5: User Story 4 - 数据范围过滤沿用现有权限模型 (Priority: P1)

**Goal**: Data scope filtering reuses `DataScopeService::allowedDepartmentIds()` + per-source permission codes. URL-tampering returns 403.

**Independent Test**: Staff A has 1 anomaly in scope; staff B has 1 anomaly in scope; cross-access to either's anomaly via `POST /transition` returns HTTP 403.

### Tests for User Story 4

- [X] T038 [P] [US4] PHPUnit test `OpsInboxServiceTest::testTransition_returnsForbiddenForOutOfScope`: actor without scope → throws `FORBIDDEN`
- [X] T039 [P] [US4] PHPUnit test `OpsInboxServiceTest::testTransition_returnsForbiddenForOutOfPermission`: actor with scope but no `order.view` accessing `payment_unknown` → throws `FORBIDDEN`
- [X] T040 [P] [US4] PHPUnit test `OpsInboxServiceTest::testTransition_returnsNotFoundForMissingId`: bogus id throws `OPS_NOT_FOUND`

### Implementation for User Story 4

- [X] T041 [US4] Implement `OpsInboxService::assertScopeAllowed(int $staffAccountId, array $permissions, string $sourceType, string $sourceKey): void` private helper — derives `departmentIds = DataScopeService::allowedDepartmentIds($staffAccountId, SOURCE_PERMISSIONS[$sourceType])`; checks `in_array(departmentIds === null || in_array($source['department_id'], $departmentIds))`; throws `BusinessException(FORBIDDEN, OPS_FORBIDDEN)` otherwise
- [X] T042 [US4] Wire `assertScopeAllowed` into `OpsInboxService::transitionState()` first line and into each per-source `query*` method (mirrors how `DashboardService::applyScope()` is used)

**Checkpoint**: US1 + US2 + US4 functional. Manual quickstart V8 (permission denial) passes.

---

## Phase 6: User Story 3 - 可恢复通知任务自动有限重试 (Priority: P2)

**Goal**: Recoverable notification fan-out failures auto-retry up to 3 times with exponential backoff before reaching inbox; unrecoverable failures bypass retry and enter inbox immediately.

**Independent Test**: Inject 1 notification fan-out failure → 3 retries → success → never appears in inbox. Inject 1 with `last_error_code=ZPay_TIMEOUT_INVALID_ACCOUNT` → straight to inbox with state `open`.

### Tests for User Story 3

- [X] T043 [P] [US3] PHPUnit test `OpsInboxServiceTest::testQueueFailed_retrySucceedsBeforeInbox`: 3rd retry succeeds → inbox never surfaces it (mock NotificationDispatchService retryFanOut)
- [X] T044 [P] [US3] PHPUnit test `OpsInboxServiceTest::testQueueFailed_retryExhaustionSurfacesInbox`: 3rd retry fails → `state=open`, audit_log action `ops_inbox.retry_exhausted` with actor_id=0
- [X] T045 [P] [US3] PHPUnit test `OpsInboxServiceTest::testQueueFailed_unrecoverableSkipsRetry`: error code in `UNRECOVERABLE_CODES` → straight to inbox
- [X] T046 [P] [US3] PHPUnit test `OpsInboxServiceTest::testRetry_backoffIsExponential`: schedule times match `initial * multiplier^(n-1)` capped at max

### Implementation for User Story 3

- [X] T047 [US3] Implement `OpsInboxService::maybeAutoRetry(int $dispatchId): string` — checks if `fan_out_error` is in `UNRECOVERABLE_CODES` const → return `unrecoverable`; else if `retry_count >= RETRY_MAX_ATTEMPTS` → write audit `retry_exhausted` + return `exhausted`; else call `notificationDispatchService->retryFanOut($dispatchId)`, compute next backoff via private `nextBackoff(int $attempt): int` (exponential capped), persist next-attempt timestamp on dispatch row, return `scheduled`
- [X] T048 [US3] Wire `maybeAutoRetry` into the existing `NotificationFanOutExecutor` failure branch (`apps/api/app/queue/redis/NotificationFanOutConsumer.php`) — when `fan_out_status` flips to `failed`, call `OpsInboxService::maybeAutoRetry`; if it returns `exhausted` or `unrecoverable`, the row surfaces in inbox naturally on next `list()` call (no further action)
- [X] T049 [US3] Add private constant `UNRECOVERABLE_CODES = ['ZPAY_INVALID_ACCOUNT', 'ZPAY_ACCOUNT_DISABLED', 'PARAM_INVALID', 'TEMPLATE_NOT_FOUND', 'CONTENT_REJECTED']` to `OpsInboxService`; document in code comment that this list is intentionally conservative

**Checkpoint**: US1 + US2 + US4 + US3 functional. Manual quickstart V7 (auto-retry exhaustion) passes.

---

## Phase 7: User Story 5 - 待办状态可推进 (Priority: P2)

**Goal**: Operator can snooze / assign / acknowledge rows. State transitions write audit_log.

**Independent Test**: Acknowledge 1 row → disappears from list + audit_log entry; snooze 1 row → hidden until `snooze_until`; assign 1 row to other staff → row appears in their inbox.

### Tests for User Story 5

- [X] T050 [P] [US5] PHPUnit test `OpsInboxServiceTest::testTransition_acknowledgeWritesAudit`: state open→resolved writes `ops_inbox.acknowledge` audit
- [X] T051 [P] [US5] PHPUnit test `OpsInboxServiceTest::testSnooze_rejectsTooShort`: snooze_until ≤ now+60s throws `OPS_SNOOZE_TOO_SHORT`
- [X] T052 [P] [US5] PHPUnit test `OpsInboxServiceTest::testSnooze_rejectsTooLong`: snooze_until > now+30d throws `OPS_SNOOZE_TOO_LONG`
- [X] T053 [P] [US5] PHPUnit test `OpsInboxServiceTest::testAssign_validatesAssigneeExists`: invalid assignee_id throws `OPS_ASSIGNEE_INVALID`
- [X] T054 [P] [US5] PHPUnit test `OpsInboxServiceTest::testAssign_writesAudit`: open→assigned writes `ops_inbox.assign` audit with payload.assignee_id
- [X] T055 [P] [US5] PHPUnit test `OpsInboxServiceTest::testSweep_flipsExpiredSnoozeToOpen`: state=snoozed, snooze_until ≤ now → flip to open, audit actor_id=0

### Implementation for User Story 5

- [X] T056 [US5] Implement `OpsInboxService::transitionState(int $staffAccountId, string $id, OpsTransitionRequest $body): array` — parses composite id `{source_type}:{source_key}`; calls `assertScopeAllowed`; loads or creates `ops_inbox_state` row; validates target state against `ALLOWED_TRANSITIONS`; validates `snooze_until` bounds; validates `assignee_id` via `assertValidAssignee()` private helper (throws `OPS_ASSIGNEE_INVALID` if missing); updates state row; writes audit_log via `writeAudit()`; returns updated shape
- [X] T057 [US5] Implement `OpsInboxService::sweep(): int` — single UPDATE on `ops_inbox_state` where status=snoozed AND snooze_until ≤ now → set status=open, last_actor_id=0; returns affected row count (audit per row optional; cheaper to log a single `ops_inbox.sweep` action via the controller)
- [X] T058 [US5] Implement `OpsInboxController::transition(Request $request, string $id): Response` — wraps `OpsInboxService::transitionState()`; `OpsInboxController::sweep(Request $request): Response` — wraps `OpsInboxService::sweep()`
- [X] T059 [US5] Add state-transition UI in `OpsInboxView.vue`: per-row "操作" el-dropdown with "标记为已处理" / "搁置到..." / "指派给..." menu items; uses `ElMessageBox.prompt` for snooze (date input + `inputValidator`) and assign (user picker); H2 rule — try/catch cancel, type: 'warning' on destructive
- [X] T060 [US5] Create `apps/api/app/queue/redis/OpsInboxSweepConsumer.php` implementing `Webman\RedisQueue\Consumer` with `$queue = 'ops-inbox-sweep'`, `$connection = 'default'`; `consume()` calls `OpsInboxService::sweep()` (resolve via DI container or new instance)
- [X] T061 [US5] Register `ops-inbox-sweep` cron in `apps/api/config/crontab.php` (every minute; mirrors existing scheduled-task cron shape)
- [X] T062 [US5] Vitest component test `apps/admin/tests/views/ops-inbox/OpsInboxView.spec.ts::testAcknowledgeRemovesRow`: stub `transitionOpsInbox`; click menu → assert row removed from list + success toast
- [X] T063 [US5] Vitest component test `OpsInboxView.spec.ts::testSnoozeValidatesDate`: pass invalid date to ElMessageBox → error shown, no API call

**Checkpoint**: US1-US5 all functional. Manual quickstart V5 (snooze), V6 (assign), V10 (optimistic update) pass.

---

## Phase 8: Polish & Cross-Cutting Concerns

**Purpose**: Documentation, code cleanup, performance, security hardening, full quickstart run.

- [X] T064 [P] Run PHPUnit suite: `cd apps/api && vendor/bin/phpunit` — confirm green + coverage ≥ 80% on `OpsInboxService.php`, `OpsInboxController.php`, `OpsInboxState.php`
- [X] T065 [P] Run Vitest suite: `cd apps/admin && pnpm vitest run` — confirm green + coverage ≥ 80% on new files
- [X] T066 [P] Run phpstan on new files: `cd apps/api && vendor/bin/phpstan analyse app/service/OpsInboxService.php app/controller/admin/OpsInboxController.php` — confirm zero errors
- [X] T067 [P] Run ESLint + tsc on admin: `cd apps/admin && pnpm lint && pnpm typecheck` — confirm zero errors
- [X] T068 Run `cd apps/api && vendor/bin/php-cs-fixer fix --dry-run` on new files — confirm no formatting issues
- [X] T069 Run `pnpm prettier --check apps/admin/src/views/ops-inbox apps/admin/src/api/opsInbox.ts packages/contracts/src/opsInbox.ts` — confirm no formatting issues
- [X] T070 [P] Security review per constitution §V: verify no hardcoded secrets; `OPS_FORBIDDEN` returned on cross-scope; `audit_log` written on every state change; structured logging via existing `Logger` for all failed transitions
- [X] T071 [P] Run quickstart scenarios V1-V10 manually against seeded dev env (`make up && make migrate && make seed-dev` if available, else manual SQL fixtures per [quickstart.md](./quickstart.md))
- [X] T072 [P] Performance check: time `GET /api/admin/v1/ops-inbox` with full scope + 7 sources populated; assert P95 < 800ms; if exceeded, consider per-source query parallelisation via separate DB connections
- [X] T073 [P] Run migration up/down cycle: `cd apps/api && vendor/bin/phinx migrate -t 0 && vendor/bin/phinx migrate` — confirm reversible
- [X] T074 [P] Add inline code comments on `OpsInboxService` private constants explaining WHY each weight was chosen (no narration of what the line does)
- [X] T075 Update `apps/admin/src/router/access.ts` if any new permission code semantics needed (e.g. partial source visibility); skip if no change
- [X] T076 Update `CONTEXT.md` if new domain vocabulary introduced (no new vocabulary in this feature — confirm and skip if so)
- [X] T077 [P] Final constitution check: walk through each principle I-VII + dev flow #1-7; confirm ALL PASS post-merge

---

## Dependencies & Execution Order

### Phase Dependencies

- **Phase 1 (Setup)**: No dependencies — start immediately
- **Phase 2 (Foundational)**: Depends on Phase 1 — BLOCKS all user stories
- **Phase 3 (US1)**: Depends on Phase 2
- **Phase 4 (US2)**: Depends on Phase 3 (US1 sorts work first; US2 tunes)
- **Phase 5 (US4)**: Depends on Phase 3 (US1 scope-aware queries; US4 adds the FORBIDDEN gate)
- **Phase 6 (US3)**: Depends on Phase 5 (US4 establishes the scope/sort pipeline; US3 plugs into the queue failure source)
- **Phase 7 (US5)**: Depends on Phase 5 (US4 establishes scope; US5 adds state mutations on top)
- **Phase 8 (Polish)**: Depends on all user stories

### User Story Dependencies

- **US1 (P1)**: Independent — first MVP slice
- **US2 (P1)**: Integrates with US1 (sorts its output); cannot run before US1
- **US4 (P1)**: Integrates with US1 (adds gates to its queries); cannot run before US1
- **US3 (P2)**: Plugs into US1's `queue_failed` source; cannot run before US1
- **US5 (P2)**: State mutations on US1's rows; cannot run before US1 + US4

The 4 P1/P2 stories can ship as one MVP if US1 + US2 + US4 + US5 are merged in one PR; US3 can ship as a follow-up since queue-failed rows are still surfaced (just without auto-retry).

### Within Each User Story

- Tests MUST be written first and FAIL before implementation (H4 + project TDD rule)
- Models before services
- Services before endpoints
- Endpoints before frontend consumption
- Story complete before moving to next priority

### Parallel Opportunities

- T001-T006 (Phase 1): all [P] except T001 (touching same file family but no inter-deps)
- T008-T009 (Phase 2): [P] — contracts + tests in parallel with model
- T010-T012 (Phase 2): [P] — different files
- T016-T022 (Phase 3 tests): all [P] — different test methods, same file
- T023-T026 (Phase 3 impl): sequential — depends on shared shape definitions
- T032-T034, T038-T040, T043-T046, T050-T055 (story tests): all [P] within each story
- T064-T069 (Phase 8): all [P] — different tools

---

## Parallel Example: User Story 1

```bash
# Launch all tests for US1 together (must FAIL before impl):
php artisan test --filter=OpsInboxServiceTest

# Then in parallel:
Task: "Implement OpsInboxService::list in apps/api/app/service/OpsInboxService.php"
Task: "Implement OpsInboxController::index in apps/api/app/controller/admin/OpsInboxController.php"
Task: "Implement OpsInboxView.vue in apps/admin/src/views/ops-inbox/"
Task: "Create useOpsInboxPolling composable in apps/admin/src/composables/"
```

---

## Implementation Strategy

### MVP First (User Stories 1, 2, 4)

1. Complete Phase 1: Setup
2. Complete Phase 2: Foundational
3. Complete Phase 3: US1 (single page, one source integrated end-to-end)
4. Complete Phase 4: US2 (tune sort)
5. Complete Phase 5: US4 (scope gate)
6. **STOP and VALIDATE**: Manual quickstart V1, V2, V3, V4, V8, V9
7. Deploy/demo if ready — operators have a working inbox with all 7 sources

### Incremental Delivery

1. Setup + Foundational → Foundation ready
2. US1 → Test → Demo (operators see their anomalies in one place)
3. US2 → Test → Demo (sort + noise control)
4. US4 → Test → Demo (scope gates verified)
5. US3 → Test → Demo (auto-retry)
6. US5 → Test → Demo (state transitions + audit)
7. Each story adds value without breaking previous stories

### Parallel Team Strategy

With multiple developers:

1. Team completes Setup + Foundational together (1 day)
2. Once Phase 2 done:
   - Developer A: US1 (3-5 days)
   - Developer B: US4 (1-2 days, after US1 query layer exists)
3. US2, US3, US5 can proceed in any order after their dependencies
4. Each story completes and integrates independently

---

## Notes

- [P] tasks = different files, no dependencies on incomplete tasks
- [Story] label maps task to specific user story for traceability
- Tests are MANDATORY per project CLAUDE.md H4 — not optional
- Each user story should be independently completable and testable
- Verify tests fail before implementing
- Commit after each task or logical group (H4 — same-commit rule)
- Stop at any checkpoint to validate story independently
- Avoid: vague tasks, same-file conflicts, cross-story dependencies that break independence
- Private constants live on `OpsInboxService` per H3 — no scattered config
- All admin SPA dialogs use `ElMessageBox` per H2 — no native `confirm/prompt/alert`
- `applyScope` reuses `DashboardService` shape; no parallel scope implementations per H1
