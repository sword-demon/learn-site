---
description: "Task list for learner daily check-in and daily plan"
---

# Tasks: 学员每日签到与每日计划

**Input**: Design documents from `/specs/005-learner-daily-checkin/`

**Prerequisites**: plan.md, spec.md, research.md, data-model.md, contracts/, quickstart.md

**Design baseline**: 宪章 v1.2.0；Webman 2.2 + PHP 8.4；think-orm 唯一 ORM；复用 `HtmlSanitizer`；学习端新增精简 WangEditor；权限码 `checkin.manage`。

**Tests**: plan 与 quickstart 要求 PHPUnit + Vitest 覆盖签到、重复拒绝、跨学员隔离、删除后重签、弹窗逻辑；测试任务与各用户故事同阶段或紧随其后。

**Organization**: 按用户故事（US1–US4）分组；Setup + Foundational 阻塞所有故事。

## Format: `[ID] [P?] [Story] Description`

- **[P]**: 可并行（不同文件、无未完成依赖）
- **[Story]**: 用户故事标签（US1–US4）
- 描述中须含确切文件路径

## Path Conventions

- 后端：`apps/api/`
- 管理端：`apps/admin/src/`、`apps/admin/tests/`
- 学习端：`apps/web/src/`、`apps/web/tests/`
- 契约：`packages/contracts/src/`

---

## Phase 1: Setup (Shared Infrastructure)

**Purpose**: 学习端富文本编辑器依赖与版本对齐

- [X] T001 Add `@wangeditor/editor` and `@wangeditor/editor-for-vue` to `apps/web/package.json` (match `apps/admin/package.json` versions `^5.1.23` / `^5.1.12`) and update `pnpm-lock.yaml`

---

## Phase 2: Foundational (Blocking Prerequisites)

**Purpose**: 数据库、契约、核心服务、权限——所有用户故事的前置条件

**⚠️ CRITICAL**: 完成本阶段前不得开始用户故事实现

- [X] T002 Create migration `apps/api/database/migrations/20260830000001_learner_daily_checkins.php` for `learner_daily_checkins` with `UNIQUE (learner_id, checkin_date)` per `specs/005-learner-daily-checkin/data-model.md`
- [X] T003 [P] Add `checkin.manage` permission to `apps/api/database/seeds/PermissionSeeder.php`
- [X] T004 [P] Create `packages/contracts/src/dailyCheckin.ts` with `LearnerCheckinDTO`, `LearnerTodayCheckinDTO`, `CreateCheckinInput`, `AdminCheckinListItemDTO`, `AdminCheckinDetailDTO`, and list envelopes per `specs/005-learner-daily-checkin/contracts/`
- [X] T005 Export daily check-in schemas from `packages/contracts/src/index.ts`
- [X] T006 [P] Add contract tests in `packages/contracts/src/__tests__/dailyCheckin.test.ts`
- [X] T007 Implement `apps/api/app/service/CheckinService.php` with `create()`, `getTodayStatus()`, `listForLearner()`, `getForLearner()`, `listForAdmin()`, `getForAdmin()`, `deleteForAdmin()` — including `HtmlSanitizer`净化、空壳校验、Asia/Shanghai 自然日、`audit_log`（`checkin.create` / `checkin.delete`）
- [X] T008 Register `CheckinService` in `apps/api/config/dependence.php`
- [X] T009 Map admin check-in routes to `checkin.manage` in `apps/api/app/middleware/Authorize.php`

**Checkpoint**: 迁移可 up；契约测试通过；`CheckinService` 单元/集成可经后续 API 测试验证；无 `checkin.manage` 时管理端路由返回 403

---

## Phase 3: User Story 1 - 学员完成当日签到并填写每日计划 (Priority: P1) 🎯 MVP

**Goal**: 已登录学员提交富文本每日计划完成当日签到；每自然日限签一次；空计划与重复签到被拒绝

**Independent Test**: 学员当日首次 `POST /checkins` 成功；再次提交返回 `409 ALREADY_CHECKED_IN`；净化后富文本可存储（见 spec US1 独立测试）

### Implementation for User Story 1

- [X] T010 [US1] Implement `store()` in `apps/api/app/controller/learner/CheckinController.php` delegating to `CheckinService::create()`
- [X] T011 [US1] Register `POST /api/learner/v1/checkins` under `LearnerAuth` in `apps/api/app/route.php`
- [X] T012 [P] [US1] Create slim `CheckinPlanEditor.vue` (bold/italic/lists/links, no image upload) in `apps/web/src/components/CheckinPlanEditor.vue` based on `apps/admin/src/components/course/ContentEditor.vue`
- [X] T013 [US1] Create `DailyCheckinDialog.vue` with `CheckinPlanEditor`, submit, and validation feedback in `apps/web/src/components/DailyCheckinDialog.vue`
- [X] T014 [P] [US1] Add `createCheckin()` with Zod parsing in `apps/web/src/api/checkins.ts`
- [X] T015 [P] [US1] Write `apps/api/tests/DailyCheckinTest.php` covering successful create, duplicate-day rejection, empty plan rejection, and script-tag sanitization

