---
description: "Task list for 课程发布核验清单"
---

# Tasks: 课程发布核验清单

**Input**: Design documents from `/specs/015-course-publish-checklist/`
- [spec.md](./spec.md) — 5 user stories, P1 × 3, P2 × 2
- [plan.md](./plan.md) — `CoursePublishChecklistService` + GET checklist + POST publish gate + admin dialog
- [research.md](./research.md) — D1–D12
- [data-model.md](./data-model.md) — computed PublishChecklist / Finding / ImpactSummary
- [contracts/](./contracts/) — HTTP + Zod draft
- [quickstart.md](./quickstart.md) — V1–V11

**Tests**: Project CLAUDE.md H4 mandates tests-same-commit. PHPUnit for backend, Vitest for contracts/admin. Tests-first within each user story phase.

**Organization**: Tasks grouped by user story. Within each: tests → service → endpoint → UI → integration. Each story is independently testable.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: Can run in parallel (different files, no dependencies on incomplete tasks)
- **[Story]**: US1–US5 mapping to spec.md user stories
- Exact file paths included in every description

## Path Conventions

- Backend: `apps/api/app/{service,controller,middleware}/...`
- Frontend admin: `apps/admin/src/{views,api}/...`
- Learner: `apps/web/src/views/me/...`
- Shared contracts: `packages/contracts/src/...`
- Backend tests: `apps/api/tests/...`
- Frontend tests: `apps/admin/tests/...`

---

## Phase 1: Setup (Shared Infrastructure)

**Purpose**: Shared contracts and domain vocabulary. No new permission, menu, or table.

- [X] T001 Copy checklist Zod module into `packages/contracts/src/coursePublishChecklist.ts` from `specs/015-course-publish-checklist/contracts/course-publish-checklist.ts` (finding codes, catalog snapshot, impact, `PublishChecklistDTO`, `PublishCourseInput`)
- [X] T002 [P] Export `coursePublishChecklist` symbols from `packages/contracts/src/index.ts`
- [X] T003 [P] Add domain terms 发布核验清单 / 核验发现 / 硬错误 / 警告 / 影响栏 to `CONTEXT.md` (keep 有效课节 / 试看课节 / 课程发布消息; field name stays `is_preview`)

**Checkpoint**: Contracts compile (`pnpm --filter @learn-site/contracts` typecheck). No runtime behavior change.

---

## Phase 2: Foundational (Blocking Prerequisites)

**Purpose**: Service skeleton, GET route, Authorize map, exception details forwarding, fake probe. Blocks every user story.

**⚠️ CRITICAL**: No user story work can begin until this phase is complete.

- [X] T004 Create Vitest contract tests in `packages/contracts/src/__tests__/coursePublishChecklist.test.ts`: valid checklist parses; unknown finding code fails; `acknowledge_warnings` optional; `completed_preserved` must be `true`; `recipient_count` nullable
- [X] T005 [P] Create `apps/api/app/service/AssetReachabilityProbe.php` with interface `AssetReachabilityProbe` (`probe(string $storagePath): array{reachable:bool,skipped:bool}`) plus `FakeAssetReachabilityProbe` (always reachable, no I/O) for tests
- [X] T006 Create `apps/api/app/service/CoursePublishChecklistService.php` skeleton: `final class`, namespace `App\service`, constructor injects `AssetReachabilityProbe` (default fake/null), private `TIMEZONE = 'Asia/Shanghai'`, private finding-code constants matching the Zod enum, public `build(int $courseId, int $actorStaffAccountId): array` stub throwing `BusinessException('NOT_FOUND', 'COURSE_NOT_FOUND')` until US1, private `nowIso8601()` / `write` helpers unused yet
- [X] T007 Add constructor injection on `apps/api/app/service/CourseService.php` for `CoursePublishChecklistService` with default `new CoursePublishChecklistService()` so existing `new CourseService()` in `apps/api/tests/CoursePublishNotifyTest.php` keeps compiling
- [X] T008 Forward `BusinessException::$details` in `apps/api/app/controller/admin/CourseController.php` `wrap()` into `ApiResponse::fail(..., $e->details)` so `error.checklist` can ride 422
- [X] T009 Add `publishChecklist` action stub on `apps/api/app/controller/admin/CourseController.php` calling `$this->service` or the checklist service with `(int) $request->account_id`
- [X] T010 Register `GET /courses/{id}/publish-checklist` in `apps/api/app/route.php` on the existing admin v1 course group
- [X] T011 Map `GET /api/admin/v1/courses/{id}/publish-checklist` → `course.view` in `apps/api/app/middleware/Authorize.php` **before** the generic `#^/api/admin/v1/courses(?:/\d+)?$#` rule (unmapped path would skip Authorize)
- [X] T012 [P] Add `fetchPublishChecklist(id: number)` in `apps/admin/src/api/catalog.ts` parsing `ApiOk(PublishChecklistDTO)`
- [X] T013 [P] Extend `apps/api/tests/AuthorizeLeakTest.php` so GET `/api/admin/v1/courses/42/publish-checklist` requires `course.view` and does not leak `course.publish`

