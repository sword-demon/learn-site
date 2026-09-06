# Implementation Plan: 课程发布核验清单

**Branch**: `015-course-publish-checklist` | **Date**: 2026-09-06 | **Spec**: [spec.md](./spec.md)

**Input**: Feature specification from `/specs/015-course-publish-checklist/spec.md`

## Summary

Replace the current "at least one good lesson" publish button with a repeatable gate: every publish (and every preview) builds the same deterministic course inventory, graded findings, and impact bar (published maps, active entitlements, progress denominator, 课程发布消息 audience). Hard errors stay at zero before status flips and before `course_published` fan-out starts. Warnings (incomplete extra lessons, asset reachability, progress recalculation) require an explicit acknowledgement so emergency publish still works. Completed lesson progress and order snapshots are never rewritten. Implementation extracts `CoursePublishChecklistService`, adds `GET /courses/{id}/publish-checklist`, tightens `POST /publish`, and swaps the three admin confirm boxes for one `el-dialog`.

## Technical Context

**Language/Version**: PHP 8.4 (Webman) backend; TypeScript 5.x (Vue 3) admin SPA; learner SPA only gains a message-kind label
**Primary Dependencies**: Existing stack only — `workerman/webman-framework ^2.2`, `webman/think-orm ^2.1`, Vue 3, Element Plus, Zod, Axios. No new Composer or npm packages. Asset probe uses the existing protected local-storage resolver (verified adjustment in research.md D6).
**Storage**: MySQL 8 existing tables (`courses`, `chapters`, `lessons`, `assets`, `learning_maps`, `map_stage_courses`, `course_entitlements`, `course_enrollments`, `lesson_progresses`, `orders`, `accounts`, `learners`, `audit_log`, `learner_notifications`). No new table.
**Testing**: PHPUnit (`apps/api/tests/`), Vitest (`apps/admin/tests/`, `packages/contracts`)
**Target Platform**: Docker Compose (OrbStack on macOS); existing API + admin services
**Project Type**: Web application — backend + admin SPA (primary); learner SPA copy-only for `progress_catalog_changed`
**Performance Goals**: Checklist GET P95 < 2s for a course with ≤30 lessons including probe budget; dialog usable within 2 minutes (SC-004); publish HTTP still returns before fan-out completes (`010` FR-008)
**Constraints**: Probe 2s/asset and 5s overall, warning-only; no Redis keys; no super-admin hard-error bypass; `CourseController::wrap` must forward exception details
**Scale/Scope**: 1 new backend service + probe port; 1 new GET; POST body flag; 1 admin dialog reused in 3 views; Zod module; PHPUnit + Vitest. Learner surface: one inbox kind label.

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

| Principle | Status | Notes |
|---|---|---|
| **I. 容器即运行契约** | PASS | No Compose / image / port change |
| **II. 稳定兼容且可复现** | PASS | No new deps; lockfiles unchanged |
| **III. 契约优先与端到端类型安全** | PASS | Zod in `packages/contracts/src/coursePublishChecklist.ts`; admin parses GET/POST with Zod; no `any` |
| **IV. 数据变更安全可追溯** | PASS | No new table. think-orm only. `progress_catalog_changed` fits existing VARCHAR kind. Audit via `audit_log` |
| **V. 质量、安全与可运维性内建** | PASS | PHPUnit for service/gate/notify regression; Vitest for dialog; existing CI gates |
| **VI. 令牌鉴权、无感续期与可踢下线** | N/A | Admin-only; inherits AdminAuth. Explicit `course.view` map for the new GET so the path is not unmapped |
| **技术约束** | PASS | Webman + think-orm; native HTTP probe; no parallel ORM; Redis unused |
| **开发流程 #1** | PASS | Spec + this plan + research/data-model/contracts/quickstart |
| **开发流程 #2** | PASS | One new service for an existing variation point; no speculative queue |
| **开发流程 #3** | PASS | Same backend/frontend gates as the rest of the repo |
| **开发流程 #4** | PASS | Contracts, Authorize map, Zod, tests updated together |
| **开发流程 #5** | PASS | No Docker change; smoke via quickstart V1–V11 on Compose |
| **开发流程 #6** | PASS | Decisions D1–D12 in research.md |
| **开发流程 #7** | PASS | Done when quickstart passes, notify tests still green, constitution still PASS |

**Gates**: ALL PASS. No unjustified violations. Post-design re-check: still PASS — design added no table, no deps, no Redis, no learner checklist API.

## Project Structure

### Documentation (this feature)

