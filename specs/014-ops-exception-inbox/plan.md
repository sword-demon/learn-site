# Implementation Plan: 运营异常收件箱

**Branch**: `014-ops-exception-inbox` | **Date**: 2026-09-05 | **Spec**: [spec.md](./spec.md)

**Input**: Feature specification from `/specs/014-ops-exception-inbox/spec.md`

## Summary

Aggregate 7 existing anomaly sources (unpublished courses, abnormal learning maps, pending questions, pending feedback, payment-unknown orders, failed queue dispatches, long-pending ops anomalies) into a single permission-scoped inbox in the admin SPA. Each row carries `积压年龄 / 影响范围 / 建议动作 / 跳转路径` and supports operator state transitions (snooze / assign / acknowledge). Recoverable queue failures auto-retry up to N times before reaching the inbox. Backend reuses existing `DashboardService::applyScope()` and `NotificationDispatchService::retryFanOut()` patterns; frontend mirrors `QuestionListView` + `DashboardView` tile deep-link. One new table (`ops_inbox_state`) for user-driven transitions only — inbox rows themselves are computed.

## Technical Context

**Language/Version**: PHP 8.x (Webman) backend; TypeScript 5.x (Vue 3) admin SPA
**Primary Dependencies**:
- Backend: `workerman/webman-framework ^2.2`, `webman/think-orm ^2.1`, `webman/redis-queue ~2.1`, `webman/redis ^2.1`
- Frontend: Vue 3, Element Plus, Pinia, Vue Router, TypeScript strict, Zod, Tailwind CSS, Axios
- No new dependencies; all reuse existing stack

**Storage**: MySQL 8 (existing); new table `ops_inbox_state` via Phinx migration
**Testing**: PHPUnit (`apps/api/tests/`), Vitest (`apps/admin/tests/`)
**Target Platform**: Docker Compose (OrbStack on macOS); existing service definitions
**Project Type**: Web application — backend + 2 SPAs (admin, web). Ops Inbox lives in admin only
**Performance Goals**: P95 list endpoint < 800ms; inbox first paint < 1.5s; 7 source queries can run sequentially (existing connection pool sized at 2-4)
**Constraints**: < 800ms P95 list query; per-source queries capped at `LIMIT 50` to bound worst-case; no new composer or npm deps
**Scale/Scope**: Single admin page; ~52 existing backend services grow by 2 (`OpsInboxService`, `OpsInboxController`); 1 new table; 1 new view file; contract updates in `packages/contracts/src/`

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

| Principle | Status | Notes |
|---|---|---|
| **I. 容器即运行契约** | PASS | No infra change; uses existing Compose services |
| **II. 稳定兼容且可复现** | PASS | No new deps; existing composer.lock / pnpm-lock.yaml unchanged |
| **III. 契约优先与端到端类型安全** | PASS | Zod schema in `packages/contracts/src/opsInbox.ts`; PHP service-layer validation; strict TS; no `any`; no assertion to bypass |
| **IV. 数据变更安全可追溯** | PASS | New `ops_inbox_state` table via Phinx migration with up/down; think-orm models follow existing convention; no parallel ORM |
| **V. 质量、安全与可运维性内建** | PASS | PHPUnit + Vitest for new code; Lint/Format/Type gates already in CI; structured log via existing `Logger`; error codes stable |
| **VI. 令牌鉴权、无感续期与可踢下线** | N/A | Ops Inbox is admin-only and inherits existing admin auth; no token model changes |
| **技术约束** | PASS | Webman + think-orm only; runtime MySQL via webman/think-orm (constitution §IV); no PDO/mysqli escape hatches; no parallel ORM |
| **开发流程 #1** | PASS | This plan documents scope, boundaries, acceptance criteria, API/data impact |
| **开发流程 #2** | PASS | Reuses existing service patterns; no speculative abstractions; Redis usage scoped to existing patterns |
| **开发流程 #3** | PASS | Backend gates: composer validate, php-cs-fixer, phpstan, phpunit, container startup; Frontend gates: prettier, eslint, tsc, vitest, production build |
| **开发流程 #4** | PASS | API contract change → updated Zod schemas in `packages/contracts`; migration updated; think-orm model added; PHPUnit + Vitest tests added |
| **开发流程 #5** | PASS | Docker validation: rebuild + compose up + curl smoke; ops inbox endpoints reachable; AdminAuth middleware enforced |
| **开发流程 #6** | PASS | PR review checks constitution + acceptance; complexity justification provided in `research.md` D1-D8 |
| **开发流程 #7** | PASS | Implementation will meet all "done" criteria: docs match, gates pass, no secrets leaked, OrbStack startup validated |