**Checkpoint**: Authenticated GET returns 404 for unknown id or 501/422 from stub; AuthorizeLeakTest covers the new path. User story work can start.

---

## Phase 3: User Story 1 - 发布前先看到确定性课程清单 (Priority: P1) 🎯 MVP

**Goal**: Admin can generate a deterministic catalog checklist (category, intro, every chapter/lesson, resources, trial) without changing status or sending 课程发布消息. Hard vs warning vs info are distinct. Incomplete extra lessons are warnings when at least one 有效课节 exists.

**Independent Test**: Ready draft / empty-intro draft / processing-asset extra lesson — GET checklist three times; fingerprint + hard-error set stable; status stays draft; zero `course_published` dispatches. Notification `recipient_count` is a live active-learner COUNT (not a hardcoded 0). Map/entitlement/progress bars may be zeros until US4.

### Tests for User Story 1

> **NOTE: Write these FIRST, ensure they FAIL before implementation**

- [X] T014 [US1] PHPUnit `apps/api/tests/CoursePublishChecklistServiceTest.php::testBuild_doesNotChangeStatusOrDispatch` (GET/build leaves `courses.status` and `notification_dispatches` unchanged)
- [X] T015 [US1] PHPUnit `apps/api/tests/CoursePublishChecklistServiceTest.php::testBuild_fingerprintAndHardErrorsStable` (two builds, same catalog → same `content_fingerprint` and hard-error code set)
- [X] T016 [US1] PHPUnit `apps/api/tests/CoursePublishChecklistServiceTest.php` hard-error locations: disabled category + empty intro + zero 有效课节 (`CATEGORY_DISABLED` / `INTRO_REQUIRED` / `NO_PUBLISHABLE_LESSON`); also `testBuild_saleWindowExpiredIsHard` and `testBuild_noPublishableChapterIsHard`
- [X] T017 [US1] PHPUnit `apps/api/tests/CoursePublishChecklistServiceTest.php::testBuild_incompleteExtraLessonIsWarning` (one markdown 有效课节 + one enabled processing asset → warning, `hard_error_count = 0`, both lessons in `catalog.chapters`)
- [X] T018 [US1] PHPUnit `apps/api/tests/CoursePublishChecklistServiceTest.php::testBuild_enumeratesArchivedAndIncomplete` (disabled chapter still listed, `is_effective = false`)

### Implementation for User Story 1

