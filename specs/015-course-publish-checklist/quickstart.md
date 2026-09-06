# Quickstart: 课程发布核验清单

**Feature**: [spec.md](./spec.md)
**Date**: 2026-09-06

Validation guide after the feature is wired. Implementation belongs in `tasks.md`.

## Prerequisites

- `make up` (or the README Compose flow) and `make migrate`
- Admin SPA logged in as a staff account with `course.view` + `course.publish` and the course in data scope
- At least one active 学员 account (so notification `recipient_count` is non-zero)

## Seed (admin UI or SQL fixtures)

| Fixture | How |
|---|---|
| Ready draft | Enabled category, non-empty intro, one enabled chapter, one markdown 有效课节, paid with `sale_price = 0` |
| Hard-blocked | Same but empty intro **or** no 有效课节 |
| Warning-only | Ready draft **plus** a second enabled lesson with `asset_id` pointing at `processing` |
| Map recovery | Published learning map whose step is this course; course currently `unpublished` |
| Entitled learner | Active `course_entitlements` + a `course_enrollments` row with at least one completed 有效课节 |
| Order snapshot | Successful paid order for this course; record `list_price` / paid amount before publish |

Reachability warnings: inject the fake probe in PHPUnit. The current storage uses protected local `uploads/` paths, so the native probe checks the resolved file through `LocalAssetStorage`, not anonymous HTTP HEAD. Probe failure remains warning-only.

## Automated

```bash
# contracts
pnpm --filter @learn-site/contracts test -- coursePublishChecklist

# API: local development/test Compose only; rebuild after source changes
docker compose -f compose.yaml -f compose.test.yaml --profile test build api-test
make test-api
make test-fmt

# admin
pnpm --filter @learn-site/admin test -- CoursePublishChecklistDialog
pnpm --filter @learn-site/admin lint
pnpm --filter @learn-site/admin typecheck

# Rebuild running services, then use the isolated E2E project
make rebuild-all
make test-e2e
make e2e-down
```

`CoursePublishNotifyTest` must stay green: a complete draft with zero warnings still publishes and fans out without `acknowledge_warnings`.

## Manual scenarios

### V1. Preview does not publish

**Given**: Ready draft.
**When**: Open the course editor, click 发布, wait for the dialog (GET checklist). Do not confirm.
**Then**: Status stays `draft`. No `notification_dispatches` row of type `course_published`. Dialog shows catalog chapters, `hard_error_count = 0`, notification bar with `will_dispatch` and a number.

### V2. Deterministic catalog

**Given**: Ready draft, unchanged.
**When**: Close and reopen the dialog twice.
**Then**: `content_fingerprint` and the hard-error set are identical. `generated_at` may differ. `recipient_count` may differ if a learner registered in between; that is labelled as a live count.

### V3. Hard errors block and list locations

**Given**: Draft with empty intro and zero 有效课节.
**When**: Open dialog; click 确认发布 (if the button is even enabled).
**Then**: Primary is disabled. Findings include `INTRO_REQUIRED` and `NO_PUBLISHABLE_LESSON` with course/lesson scope. POST (if forced) returns 422 `PUBLISH_CHECK_FAILED` with `error.checklist`. Status unchanged. Zero course_published dispatches.

### V4. Incomplete extra lesson is a warning

**Given**: One 有效课节 plus one enabled processing-asset lesson.
**When**: Open dialog.
**Then**: `LESSON_INCOMPLETE` or `ASSET_PROCESSING` is `warning`. Checkbox required. Confirm without checkbox stays unpublished. Confirm with checkbox publishes and fans out.

### V5. Stale preview is not a token

**Given**: Dialog open on a green checklist.
**And**: Another tab archives the last 有效课节.
**When**: Confirm publish.
**Then**: 422, dialog shows the new hard error, status unchanged, zero dispatches.

### V6. Notification impact cannot be skipped

**Given**: Ready draft, `will_dispatch = true`.
**When**: Inspect the dialog.
**Then**: 在册学员 count is visible **before** the primary is usable. Entitlement count is a separate number. Already-published GET says `NOTIFICATION_SKIPPED_ALREADY_PUBLISHED` and `will_dispatch = false`.

### V7. Reachability is warning-only

**Given**: Ready draft whose video asset is `ready` but probe returns unreachable.
**When**: Open dialog and confirm with checkbox.
**Then**: `ASSET_UNREACHABLE` is warning, not hard. Publish succeeds. `is_effective` stays true for that lesson.

### V8. Map / entitlement / progress bars

**Given**: Map recovery + entitled learner + extra 有效课节 that changes the denominator.
**When**: Open dialog on unpublished → publish path.
**Then**: Maps list the published map with `will_recover_abnormal_step = true`. `entitlements.active_count >= 1`. Progress warning with `completed_preserved: true`. After confirm, learner completed lesson stays completed; order snapshot columns unchanged; entitled learner (not every 在册学员) may receive `progress_catalog_changed` if `will_recalculate`.

### V9. Idempotent already-published

**Given**: Course already published.
**When**: Open dialog and confirm.
**Then**: Still shows the inventory. No new `course_published` dispatch. No order writes.

### V10. Permission and scope

**Given**: Staff with `course.view` but not `course.publish`.
**When**: Open editor / list / preview.
**Then**: Publish button hidden. GET checklist still allowed if in scope.

**Given**: Course outside data scope.
**When**: GET checklist or POST publish.
**Then**: 403, no leak of catalog titles beyond existing scope behaviour.

### V11. Existing notify regression

**Given**: Fixtures from `CoursePublishNotifyTest`.
**When**: `publishCourse($id, $staffId)` with no body flag.
**Then**: Dispatch + inbox rows as in `010`. Re-publish does not duplicate.