**Gates**: ALL PASS. No unjustified violations.

## Project Structure

### Documentation (this feature)

```text
specs/014-ops-exception-inbox/
├── spec.md              # Phase 0 output ($speckit-specify command)
├── plan.md              # This file ($speckit-plan command output)
├── research.md          # Phase 0 output ($speckit-plan command)
├── data-model.md              # Phase 1 output ($speckit-plan command)
├── quickstart.md             # Phase 1 output ($speckit-plan command)
├── contracts/
│   ├── ops-inbox-contract.ts   # Zod schemas + types
│   └── api-contract.md         # HTTP API surface
└── tasks.md             # Phase 2 output ($speckit-tasks command - NOT created by $speckit-plan)
```

### Source Code (repository root)

**Backend (new)**:
- `apps/api/app/service/OpsInboxService.php` — aggregation, scope filter, retry logic, state transitions, audit
- `apps/api/app/controller/admin/OpsInboxController.php` — `index`, `transition`, `retry`, `sweep`
- `apps/api/app/queue/redis/OpsInboxSweepConsumer.php` — snooze-expiry cron consumer
- `apps/api/database/migrations/20260905000001_create_ops_inbox_state.php` — Phinx migration
- `apps/api/tests/Service/OpsInboxServiceTest.php` — PHPUnit unit tests

**Backend (modified)**:
- `apps/api/app/route.php` — register `/ops-inbox` group under adminV1 middleware stack
- `apps/api/app/middleware/Authorize.php` — add path → `ops_inbox.view` map
- `apps/api/database/seeds/PermissionSeeder.php` — register `ops_inbox.view` permission code
- `apps/api/config/crontab.php` — schedule `ops-inbox-sweep` cron every minute

**Frontend (new)**:
- `apps/admin/src/views/ops-inbox/OpsInboxView.vue` — single page view (filter + table)
- `apps/admin/src/api/opsInbox.ts` — `fetchOpsInbox`, `transitionOpsInbox`, `retryOpsInbox`
- `apps/admin/tests/views/ops-inbox/OpsInboxView.spec.ts` — Vitest component test

**Frontend (modified)**:
- `apps/admin/src/router/index.ts` — add route with `permission: 'ops_inbox.view'`
- `apps/admin/src/layouts/AdminMenu.ts` — add menu entry `{ path: '/ops-inbox', label: '运营收件箱', permission: 'ops_inbox.view' }`
- `apps/admin/src/layouts/AdminLayout.vue` — add `menuIcons['/ops-inbox']` entry
- `apps/admin/src/main.ts` — register Vue 3 app (no change unless new dependency)

**Shared (new)**:
- `packages/contracts/src/opsInbox.ts` — Zod schemas + inferred types
- `packages/contracts/tests/opsInbox.contract.spec.ts` — Zod parse/reject unit tests

**Structure Decision**: Web application (Option 2 of template) — backend + frontend split. Ops Inbox is admin-only; no learner-side changes. Mirrors existing layout of features `011-catalog-data-mapper`, `012-zpay-payment-integration`, `013-learning-action-loop`.

## Complexity Tracking

> **Fill ONLY if Constitution Check has violations that must be justified**

No constitution violations. No complexity entries needed.

## Implementation Phases (high-level; detail in tasks.md)

1. **Phase A — Backend skeleton**: Migration + Service skeleton + Controller skeleton + tests skeleton. No behavior.
2. **Phase B — Single source integration**: Wire one source (e.g. `course_unpublished`) end-to-end. Validate scope + sort + render.
3. **Phase C — Remaining sources + sort**: Add the other 6 sources; tune `SOURCE_WEIGHTS`; verify sort order matches spec.
4. **Phase D — State transitions**: Implement `snooze / assign / acknowledge`; wire audit_log writes; write PHPUnit coverage.
5. **Phase E — Auto-retry**: Implement retry exhaustion logic for `queue_failed` source; wire to existing `NotificationDispatchService`.
6. **Phase F — Frontend view**: Build `OpsInboxView.vue` with filter + table + state-transition dialogs; deep-link integration.
7. **Phase G — Sweep cron + migration validation**: Add sweep consumer; run full Phinx up/down cycle in CI; verify rollback.

## Done When

- All phases A-G merged behind PRs
- PHPUnit + Vitest green; coverage ≥ 80% on new code
- `make up` from clean checkout reaches the new page
- Manual quickstart scenarios V1-V10 pass on a seeded dev env
- Constitution Check remains ALL PASS post-merge