- [X] T019 [US1] Implement 有效课节 predicate (enabled chapter + enabled lesson + markdown body / pdf|video `asset_id` with `assets.status = ready`) as a private method on `apps/api/app/service/CoursePublishChecklistService.php` and use it for `catalog.chapters[]`
- [X] T020 [US1] Implement catalog findings in `apps/api/app/service/CoursePublishChecklistService.php` per `data-model.md`: hard `CATEGORY_*` / `INTRO_REQUIRED` / `SALE_WINDOW_EXPIRED` / `NO_PUBLISHABLE_CHAPTER` / `NO_PUBLISHABLE_LESSON`; warning `LESSON_INCOMPLETE` / `ASSET_PROCESSING` / `ASSET_MISSING` / `ASSET_BROKEN`; info `NO_TRIAL_LESSON` / `ALL_LESSONS_TRIAL`
- [X] T021 [US1] Implement `content_fingerprint` SHA-256 over catalog snapshot only (exclude `generated_at`, probe findings, live counts) in `apps/api/app/service/CoursePublishChecklistService.php`
- [X] T022 [US1] Implement `build()` data-scope via `DataScopeService::assertCourseAccessibleFromScope` in `apps/api/app/service/CoursePublishChecklistService.php`. Maps / entitlements / progress may be DTO zeros and empty `maps.items` until US4. Notification must reuse/promote `NotificationDispatchService::activeLearnerSnapshot` in `apps/api/app/service/NotificationDispatchService.php`: live COUNT into `recipient_count`, `will_dispatch` from current course status; on throw set `recipient_unavailable = true` and hard `NOTIFICATION_IMPACT_UNAVAILABLE`. Never hardcode `recipient_count = 0` as a successful count.
- [X] T023 [US1] Implement `CourseController::publishChecklist` in `apps/api/app/controller/admin/CourseController.php` to return `build()` inside `ApiResponse::ok`
- [X] T024 [US1] Create `apps/admin/src/views/catalog/CoursePublishChecklistDialog.vue`: `el-dialog`, load GET on open, `el-alert` for hard/warning/info, catalog chapter/lesson list with `el-tag` for 有效/试看, four impact placeholders, footer 取消 only (confirm is US2)
- [X] T025 [US1] Replace `ElMessageBox.confirm` in `apps/admin/src/views/catalog/CourseEditView.vue` `onPublish` with opening `CoursePublishChecklistDialog` (do not POST publish yet)
- [X] T026 [US1] Vitest `apps/admin/tests/views/catalog/CoursePublishChecklistDialog.test.ts` asserting hard findings render and status-changing POST is not called on open/cancel

**Checkpoint**: Quickstart V1, V2, V3, V4 (dialog shows warnings; confirm not required yet). MVP demoable.

---

## Phase 4: User Story 2 - 预览与发布共用同一份结果, 硬错误为零才通知 (Priority: P1)

**Goal**: Confirm publish re-runs the same `build()`. Hard errors block status flip and `course_published` fan-out. Notification audience is visible before confirm. Already-published POST is idempotent.

**Independent Test**: Green checklist → publish → one dispatch; delete last 有效课节 between preview and confirm → 422 latest findings, zero dispatch; already published → no new dispatch; snapshot throw → 422 `NOTIFICATION_IMPACT_UNAVAILABLE`, zero dispatch.

### Tests for User Story 2

- [X] T027 [US2] PHPUnit `apps/api/tests/CoursePublishChecklistServiceTest.php::testPublish_greenChecklistDispatchesOnce` (or extend `apps/api/tests/CoursePublishNotifyTest.php`) — complete draft, no acknowledge flag, one `course_published` dispatch
- [X] T028 [P] [US2] PHPUnit `apps/api/tests/CoursePublishNotifyTest.php` stays green for unpublish+republish, content-only edit, actor=0 skip, enqueue failure does not roll back
- [X] T029 [US2] PHPUnit `apps/api/tests/CoursePublishChecklistServiceTest.php::testPublish_hardErrorsReturnChecklistAndZeroDispatch` — 422 `PUBLISH_CHECK_FAILED` with `error.checklist`, status unchanged
- [X] T030 [US2] PHPUnit `apps/api/tests/CoursePublishChecklistServiceTest.php::testPublish_alreadyPublishedSkipsDispatch` — second POST no new dispatch, GET still returns checklist
- [X] T031 [US2] PHPUnit `apps/api/tests/CoursePublishChecklistServiceTest.php::testPublish_stalePreviewRerunsBuild` (preview hard_error_count=0, then delete the last 有效课节, then POST publish → 422 `PUBLISH_CHECK_FAILED` with latest findings, status unchanged, zero dispatch)
- [X] T032 [US2] PHPUnit `apps/api/tests/CoursePublishChecklistServiceTest.php::testImpact_recipientUnavailableIsHard` — snapshot throws → `NOTIFICATION_IMPACT_UNAVAILABLE`, publish refused, zero dispatch

### Implementation for User Story 2