**Checkpoint**: US1 验收场景 1–5 可通过 API 验证；弹窗 UI 可在 US2 完整串联

---

## Phase 4: User Story 2 - 进入学习端时自动提示未签到学员 (Priority: P1)

**Goal**: 已登录学员进入学习端时查询今日签到状态；未签到则弹窗；同会话关闭后不重复；已签到不弹

**Independent Test**: 未签到学员打开学习端见弹窗；关闭后同会话不再弹；新会话再弹；已签到学员不弹（见 spec US2 独立测试）

### Implementation for User Story 2

- [X] T016 [US2] Implement `today()` in `apps/api/app/controller/learner/CheckinController.php` returning `server_date`, `checked_in`, and optional `record`
- [X] T017 [US2] Register `GET /api/learner/v1/checkins/today` under `LearnerAuth` in `apps/api/app/route.php`
- [X] T018 [P] [US2] Add `fetchTodayCheckinStatus()` in `apps/web/src/api/checkins.ts`
- [X] T019 [US2] Create `useDailyCheckinPrompt.ts` composable with `sessionStorage` dismiss key `checkin_dismissed_{server_date}` in `apps/web/src/composables/useDailyCheckinPrompt.ts`
- [X] T020 [US2] Mount `useDailyCheckinPrompt` and `DailyCheckinDialog` in `apps/web/src/layouts/LearnerLayout.vue` when `session.loggedIn`
- [X] T021 [P] [US2] Write `apps/web/tests/DailyCheckinDialog.test.ts` for show/hide on `checked_in`, dismiss without check-in, and success close

**Checkpoint**: US2 验收场景 1–5 可通过；SC-001 弹窗 3 秒内展示可人工验证

---

## Phase 5: User Story 3 - 学员在签到列表页查看自己的签到历史 (Priority: P1)

**Goal**: 独立「每日签到」分页列表；仅展示当前学员记录；倒序分页；空态引导

**Independent Test**: 预置 15 条记录验证分页与倒序；另一学员仅见自己的记录（见 spec US3 独立测试）

### Implementation for User Story 3

- [X] T022 [US3] Implement `index()` and `show()` in `apps/api/app/controller/learner/CheckinController.php` with learner-scoped queries
- [X] T023 [US3] Register `GET /api/learner/v1/checkins` and `GET /api/learner/v1/checkins/{id}` under `LearnerAuth` in `apps/api/app/route.php`
- [X] T024 [P] [US3] Add `listCheckins()` and `getCheckin()` in `apps/web/src/api/checkins.ts`
- [X] T025 [US3] Create paginated list with rich-text display in `apps/web/src/views/me/CheckinListView.vue` using `MarkdownRenderer.vue` and `hasRichHtml()` from `apps/web/src/utils/richHtml.ts`
- [X] T026 [US3] Add `/me/checkins` route with `requireLearnerAuth` in `apps/web/src/router/index.ts` and「每日签到」nav link in `apps/web/src/layouts/LearnerLayout.vue`
- [X] T027 [P] [US3] Write `apps/web/tests/CheckinListView.test.ts` for list rendering, empty state, and pagination controls
- [X] T028 [P] [US3] Extend `apps/api/tests/DailyCheckinTest.php` for learner list pagination order and cross-learner `404` isolation

**Checkpoint**: US3 验收场景 1–5 通过；SC-004 列表首屏 ≤2 秒可人工验证

---

## Phase 6: User Story 4 - 管理员查询与删除学员签到记录 (Priority: P1)

**Goal**: 管理端全部学员签到列表；按学员与日期筛选；详情查看完整计划；删除后学员可重签

**Independent Test**: 管理员筛选列表、查看详情、删除记录；被删学员 `GET /checkins/today` 返回 `checked_in: false` 并可重签（见 spec US4 独立测试）

### Implementation for User Story 4

- [X] T029 [US4] Implement `index()`, `show()`, and `destroy()` in `apps/api/app/controller/admin/CheckinController.php`
- [X] T030 [US4] Register `GET /api/admin/v1/checkins`, `GET /api/admin/v1/checkins/{id}`, and `DELETE /api/admin/v1/checkins/{id}` in `apps/api/app/route.php`
- [X] T031 [P] [US4] Add `listCheckins()`, `getCheckin()`, and `deleteCheckin()` in `apps/admin/src/api/checkins.ts`
- [X] T032 [US4] Create list with learner/date filters, detail drawer, and delete confirm in `apps/admin/src/views/checkins/CheckinListView.vue`
- [X] T033 [US4] Add `/checkins` route with `checkin.manage` guard in `apps/admin/src/router/index.ts` and「签到管理」menu entry in `apps/admin/src/layouts/AdminMenu.ts`
- [X] T034 [P] [US4] Extend `apps/api/tests/DailyCheckinTest.php` for admin list filters, `checkin.manage` gate, delete audit log, and post-delete re-checkin
- [X] T035 [P] [US4] Write `apps/admin/tests/CheckinListView.test.ts` for list rendering, filter controls, and delete confirmation dialog

