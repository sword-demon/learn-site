# Research: 课程发布核验清单

**Feature**: [spec.md](./spec.md)
**Branch**: `015-course-publish-checklist`
**Date**: 2026-09-06

## Codebase Context (existing patterns to reuse)

| Layer | Canonical pattern | Reference |
|---|---|---|
| Backend service | `final class`, namespace `App\service`, one per domain | `app/service/OpsInboxService.php` |
| Backend controller | Thin `wrap()` + `mapApiCode()`, DI constructor | `app/controller/admin/CourseController.php` |
| Publish write | `CourseService::publishCourse` (~809 lines file) flips status then `notifyCoursePublished` | `app/service/CourseService.php:153-210` |
| Current gate | `assertPublishable`: category enabled, intro non-empty, sale window, **first** enabled lesson with payload | `CourseService.php:569-616` |
| Map issues | Computed `publish_issues` on admin map detail; UI `el-alert` list | `LearningMapService.php`, `MapEditorView.vue` |
| Abnormal maps | Published map + referenced course not published | `DashboardService.php:115` |
| Entitlements | `course_entitlements.status = active` | `EntitlementService.php:54-58` |
| 在册学员 | `accounts.status = active` JOIN `learners` | `NotificationDispatchService::activeLearnerSnapshot()` |
| Fan-out | Status flip commits first; enqueue failure does not roll back publish | `CourseService::notifyCoursePublished`, `010` FR-008 |
| Audit | Service-private `writeAudit()` into `audit_log` | `CourseFeedbackService`, `OpsInboxService` |
| Timezone | `Asia/Shanghai` private const + `DateTimeImmutable` | `OpsInboxService.php:15` |
| Frontend confirm | `ElMessageBox.confirm` today; complex forms use `el-dialog` | `CourseEditView.vue:573`, `NotificationComposeDialog.vue` |
| Permission | `POST .../publish` → `course.publish`; GET course → `course.view` | `Authorize.php:58-59,100-101` |

## Decisions

### D1. Extract `CoursePublishChecklistService`; do not grow `CourseService`

**Decision**: New `App\service\CoursePublishChecklistService` owns building the deterministic inventory, findings, impact, and reachability warnings. `CourseService::publishCourse` calls `build()` then `assertPublishableFromChecklist()`. `assertPublishable()` is replaced, not duplicated.

**Rationale**: `CourseService` already mixes CRUD, tree shaping, and a short-circuit publish gate (~710 lines; smell report). A second, richer gate in the same class would hide the feature behind catalog noise. Map publish already uses a dedicated `collectPublishIssues()` on `LearningMapService`; course publish needs the same seam but with impact + severity, which is enough to justify a focused service.

**Alternatives considered**:
- Keep everything in `CourseService` — rejected: the class is already the catalog bottleneck.
- Generic `PublishGate` for courses and maps — rejected: map issues are three codes with no impact bar; unifying now is speculative.

### D2. Checklist is computed; persist only via `audit_log`

**Decision**: No `course_publish_versions` table. GET and POST both call `build()` against live rows. Successful publish writes `audit_log.action = course.publish` with a compact payload (hard_error_count=0, warning_count, will_dispatch, recipient_count, acknowledge_warnings, content fingerprint). Blocked publish may write `course.publish.rejected` with finding codes (optional but useful).

**Rationale**: FR-034 forbids a version product. FR-029 allows a snapshot only for audit/contrast. `audit_log` is the existing write-audit path (H3). A new table would be a second source of truth that can drift from the catalog.

**Alternatives considered**:
- Store full JSON checklist per publish — rejected: duplicates catalog, invites "rollback to version" scope.
- Preview token / nonce that publish must present — rejected: spec says stale preview cannot authorize; re-run at confirm time is simpler and correct.

### D3. GET preview + POST publish with `acknowledge_warnings`