- [X] T033 [US2] Replace `assertPublishable` in `apps/api/app/service/CourseService.php` with `build()`; if `hard_error_count > 0` or `recipient_unavailable` throw `BusinessException('VALIDATION_FAILED', 'PUBLISH_CHECK_FAILED', ['checklist' => $dto])` or `NOTIFICATION_IMPACT_UNAVAILABLE`; only then flip status and call existing `notifyCoursePublished`
- [X] T034 [US2] Add `writeAudit()` on `apps/api/app/service/CourseService.php` for `course.publish` / `course.publish.rejected` payload per `data-model.md`
- [X] T035 [US2] Parse optional JSON `{ acknowledge_warnings }` in `apps/api/app/controller/admin/CourseController.php` `publish()` and pass to `publishCourse`; success body remains course tree
- [X] T036 [US2] Enable 确认发布 in `apps/admin/src/views/catalog/CoursePublishChecklistDialog.vue` only after GET returns a real `recipient_count` (or explicit `recipient_unavailable`): POST via `publishCourse`; on 422 with `error.checklist` replace dialog state; disable primary when `hard_error_count > 0` or `recipient_unavailable`
- [X] T037 [US2] Show live `impact.notification.recipient_count` in `apps/admin/src/views/catalog/CoursePublishChecklistDialog.vue` before primary is enabled when `will_dispatch` (FR-016); never treat a placeholder 0 as success; already-published shows 本次不通知
- [X] T038 [US2] Add `publishCourse(id, { acknowledge_warnings?: boolean })` body in `apps/admin/src/api/catalog.ts` without breaking the current tree parse
- [X] T039 [US2] Gate the CourseEdit publish button with `hasPermission('course.publish')` in `apps/admin/src/views/catalog/CourseEditView.vue`

**Checkpoint**: Quickstart V5, V6, V9, V11. `CoursePublishNotifyTest` green.

---

## Phase 5: User Story 3 - 警告不阻断紧急发布, 瞬时问题不当作硬错误 (Priority: P1)

**Goal**: Hard errors still have no bypass. Warnings (probe failure, processing extra lesson) require `acknowledge_warnings` then allow publish and fan-out.

**Independent Test**: Ready course + unreachable ready asset → warning, checkbox, publish succeeds; zero 有效课节 still blocked.

### Tests for User Story 3

- [X] T040 [US3] PHPUnit `apps/api/tests/CoursePublishChecklistServiceTest.php::testProbeFailureIsWarningNotHard` — fake probe unreachable on a `ready` asset → `ASSET_UNREACHABLE`, `is_effective` stays true
- [X] T041 [US3] PHPUnit `apps/api/tests/CoursePublishChecklistServiceTest.php::testWarningsRequireAcknowledge` — warning_count > 0 without flag → 422 `WARNINGS_NOT_ACKNOWLEDGED`, no dispatch; with flag → published
- [X] T042 [US3] PHPUnit `apps/api/tests/CoursePublishChecklistServiceTest.php::testNoHardErrorBypass` — zero 有效课节 cannot publish even if a caller would pass `acknowledge_warnings: true`

### Implementation for User Story 3

- [X] T043 [US3] Implement `NativeAssetReachabilityProbe` using the actual local storage resolver and readability check; keep the builder's 5s budget and `ASSET_PROBE_SKIPPED`. Tests inject `FakeAssetReachabilityProbe`. HTTP probing is inapplicable to protected local storage (verified adjustment: research.md D6).
- [X] T044 [US3] Call probe only for 有效课节 with `asset.status = ready` inside `apps/api/app/service/CoursePublishChecklistService.php`; never change `is_effective`; exclude probe codes from fingerprint
- [X] T045 [US3] Enforce `acknowledge_warnings === true` when `warning_count > 0` in `apps/api/app/service/CourseService.php` `publishCourse` (message `WARNINGS_NOT_ACKNOWLEDGED`)
- [X] T046 [US3] Add `el-checkbox` 「我已知晓以上警告与通知影响」 in `apps/admin/src/views/catalog/CoursePublishChecklistDialog.vue`; disable primary until checked when `warning_count > 0`; send `acknowledge_warnings: true`
- [X] T047 [US3] Extend `apps/admin/tests/views/catalog/CoursePublishChecklistDialog.test.ts` for checkbox gate and hard-error still disabling primary