**Checkpoint**: US4 验收场景 1–6 通过；SC-005/SC-006 可度量

---

## Phase 7: Polish & Cross-Cutting Concerns

**Purpose**: 权限文案、安全泄漏测试、迁移门禁与端到端冒烟

- [X] T036 [P] Add `checkin.manage` label in `apps/api/app/controller/admin/RoleController.php` permission map
- [X] T037 [P] Extend `apps/api/tests/AuthorizeLeakTest.php` for admin check-in routes and learner check-in routes cross-token isolation
- [X] T038 [P] Extend `apps/api/tests/MigrationReleaseGateTest.php` for `20260830000001_learner_daily_checkins.php`
- [X] T039 Run validation scenarios in `specs/005-learner-daily-checkin/quickstart.md` and fix any gaps found

---

## Dependencies & Execution Order

### Phase Dependencies

- **Setup (Phase 1)**: 无依赖，可立即开始
- **Foundational (Phase 2)**: 依赖 Setup（T001 WangEditor）— **阻塞所有用户故事**
- **US1 (Phase 3)**: 依赖 Foundational — **MVP 起点**
- **US2 (Phase 4)**: 依赖 Foundational + US1（`POST /checkins` 与 `DailyCheckinDialog` 提交）
- **US3 (Phase 5)**: 依赖 Foundational；验收建议有 US1 种子数据
- **US4 (Phase 6)**: 依赖 Foundational；可与 US2/US3 并行；验收建议有 US1 种子数据
- **Polish (Phase 7)**: 依赖所有目标用户故事完成

### User Story Dependencies

```text
Setup → Foundational
              ├─→ US1 (签到提交) ──→ US2 (弹窗，依赖 Dialog + POST)
              ├─→ US3 (学员列表，验收建议有 US1 数据)
              └─→ US4 (管理端，可与 US2/US3 并行)
```

| 故事 | 依赖 | 可独立测试 |
|------|------|------------|
| US1 | Foundational | 是 — API `POST /checkins` + 重复拒绝 |
| US2 | Foundational + US1 | 是 — `GET /today` + 弹窗行为（提交依赖 US1 API） |
| US3 | Foundational | 是 — 空列表空态亦有效 |
| US4 | Foundational | 是 — 管理端空列表；删除重签需 US1 数据 |

### Within Each User Story

- `CheckinService` 先于 Controller（Foundational 已完成）
- Controller 先于路由注册
- API 客户端与后端路由同步
- 学习端/管理端 UI 在对应 API 就绪后接入
- 测试在实现后或紧随其后

### Parallel Opportunities

- Phase 1: 单任务
- Phase 2: T003–T006 可与 T002 之后并行；T007–T009 顺序在 T002 之后
- US1: T012、T014、T015 与 T013 并行（不同文件）
- US2: T018、T021 与 T019–T020 部分并行
- US3: T024、T027、T028 并行
- US4: T031、T034、T035 并行
- Polish: T036–T038 全部 [P]

---

## Parallel Example: User Story 1

```bash
# T010–T011 后端就绪后并行:
Task T012: apps/web/src/components/CheckinPlanEditor.vue
Task T014: apps/web/src/api/checkins.ts
Task T015: apps/api/tests/DailyCheckinTest.php

# 然后串联:
Task T013: apps/web/src/components/DailyCheckinDialog.vue
```

---

## Parallel Example: Foundational

```bash
# T002 迁移落地后并行:
Task T003: PermissionSeeder.php
Task T004: dailyCheckin.ts
Task T006: dailyCheckin.test.ts

# 然后:
Task T007: CheckinService.php
Task T008–T009: dependence.php + Authorize.php
```

---

## Implementation Strategy

### MVP First (User Story 1)

1. Complete Phase 1: Setup
2. Complete Phase 2: Foundational
3. Complete Phase 3: User Story 1（`POST /checkins` + 编辑器组件）
4. **STOP and VALIDATE**: `DailyCheckinTest` 通过；curl 可完成签到与重复拒绝
5. 再加 US2 弹窗完整体验

### Incremental Delivery

1. Setup + Foundational → 数据与服务基础就绪
2. US1 签到提交 → 核心闭环
3. US2 进入弹窗 → 日常触达
4. US3 学员列表 → 历史回顾
5. US4 管理端 → 运营监督与删除
6. Polish → 安全、迁移门禁、quickstart 全量验证

### Parallel Team Strategy

| 开发者 | 任务流 |
|--------|--------|
| A | Foundational → US1 → US2 |
| B | Foundational 完成后 → US3 学员列表 |
| C | Foundational 完成后 → US4 管理端 |

---

## Notes

- 自然日由服务端 `Asia/Shanghai` 判定；`server_date` 用于客户端 `sessionStorage` key
- 学员列表仅展示本人记录；管理端可见全部
- 删除为物理删除；删除后该日可重签并再次触发弹窗
- `[P]` 任务避免同文件冲突；`DailyCheckinDialog.vue` 在 US1/US2 顺序修改
- 每个 Checkpoint 可独立提交；全量门禁见 `specs/005-learner-daily-checkin/quickstart.md`