**Decision**:
- `GET /api/admin/v1/courses/{id}/publish-checklist` — `course.view` + data scope; no status change.
- `POST /api/admin/v1/courses/{id}/publish` body `{ acknowledge_warnings?: boolean }` — `course.publish` + data scope; re-runs `build()`; flips status only when `hard_error_count === 0`; if `warning_count > 0` requires `acknowledge_warnings === true`.
- Success response of POST stays a course tree (backward compatible with `CoursePublishNotifyTest` and admin `publishCourse` parser).
- Failure: `422 VALIDATION_FAILED` with `error.message` in `{PUBLISH_CHECK_FAILED, WARNINGS_NOT_ACKNOWLEDGED, NOTIFICATION_IMPACT_UNAVAILABLE}` and `error.checklist` = same DTO as GET.

**Rationale**: Spec FR-011/012/013: same rules, re-run at confirm, hard errors block. Existing publish clients keep working when the fixture has zero warnings (current notify tests: one complete markdown lesson, no sale window).

**Authorize**: nested `GET .../publish-checklist` does **not** match `#^/api/admin/v1/courses(?:/\d+)?$#`. Must add an explicit regex **before** the generic course rule; otherwise the path is unmapped and skips permission (handler continues). Map GET to `course.view`.

**Alternatives considered**:
- Return checklist inside 200 on a failed publish — rejected: would look like success to Axios wrappers that only check HTTP.
- New error code in `ErrorCode` enum — not required; `VALIDATION_FAILED` + stable `message` is the existing catalog pattern (`CATEGORY_DISABLED`, `INTRO_REQUIRED`).

### D4. Hard vs warning vs info (so existing publishes do not need a new flag)

**Decision**:

| Level | Codes |
|---|---|
| hard | `CATEGORY_NOT_FOUND`, `CATEGORY_DISABLED`, `INTRO_REQUIRED`, `SALE_WINDOW_EXPIRED`, `NO_PUBLISHABLE_CHAPTER`, `NO_PUBLISHABLE_LESSON`, `NOTIFICATION_IMPACT_UNAVAILABLE` |
| warning | `LESSON_INCOMPLETE`, `ASSET_PROCESSING`, `ASSET_MISSING`, `ASSET_BROKEN`, `ASSET_UNREACHABLE`, `ASSET_PROBE_SKIPPED`, `PROGRESS_DENOMINATOR_CHANGES` |
| info | `NO_TRIAL_LESSON`, `ALL_LESSONS_TRIAL`, `MAP_REFERENCE`, `MAP_STEP_WILL_RECOVER`, `NOTIFICATION_WILL_SEND`, `NOTIFICATION_SKIPPED_ALREADY_PUBLISHED` |

`NO_TRIAL_LESSON` is **info**, not warning. FR-008 allows 说明; classifying it as warning would force `acknowledge_warnings` on every current catalog fixture (they ship `is_preview = 0`) and would block emergency publish of complete courses that simply have no trial.

`NOTIFICATION_IMPACT_UNAVAILABLE` is hard (FR-017): cannot publish or start fan-out if 在册学员 count cannot be read.

Already-published courses: GET still builds the list; POST is idempotent (no new dispatch, `010` FR-002). `will_dispatch = false`, info `NOTIFICATION_SKIPPED_ALREADY_PUBLISHED`. `acknowledge_warnings` ignored because no state change.

**Alternatives considered**:
- Every incomplete enabled lesson is hard — rejected: spec says that blocks emergency publish when at least one 有效课节 remains.
- Network failure is hard — rejected: spec SC-005.

### D5. 有效课节 definition aligned with catalog + assets

**Decision**: A lesson is 有效课节 iff:
1. Parent chapter `status = enabled`
2. Lesson `status = enabled`
3. Content complete:
   - `markdown`: trimmed `body_markdown` non-empty
   - `pdf` / `video`: `asset_id > 0` and joined `assets.status = ready`

`processing` / `missing` / `broken` / missing asset_id → not 有效. If any 有效课节 exist, those rows are warnings; if none exist, `NO_PUBLISHABLE_LESSON` is hard.

Disabled/archived chapters and lessons still appear in the inventory (FR-004) with `is_effective = false`.

`is_preview` is listed; it never decides hard/warning except the info codes in D4.

**Rationale**: `AssetDTO.status` already encodes processing/ready/missing/broken. Current `lessonHasPayload` only checks `asset_id > 0`, which would treat a processing video as publishable. The checklist must not.