**Checkpoint**: Quickstart V4 (with confirm), V7.

---

## Phase 6: User Story 4 - 发布前看到地图、学员、进度与通知影响 (Priority: P2)

**Goal**: Impact bar is real: published maps (recovery vs reference), active entitlements vs 在册学员, progress denominator warning. Notification count and snapshot-failure hard error already land in US1/US2 (T022, T032, T036).

**Independent Test**: Unpublished course on a published map + 2 entitlements + extra 有效课节 → four bars populated and distinct counts.

### Tests for User Story 4

- [X] T048 [US4] PHPUnit `apps/api/tests/CoursePublishChecklistServiceTest.php::testImpact_publishedMapsRecover` — published map + unpublished course → `will_recover_abnormal_step = true`; draft map only increments `draft_count`
- [X] T049 [US4] PHPUnit `apps/api/tests/CoursePublishChecklistServiceTest.php::testImpact_zeroMapsExplicit` — `published_count = 0`, `items = []`
- [X] T050 [US4] PHPUnit `apps/api/tests/CoursePublishChecklistServiceTest.php::testImpact_entitlementsNotRecipientCount` — 2 active entitlements, N active learners, numbers differ
- [X] T051 [US4] PHPUnit `apps/api/tests/CoursePublishChecklistServiceTest.php::testImpact_progressWarning` — enrollments exist and enabled-lesson count ≠ 有效课节 count → `PROGRESS_DENOMINATOR_CHANGES` + `completed_preserved: true`

### Implementation for User Story 4

- [X] T052 [US4] Query published maps via `learning_maps` ⋈ `map_stage_courses` in `apps/api/app/service/CoursePublishChecklistService.php` (status=published only; recovery when course status ≠ published)
- [X] T053 [US4] Count `course_entitlements` `status = active` in `apps/api/app/service/CoursePublishChecklistService.php`
- [X] T054 [US4] Fill progress impact in `apps/api/app/service/CoursePublishChecklistService.php`: `current_denominator` = enabled lessons (legacy, no chapter filter); `next_denominator` = 有效课节; `will_recalculate` per data-model.md
- [X] T055 [US4] Render four impact blocks in `apps/admin/src/views/catalog/CoursePublishChecklistDialog.vue` (maps list, two learner counts labeled 已有访问权 vs 在册学员, progress warning, notification). Never hide the notification block
- [X] T056 [US4] Extend `apps/admin/tests/views/catalog/CoursePublishChecklistDialog.test.ts` to assert both learner counts render as separate labels

**Checkpoint**: Quickstart V6, V8.

---

## Phase 7: User Story 5 - 核验与发布不得破坏已完成进度和订单快照 (Priority: P2)

**Goal**: Publish/checklist never un-completes lessons or rewrites order snapshots. Status-changing publish recalculates enrollment percent on 有效课节 and explains to enrolled learners only.

**Independent Test**: Completed lesson A + paid order; archive B, add 有效课节 C, republish → A still completed, order snapshots unchanged, percent uses new denominator.

### Tests for User Story 5

- [X] T057 [US5] PHPUnit `apps/api/tests/CoursePublishChecklistServiceTest.php::testPublish_doesNotUncompleteLesson` (`lesson_progresses.completed` stays 1)
- [X] T058 [US5] PHPUnit `apps/api/tests/CoursePublishChecklistServiceTest.php::testPublish_doesNotRewriteOrderSnapshots` (`list_price_snapshot` / `sale_price_snapshot` / `coupon_discount_snapshot` / `paid_amount` unchanged)
- [X] T059 [US5] PHPUnit `apps/api/tests/CoursePublishChecklistServiceTest.php::testBuild_doesNotTouchProgressEntitlementsOrders`
- [X] T060 [P] [US5] PHPUnit `apps/api/tests/ProgressServiceTest.php::testRecalculate_usesEffectiveLessonsAndStickyCompletedAt`

### Implementation for User Story 5