```text
specs/015-course-publish-checklist/
├── spec.md
├── plan.md              # This file
├── research.md
├── data-model.md
├── quickstart.md
├── contracts/
│   ├── api-contract.md
│   └── course-publish-checklist.ts
└── tasks.md             # Phase 2 ($speckit-tasks) — not created here
```

### Source Code (repository root)

**Backend (new)**:
- `apps/api/app/service/CoursePublishChecklistService.php` — `build()`, fingerprint, findings, impact
- `apps/api/app/service/AssetReachabilityProbe.php` — interface + native HTTP adapter
- `apps/api/tests/CoursePublishChecklistServiceTest.php`

**Backend (modified)**:
- `apps/api/app/service/CourseService.php` — `publishCourse(..., bool $acknowledgeWarnings = false)`; replace `assertPublishable`; `writeAudit`
- `apps/api/app/service/ProgressService.php` — `recalculateCourseEnrollments()` using 有效课节 predicate
- `apps/api/app/service/NotificationDispatchService.php` — reuse `activeLearnerSnapshot` (promote to package-visible if still private)
- `apps/api/app/controller/admin/CourseController.php` — `publishChecklist`; publish reads body; `wrap` forwards details
- `apps/api/app/route.php` — GET publish-checklist
- `apps/api/app/middleware/Authorize.php` — GET → `course.view`
- `apps/api/app/service/MessageService.php` — kind constant
- `apps/api/tests/CoursePublishNotifyTest.php` — still green without acknowledge flag
- `apps/api/tests/AuthorizeLeakTest.php` — new path → `course.view`

**Frontend admin (new)**:
- `apps/admin/src/views/catalog/CoursePublishChecklistDialog.vue`
- `apps/admin/tests/views/catalog/CoursePublishChecklistDialog.test.ts`

**Frontend admin (modified)**:
- `apps/admin/src/api/catalog.ts` — `fetchPublishChecklist`, `publishCourse(id, { acknowledge_warnings })`
- `apps/admin/src/views/catalog/CourseEditView.vue`
- `apps/admin/src/views/catalog/CourseListView.vue`
- `apps/admin/src/views/catalog/CoursePreviewView.vue`
- `apps/admin/tests/CourseListView.test.ts` — publish opens dialog, not MessageBox

**Frontend learner (modified, small)**:
- `apps/web/src/views/me/StudentCenterView.vue` — label for `progress_catalog_changed`
- `packages/contracts/src/notification.ts` — add kind to `LearnerNotificationKind`

**Shared / docs**:
- `packages/contracts/src/coursePublishChecklist.ts`
- `packages/contracts/src/index.ts` — export
- `packages/contracts/src/__tests__/coursePublishChecklist.test.ts` (or `packages/contracts/tests/`)
- `CONTEXT.md` — add 发布核验清单 / 硬错误 / 警告 / 影响栏; keep 有效课节 / 课程发布消息

**Structure Decision**: Web application (admin + API). Learner app is not a checklist surface. Mirrors `010` (publish notify) + map `publish_issues` UI, with a dedicated service like `014` so `CourseService` does not absorb another domain.

## Complexity Tracking

No constitution violations. Extraction of `CoursePublishChecklistService` is a simplification of `CourseService`, not an extra abstraction layer.

## Implementation Phases (high-level; detail in tasks.md)

1. **Phase A — Contracts**: Zod module, finding codes, publish input, contract tests.
2. **Phase B — Checklist builder**: Service + GET + Authorize + PHPUnit for catalog/hard/warning/info and fingerprint stability. Fake probe.
3. **Phase C — Publish gate**: Wire POST, `acknowledge_warnings`, details-forwarding wrap, audit, keep `CoursePublishNotifyTest` green.
4. **Phase D — Impact bars**: Maps, entitlements, learner snapshot, progress warning. FR-017 unavailable snapshot.
5. **Phase E — Probe**: Native HTTP adapter, timeouts, warning-only, skip budget.
6. **Phase F — Progress/orders**: Recalc enrollments; `progress_catalog_changed`; order snapshot assertion.
7. **Phase G — Admin dialog**: Shared dialog + three entry points + `course.publish` visibility + Vitest.
8. **Phase H — Regression**: Notify idempotency, unpublish unchanged, ops inbox still lists unpublished courses.

## Done When

- Quickstart V1–V11 pass on a Compose env
- PHPUnit + Vitest green, including existing `CoursePublishNotifyTest`
- Hard-error publishes start 0 `course_published` dispatches
- Constitution Check remains ALL PASS