**ProgressService today** (`refreshEnrollment` L283–327):
- Denominator = every `lessons.status='enabled'` under the course, **including lessons in disabled chapters**. Publish gate only scans **enabled** chapters. 015 有效课节 requires enabled chapter + enabled lesson + complete payload, closing both gaps.
- `lesson_progresses.completed = 1` is never flipped to 0.
- Enrollment `completed_at` is sticky: a later recalc that would set it to null keeps the old timestamp. `recalculateCourseEnrollments` must reuse that rule, not invent a stricter un-complete.

Asset upload currently writes `assets.status='ready'` immediately (`AssetController`); a processing worker is not shipped. `ASSET_PROCESSING` remains a first-class warning for when that status appears.

### D6. Reachability probe is injected, budgeted, warning-only

**Implementation verification (2026-09-06)**: `AssetController` stores only `uploads/YYYY/MM/<hex>.<ext>` and learner delivery uses `LocalAssetStorage::resolve()` behind authorization. There is no public asset URL or remote storage adapter. The native probe therefore reuses that resolver and checks file readability; failures remain warnings and the builder retains its 5s budget. Anonymous HTTP HEAD against this service would test authorization instead of the resource. HTTP HEAD/short GET with a 2s timeout belongs with a future remote-storage adapter, not the current local-storage path. `FakeAssetReachabilityProbe` and the production adapter are in separate PSR-4 files so Composer can autoload both.

**Decision**: `AssetReachabilityProbe` interface with `probe(string $storagePath): ProbeResult`. Production adapter uses PHP native HTTP (`HEAD`, fallback `GET` with a short range) via `stream_context_create`; **no new Composer package**. Timeouts: 2s per asset, 5s overall budget; remaining ready assets become warning `ASSET_PROBE_SKIPPED`. Tests inject a fake that returns reachable without I/O.

Probe runs only for 有效课节 whose asset is `ready`. Probe failure never changes `is_effective`. Catalog fingerprint **excludes** probe findings so two builds with identical catalog still match on hard errors even if the network flaps.

**Rationale**: Spec: 瞬时问题 are warnings; 确定性 applies to the course inventory. Adding Guzzle would violate constitution II (no new deps for this feature).

**Alternatives considered**:
- Skip probe entirely in v1 — rejected: spec US3 explicitly requires classifying reachability as warning, which means it must run (or be skipped as warning).
- Queue a background probe — rejected: preview must show the warning before confirm; async would hide it.

### D7. Impact queries reuse live tables; two learner counts stay separate

**Decision**:

| Impact | Query |
|---|---|
| Published maps | `learning_maps` (status=published) ⋈ `map_stages` ⋈ `map_stage_courses` where `course_id = :id`. `will_recover_abnormal_step` when current course status ≠ `published`. Draft map hits returned as `draft_count` only, not in `items`. |
| Active entitlements | `COUNT(*)` from `course_entitlements` where `course_id` and `status = active` |
| 在册学员 / notify count | Reuse `NotificationDispatchService` snapshot (`accounts.status=active` JOIN `learners`). If this query throws, set `recipient_unavailable=true` and emit hard `NOTIFICATION_IMPACT_UNAVAILABLE`. |
| Progress | `next_denominator` = 有效课节 count (D5). `current_denominator` = enabled lesson count with **no** chapter-status filter (legacy `refreshEnrollment`). `will_recalculate` = `enrollment_count > 0 && current_denominator !== next_denominator`. Status-changing publish with enrollments always calls `recalculateCourseEnrollments`. Abnormal map steps are **derived** (`available = course.status === 'published'` in `LearningMapService` L762), not a stored flag — re-publish recovers them by read path only. |

Zero map references must still serialize `{ published_count: 0, items: [], draft_count: N }`.

**Rationale**: Spec FR-023 insists 已有访问权学员 ≠ 在册学员. Fan-out already counts the latter. Dashboard already knows how to find published maps whose course is not published.

### D8. Progress and orders: recalculate percent, never mutate completion or orders