- [X] T061 [US5] Add `recalculateCourseEnrollments(int $courseId): void` on `apps/api/app/service/ProgressService.php` using the same 有效课节 predicate as the checklist; keep `lesson_progresses.completed`; keep sticky `course_enrollments.completed_at`
- [X] T062 [US5] Call recalc from `apps/api/app/service/CourseService.php` after a status-changing publish when `enrollment_count > 0`
- [X] T063 [US5] Add `KIND_PROGRESS_CATALOG_CHANGED` in `apps/api/app/service/MessageService.php` and emit to enrollment learners only when `will_recalculate`, idempotency `progress_catalog_changed:{courseId}:{fingerprint}`
- [X] T064 [US5] Add `progress_catalog_changed` to `packages/contracts/src/notification.ts` `LearnerNotificationKind` and a case in `packages/contracts/src/__tests__/notification.test.ts`
- [X] T065 [US5] Add type label in `apps/web/src/views/me/StudentCenterView.vue` (and `apps/web/tests/StudentCenterView.test.ts` if the kind map is tested)

**Checkpoint**: Quickstart V8 (progress/order clauses), V1 (GET has no side effects).

---

## Phase 8: Polish & Cross-Cutting Concerns

**Purpose**: Remaining entry points, permissions, regressions, docs, gates.

- [X] T066 [P] Open the same dialog from `apps/admin/src/views/catalog/CourseListView.vue` `onPublish` instead of `ElMessageBox.confirm`
- [X] T067 [P] Open the same dialog from `apps/admin/src/views/catalog/CoursePreviewView.vue` `onPublish`
- [X] T068 Hide publish buttons without `course.publish` in `apps/admin/src/views/catalog/CourseListView.vue` and `apps/admin/src/views/catalog/CoursePreviewView.vue`
- [X] T069 Extend `apps/admin/tests/CourseListView.test.ts` so publish opens the checklist dialog rather than native/`ElMessageBox` only
- [X] T070 Confirm `apps/api/app/service/CourseService.php` `unpublishCourse` behavior unchanged (no checklist, no new notify) via existing or a short assertion in `apps/api/tests/CoursePublishNotifyTest.php`
- [X] T071 Confirm unpublished courses still appear in ops inbox `course_unpublished` (no change required in `apps/api/app/service/OpsInboxService.php` unless a regression appears)
- [x] T072 Run backend gates on `apps/api` (repository PHPCS/PSR12 + PHP syntax / phpstan / PHPUnit: `CoursePublishChecklistServiceTest`, `CoursePublishNotifyTest`, `AuthorizeLeakTest`, `ProgressServiceTest`) and frontend gates on touched packages (prettier, eslint, tsc, Vitest, production build)
- [x] T073 Walk `specs/015-course-publish-checklist/quickstart.md` V1–V11 on Compose (`make up`); browser/HTTP scenarios plus service integration checks, evidence below

**Checkpoint**: Feature complete. Constitution Check still PASS. No new table, no new deps.

---

## Dependencies & Execution Order

### Phase Dependencies

- **Setup (Phase 1)**: No dependencies
- **Foundational (Phase 2)**: Depends on Setup — BLOCKS all user stories
- **US1 (Phase 3)**: Depends on Foundational — MVP
- **US2 (Phase 4)**: Depends on US1 (`build()` + dialog)
- **US3 (Phase 5)**: Depends on US2 (publish path)
- **US4 (Phase 6)**: Depends on US1 GET DTO and US2 notification snapshot; can overlap US3 if `CoursePublishChecklistService.php` edits are sequenced
- **US5 (Phase 7)**: Depends on US2 status flip
- **Polish (Phase 8)**: Depends on US1–US5 desired for the increment

### User Story Dependencies

- **US1**: After Phase 2 only
- **US2**: After US1
- **US3**: After US2
- **US4**: After US1 + US2 notification snapshot (T022/T032); maps/entitlements/progress only
- **US5**: After US2; progress tests independent of probe/UI checkbox

### Within Each User Story

- Tests first and failing
- Predicate / findings before HTTP
- HTTP before dialog
- Story checkpoint before next priority

### Parallel Opportunities