**Decision**:
- `publishCourse` after a successful status flip, if `enrollment_count > 0`, calls `ProgressService::recalculateCourseEnrollments($courseId)` in-request. Formula: completed 有效课节 / 有效课节. `lesson_progresses.completed = 1` stays `1`. Enrollment `completed_at` follows existing sticky rule (do not null a previously set timestamp).
- If `will_recalculate` was true, emit inbox rows **only** to learners with a `course_enrollments` row for this course, kind `progress_catalog_changed`. Idempotency key `progress_catalog_changed:{courseId}:{fingerprint}`.
- **No** `UPDATE` on `orders`. Assert `list_price_snapshot`, `sale_price_snapshot`, `coupon_discount_snapshot`, `paid_amount` unchanged. Course title on order views may still join live `courses.title` (existing; not a snapshot column).
- Fan-out still uses `activeLearnerSnapshot` + `id <= max_id` / `created_at <= dispatch.created_at`. Checklist preview copies that COUNT query. `actorStaffAccountId === null` or `0` still skips `notifyCoursePublished` (existing; controller passes `account_id`).
- This learner message is **not** the admin checklist (FR-033) and **not** a second `course_published` blast.

**Rationale**: US5 / FR-026–028. Reusing `progress_reset` would lie (that kind means admin-initiated relearn). A new VARCHAR kind is enough; web `StudentCenterView` already maps kinds to tags and needs one more label.

**Alternatives considered**:
- Recalculate lazily on next lesson ping — rejected: stale percent until the learner opens a lesson; spec wants publish-time recalc.
- Fan-out progress messages through `NotificationDispatchService` to all 在册学员 — rejected: wrong audience.

### D9. Frontend: one `el-dialog`, three entry points, `course.publish` visible

**Decision**: New `apps/admin/src/views/catalog/CoursePublishChecklistDialog.vue` opened from `CourseEditView`, `CourseListView`, `CoursePreviewView` instead of `ElMessageBox.confirm`. Load GET on open; confirm calls POST with `acknowledge_warnings` from `el-checkbox` when `warning_count > 0`. Hard errors or `recipient_unavailable` disable the primary button. 422 responses replace dialog state with `error.checklist`.

Publish buttons get `v-if="hasPermission('course.publish')"` (maps already do this; courses do not — today a `course.manage` without `course.publish` still sees the button and gets 403).

H2: `el-dialog` + `el-alert` + `el-tag` + `el-checkbox`; no native `confirm` / custom `.badge`.

**Rationale**: The confirm box cannot hold four impact blocks + graded lists. Map editor is the issue-list pattern; compose-notification dialog is the footer-button pattern.

### D10. No new permission code, no learner checklist API, no new deps

**Decision**: Reuse `course.view` / `course.publish`. No learner endpoints. No Composer/npm packages. No Redis keys (constitution VI Redis remains tokens/captcha; this feature does not add cache). No Webman process changes.

### D11. Controller must forward `BusinessException::$details`

**Decision**: `CourseController::wrap` today drops `$e->details`. Publish and checklist actions must pass details into `ApiResponse::fail(..., $e->details)` so `error.checklist` reaches the admin SPA. Prefer fixing `wrap` once rather than a one-off in `publish()`.

**Rationale**: `BusinessException` already has `$details`; `ApiResponse::fail` already merges them onto `error`. The gap is only the course controller.

### D12. Determinism fingerprint

**Decision**: `content_fingerprint` = SHA-256 of canonical JSON of the catalog snapshot (course id/status/category/intro-empty/price fields, chapter/lesson ids, order, status, content_type, is_preview, is_effective, asset_id, asset.status). Excludes `generated_at`, probe findings, live counts. Tests assert two builds with unchanged catalog share fingerprint and hard-error code sets.

## Constitution implications

- No new service containers, images, or Compose keys (I).
- No dependency or lockfile change (II).
- Zod in `packages/contracts` + PHP service validation (III).
- No schema table. Kind `progress_catalog_changed` needs no ALTER (kind is VARCHAR). (IV) PASS without a Phinx file unless we later persist snapshots.
- PHPUnit + Vitest for the new service/dialog (V).
- Admin auth unchanged (VI).

## Open items resolved

All Technical Context unknowns are resolved above. No remaining `NEEDS CLARIFICATION`.