- T002 / T003 after T001
- T005 / T012 / T013 during foundation (different files)
- US1 tests T014–T018 are the same PHPUnit file — sequential methods, not `[P]`
- T028 can run beside other US2 tests (`CoursePublishNotifyTest.php` is a different file)
- T060 can run beside T057–T059 (`ProgressServiceTest.php` is a different file)
- T066 / T067 polish UI files in parallel; T068 touches both, run after
- T005 / T012 / T013 during foundation (different files)

Same-file warning: `CoursePublishChecklistService.php`, `CourseService.php`, `CoursePublishChecklistDialog.vue` are sequential choke points.

---

## Parallel Example: User Story 1

```bash
# Tests in parallel:
Task: "testBuild_doesNotChangeStatusOrDispatch in apps/api/tests/CoursePublishChecklistServiceTest.php"
Task: "testBuild_fingerprintAndHardErrorsStable in apps/api/tests/CoursePublishChecklistServiceTest.php"
Task: "testBuild_listsHardErrorsWithLocations in apps/api/tests/CoursePublishChecklistServiceTest.php"
Task: "testBuild_incompleteExtraLessonIsWarning in apps/api/tests/CoursePublishChecklistServiceTest.php"
Task: "testBuild_enumeratesArchivedAndIncomplete in apps/api/tests/CoursePublishChecklistServiceTest.php"

# Then sequential implementation on CoursePublishChecklistService.php (T019–T022)
# Then controller + dialog (T023–T026)
```

---

## Implementation Strategy

### MVP First (User Story 1 Only)

1. Phase 1 Setup
2. Phase 2 Foundational
3. Phase 3 US1 — GET checklist + dialog preview, no publish change
4. **STOP and VALIDATE** quickstart V1–V3

### Incremental Delivery

1. US1 → operators can inspect a course before anyone changes the button
2. US2 → button becomes the gate; notifications wait for hard_error_count = 0
3. US3 → emergency publish with acknowledged warnings + probe
4. US4 → impact bar truth
5. US5 → progress/order safety
6. Polish remaining surfaces

### Parallel Team Strategy

After Phase 2: one person on US1 service tests+build, another on dialog shell. After US1: backend US2/US5 vs frontend US3 checkbox can split; US4 stays on the checklist service.

---

## Notes

### Implementation verification (2026-09-06)

- Backend: Compose PHPUnit 400 tests / 1527 assertions passed (1 PHPUnit deprecation); PHPStan passed. PHP syntax passed; new PHP files passed repository PHPCS/PSR12 error checks with line-length warnings. The repository uses PHPCS, not php-cs-fixer.
- Frontend: contracts 94, web 159, admin 150 Vitest tests passed. Admin full run used `--maxWorkers=2 --minWorkers=1` after an existing order-view test timed out under full concurrency. Touched frontend formatting, lint, typecheck and production builds passed.
- Isolated Compose Playwright: 5 admin + 1 learner journeys passed. V1/V2/V3/V4/V5/V6/V9 use real browser/HTTP checks; V7 uses injected probe integration checks; V8 uses map, entitlement, progress and order-snapshot integration checks; V10 combines restricted-staff E2E, UI tests and authorization tests; V11 uses `CoursePublishNotifyTest`. Related task test cases are consolidated into comprehensive methods where they share fixtures.
- Desktop 1280x720 and mobile 390x844 screenshots were inspected: notification count and confirmation controls remain visible. Local evidence: `/tmp/learn-015-evidence/course-publish-desktop.png`, `/tmp/learn-015-evidence/course-publish-mobile.png`; E2E log: `/tmp/learn-015-e2e-final.log`; latest backend log: `/tmp/learn-015-api-last.log`.
- API/admin/web runtime images rebuilt and local services healthy. No schema migration or dependency added for this feature. Existing unrelated worktree changes retained; no commit or push performed.
- SC-004 and SC-010 require human usability observations; their 90%/80% targets have not been measured. Automated walkthroughs do not establish those ratios.

- [P] = different files, no incomplete deps
- Do not add a Phinx table or Composer/npm dependency
- `NO_TRIAL_LESSON` is **info** so existing `CoursePublishNotifyTest` fixtures (is_preview=0) still publish without acknowledge
- GET must never flip status or fan-out
- Super-admin has no hard-error bypass
- Commit after each story checkpoint; tests travel with the story (H4)
