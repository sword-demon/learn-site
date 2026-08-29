---
description: "Task list for admin scheduled task management"
---

# Tasks: 管理后台自动任务管理

**Input**: Design documents from `/specs/004-admin-crontab-tasks/`

**Prerequisites**: plan.md, spec.md, research.md, data-model.md, contracts/, quickstart.md

**Design baseline**: 宪章 v1.2.0；Webman 2.2 + PHP 8.4；think-orm 唯一 ORM；复用既有 `workerman/crontab` 与 `NotificationCleanupService`；将 `notification_cleanup` 进程迁入 `ScheduledTaskRunner`。

**Tests**: plan 与 quickstart 要求 PHPUnit + Vitest 覆盖表达式校验、权限、执行日志、runner 调度；测试任务与各用户故事同阶段或紧随其后。

**Organization**: 按用户故事（US1–US5）分组；Setup + Foundational 阻塞所有故事。

## Format: `[ID] [P?] [Story] Description`

- **[P]**: 可并行（不同文件、无未完成依赖）
- **[Story]**: 用户故事标签（US1–US5）
- 描述中须含确切文件路径

## Path Conventions

- 后端：`apps/api/`
- 管理端：`apps/admin/src/`、`apps/admin/tests/`
- 契约：`packages/contracts/src/`

---

## Phase 1: Setup (Shared Infrastructure)

**Purpose**: 确认依赖与创建目录骨架（`workerman/crontab` 已由 003 安装）

- [X] T001 [P] Verify `workerman/crontab` is present in `apps/api/composer.json` and `apps/api/composer.lock` (no upgrade unless required)
- [X] T002 [P] Create handler package directories `apps/api/app/scheduled/handler/` per plan structure
- [X] T003 [P] Create admin view directory `apps/admin/src/views/scheduled-tasks/`

---

## Phase 2: Foundational (Blocking Prerequisites)

**Purpose**: 数据库、契约、表达式服务、处理器注册表、执行器、调度 runner、权限——所有用户故事的前置条件

**⚠️ CRITICAL**: 完成本阶段前不得开始用户故事实现

- [X] T004 Create migration `apps/api/database/migrations/20260829000002_scheduled_tasks.php` for `scheduled_tasks` and `scheduled_task_runs` per `specs/004-admin-crontab-tasks/data-model.md`
- [X] T005 [P] Create `apps/api/database/seeds/ScheduledTaskSeeder.php` seeding `notification.cleanup` default row (`0 30 3 * * *`, `batch_size=500`)
- [X] T006 [P] Add `scheduled_task.manage` permission to `apps/api/database/seeds/PermissionSeeder.php`
- [X] T007 [P] Create `packages/contracts/src/adminScheduledTask.ts` per `specs/004-admin-crontab-tasks/contracts/admin-scheduled-tasks.md`
- [X] T008 [P] Export scheduled-task schemas from `packages/contracts/src/index.ts`
- [X] T009 [P] Add contract tests in `packages/contracts/src/__tests__/adminScheduledTask.test.ts`
- [X] T010 Create `apps/api/app/scheduled/ScheduledTaskHandler.php` interface (`execute`, metadata for params)
- [X] T011 [P] Create `apps/api/app/scheduled/ScheduledTaskHandlerRegistry.php` registering handler codes
- [X] T012 [P] Create `apps/api/app/scheduled/handler/NotificationCleanupHandler.php` delegating to `NotificationCleanupService::purgeExpired()`
- [X] T013 Implement `apps/api/app/service/ScheduleExpressionService.php` (`isValid`, six-field enforcement, `nextRunAt`, min interval 60s via `Workerman\Crontab\Parser`)
- [X] T014 Implement `apps/api/app/service/ScheduledTaskExecutor.php` wrapping handler run, writing `scheduled_task_runs`, updating `last_run_*`, catching failures, overlap `running` guard
- [X] T015 [P] Implement `apps/api/app/service/ScheduledTaskRunService.php` for run log queries (list/show skeleton)
- [X] T016 Implement `apps/api/app/process/ScheduledTaskRunner.php` with `onWorkerStart` load, `Crontab` registration, and 30s `updated_at` poll reload via `Crontab::destroy()`
- [X] T017 Update `apps/api/config/process.php`: remove `notification_cleanup` handler; add `scheduled_tasks_runner` pointing to `ScheduledTaskRunner`
- [X] T018 Remove or deprecate `apps/api/app/process/NotificationCleanup.php` (no duplicate cleanup process)
- [X] T019 Map `/api/admin/v1/scheduled-tasks` to `scheduled_task.manage` in `apps/api/app/middleware/Authorize.php`
- [X] T020 [P] Register scheduled-task services in `apps/api/config/dependence.php` if autowire requires explicit bindings

**Checkpoint**: 迁移 + 种子可 up；契约测试通过；runner 进程启动无 `notification_cleanup` 重复；`notification.cleanup` 种子行存在

---

## Phase 3: User Story 1 - 管理员查看自动任务列表 (Priority: P1) 🎯 MVP

**Goal**: 管理后台展示全部已注册任务：名称、表达式、启用状态、最近执行摘要、下一次预计执行时间

**Independent Test**: 登录具备权限的管理员打开「自动任务」→ 列表含种子清理任务且字段完整；无权限账号被拒绝（见 spec US1 独立测试）

### Implementation for User Story 1

- [X] T021 [US1] Implement `list()` and `show()` with `next_run_at` and `handler_status` in `apps/api/app/service/ScheduledTaskService.php`
- [X] T022 [US1] Implement `index()` and `show()` in `apps/api/app/controller/admin/ScheduledTaskController.php`
- [X] T023 [US1] Register `GET /api/admin/v1/scheduled-tasks` and `GET /api/admin/v1/scheduled-tasks/{id}` in `apps/api/app/route.php`
- [X] T024 [P] [US1] Add `listScheduledTasks()` and `getScheduledTask()` in `apps/admin/src/api/scheduledTasks.ts` with Zod parsing
- [X] T025 [US1] Create list UI in `apps/admin/src/views/scheduled-tasks/ScheduledTaskListView.vue` (columns per FR-012)
- [X] T026 [US1] Add `/scheduled-tasks` route with `scheduled_task.manage` guard in `apps/admin/src/router/index.ts` and menu entry in `apps/admin/src/layouts/AdminMenu.ts`
- [X] T027 [P] [US1] Write `apps/api/tests/ScheduledTaskControllerTest.php` for list/show and `scheduled_task.manage` permission gate
- [X] T028 [P] [US1] Write `apps/admin/tests/ScheduledTaskListView.test.ts` for list rendering and empty last-run state

**Checkpoint**: US1 验收场景 1–4 可通过 API + 管理端列表验证

---

## Phase 4: User Story 2 - 管理员通过表单配置自动任务 (Priority: P1)

**Goal**: 表单编辑调度表达式、启用/停用、参数；保存前语法校验与下一次执行时间预览

**Independent Test**: 修改表达式并预览 → 非法表达式被拒绝 → 停用后不再自动调度（见 spec US2 独立测试）

### Implementation for User Story 2

- [X] T029 [US2] Implement `validateExpression()` and `update()` with audit_log in `apps/api/app/service/ScheduledTaskService.php`
- [X] T030 [US2] Implement `validateExpression()` and `update()` actions in `apps/api/app/controller/admin/ScheduledTaskController.php`
- [X] T031 [US2] Register `PATCH /api/admin/v1/scheduled-tasks/{id}` and `POST /api/admin/v1/scheduled-tasks/validate-expression` in `apps/api/app/route.php`
- [X] T032 [P] [US2] Add `validateScheduleExpression()` and `updateScheduledTask()` in `apps/admin/src/api/scheduledTasks.ts`
- [X] T033 [US2] Create `apps/admin/src/views/scheduled-tasks/ScheduledTaskEditDialog.vue` with expression input, enable switch, params form, and next-run preview
- [X] T034 [US2] Wire edit dialog open/save from `apps/admin/src/views/scheduled-tasks/ScheduledTaskListView.vue`
- [X] T035 [P] [US2] Write `apps/api/tests/ScheduledTaskExpressionTest.php` for valid/invalid expressions, six-field rule, and min-interval rejection
- [X] T036 [P] [US2] Extend `apps/api/tests/ScheduledTaskControllerTest.php` for PATCH validation errors and `HANDLER_UNAVAILABLE` on enable

**Checkpoint**: SC-001、SC-002 可度量；保存后 `updated_at` 变化可供 runner reload

---

## Phase 5: User Story 3 - 系统自动执行已启用的任务 (Priority: P1)

**Goal**: 启用任务按表达式自动触发；每次执行写入日志；失败不拖垮调度器；重叠时跳过并记录

**Independent Test**: 短周期任务观察数个周期均有 `trigger_type=schedule` 日志；停用后无新自动记录（见 spec US3 独立测试）

### Implementation for User Story 3

- [X] T037 [US3] Complete overlap skip (`status=skipped`) and schedule trigger path in `apps/api/app/service/ScheduledTaskExecutor.php`
- [X] T038 [US3] Wire schedule callback in `apps/api/app/process/ScheduledTaskRunner.php` to `ScheduledTaskExecutor` with `trigger_type=schedule`
- [X] T039 [US3] Ensure disabled tasks are not registered as `Crontab` instances after reload in `apps/api/app/process/ScheduledTaskRunner.php`
- [X] T040 [P] [US3] Write `apps/api/tests/ScheduledTaskRunnerTest.php` for auto execution log creation, failure recovery, and skip-on-overlap behavior

**Checkpoint**: SC-003 可在测试环境用 `0 */1 * * * *` 验证；US2 停用验收需等待 ≤30s reload

---

## Phase 6: User Story 4 - 管理员查询自动任务执行日志 (Priority: P1)

**Goal**: 执行日志列表与详情；按任务、结果、触发方式、时间筛选分页

**Independent Test**: 多次执行后按任务/失败筛选，分页与详情正确（见 spec US4 独立测试）

### Implementation for User Story 4

- [X] T041 [US4] Implement filtered `list()` and `show()` in `apps/api/app/service/ScheduledTaskRunService.php`
- [X] T042 [US4] Implement `runsIndex()` and `runsShow()` in `apps/api/app/controller/admin/ScheduledTaskController.php`
- [X] T043 [US4] Register `GET /api/admin/v1/scheduled-tasks/runs` and `GET /api/admin/v1/scheduled-tasks/runs/{id}` in `apps/api/app/route.php`
- [X] T044 [P] [US4] Add `listScheduledTaskRuns()` and `getScheduledTaskRun()` in `apps/admin/src/api/scheduledTasks.ts`
- [X] T045 [US4] Create `apps/admin/src/views/scheduled-tasks/ScheduledTaskRunLogView.vue` with filters and detail drawer
- [X] T046 [US4] Add `/scheduled-tasks/runs` route (or tab) in `apps/admin/src/router/index.ts` and link from `ScheduledTaskListView.vue`
- [X] T047 [P] [US4] Write `apps/admin/tests/ScheduledTaskRunLogView.test.ts` for filter UI and empty state

**Checkpoint**: US4 验收场景 1–5 可通过；SC-004 列表首屏可体感验证

---

## Phase 7: User Story 5 - 管理员手动触发任务执行 (Priority: P2)

**Goal**: 「立即运行一次」；日志标记 `trigger_type=manual`；执行中拒绝重复触发；不改变调度配置

**Independent Test**: 手动触发产生 manual 日志；表达式与启用状态不变；无权限拒绝（见 spec US5 独立测试）

### Implementation for User Story 5

- [X] T048 [US5] Implement `runNow()` with `audit_log` (`scheduled_task.run`) in `apps/api/app/service/ScheduledTaskService.php` calling `ScheduledTaskExecutor`
- [X] T049 [US5] Implement `run()` action in `apps/api/app/controller/admin/ScheduledTaskController.php` returning run summary (`409 TASK_ALREADY_RUNNING` when busy)
- [X] T050 [US5] Register `POST /api/admin/v1/scheduled-tasks/{id}/run` in `apps/api/app/route.php`
- [X] T051 [P] [US5] Add `runScheduledTask()` in `apps/admin/src/api/scheduledTasks.ts`
- [X] T052 [US5] Add「立即执行」action and loading state in `apps/admin/src/views/scheduled-tasks/ScheduledTaskListView.vue`
- [X] T053 [P] [US5] Extend `apps/api/tests/ScheduledTaskControllerTest.php` for manual trigger, `actor_staff_id`, and concurrent-run rejection

**Checkpoint**: SC-006 可度量；手动与自动重叠时各自独立日志（规格假设）

---

## Phase 8: Polish & Cross-Cutting Concerns

**Purpose**: 权限文案、安全隔离、迁移门禁与 quickstart 端到端验证

- [X] T054 [P] Add `scheduled_task.manage` label in `apps/api/app/controller/admin/RoleController.php` permission map
- [X] T055 [P] Extend `apps/api/tests/AuthorizeLeakTest.php` for scheduled-task admin routes and learner-token denial
- [X] T056 [P] Extend `apps/api/tests/MigrationReleaseGateTest.php` for `20260829000002_scheduled_tasks.php`
- [X] T057 Run validation scenarios in `specs/004-admin-crontab-tasks/quickstart.md` and fix any gaps found
- [X] T058 [P] Add scheduled-task list smoke step to `apps/admin/tests/e2e/smoke.spec.ts` if e2e suite exists

---

## Dependencies & Execution Order

### Phase Dependencies

- **Setup (Phase 1)**: 无依赖，可立即开始
- **Foundational (Phase 2)**: 依赖 Setup — **阻塞所有用户故事**
- **US1 (Phase 3)**: 依赖 Foundational — **MVP 起点**
- **US2 (Phase 4)**: 依赖 Foundational；与 US1 共享 list view / service
- **US3 (Phase 5)**: 依赖 Foundational（executor + runner）；验收可与 US2 停用联动
- **US4 (Phase 6)**: 依赖 Foundational（run 表 + executor 写入）；验收需有 run 数据（US3/US5 或测试插入）
- **US5 (Phase 7)**: 依赖 Foundational（executor）；与 US4 共享 run 日志
- **Polish (Phase 8)**: 依赖所有目标用户故事完成

### User Story Dependencies

```text
Setup → Foundational
              ├─→ US1 (列表) ──→ US2 (表单配置)
              ├─→ US3 (自动执行，Foundational 内 runner 为主)
              ├─→ US4 (日志查询，需 run 数据)
              └─→ US5 (手动触发，需 executor)
```

| 故事 | 依赖 | 可独立测试 |
|------|------|------------|
| US1 | Foundational | 是 — 种子任务列表 + 权限门 |
| US2 | Foundational + US1 UI 集成 | 是 — API 校验/PATCH 可独立测 |
| US3 | Foundational | 是 — runner 测试 + 短周期观察 |
| US4 | Foundational + 建议有 run 数据 | 是 — 空列表空态亦有效 |
| US5 | Foundational | 是 — 手动触发 API + 日志 |

### Within Each User Story

- Service 层先于 Controller
- Controller 先于路由注册
- API 客户端与后端路由同步
- 管理端 UI 在对应 API 就绪后接入
- 测试在实现后或紧随其后

### Parallel Opportunities

- Phase 1: T001–T003 全部 [P]
- Phase 2: T005–T009、T011–T012、T015、T020 可与 T004 之后并行
- US1: T024、T027、T028 与 T025 并行（不同文件）
- US2: T032、T035、T036 并行
- US3: T040 与 T037–T039 实现后并行
- US4: T044、T047 并行
- US5: T051、T053 并行
- Polish: T054–T056、T058 全部 [P]

---

## Parallel Example: User Story 1

```bash
# T021–T023 完成后并行:
Task T024: apps/admin/src/api/scheduledTasks.ts
Task T027: apps/api/tests/ScheduledTaskControllerTest.php
Task T028: apps/admin/tests/ScheduledTaskListView.test.ts
```

---

## Parallel Example: Foundational

```bash
# T004 迁移落地后并行:
Task T005: ScheduledTaskSeeder.php
Task T006: PermissionSeeder.php
Task T007: adminScheduledTask.ts
Task T009: adminScheduledTask.test.ts
Task T011: ScheduledTaskHandlerRegistry.php
Task T012: NotificationCleanupHandler.php
```

---

## Implementation Strategy

### MVP First (User Story 1)

1. Complete Phase 1: Setup
2. Complete Phase 2: Foundational
3. Complete Phase 3: User Story 1（任务列表 + 权限门）
4. **STOP and VALIDATE**: `ScheduledTaskControllerTest` + 管理端列表见种子清理任务
5. 可演示后再加 US2（配置）→ US3（自动跑）→ US4（日志）→ US5（手动）

### Incremental Delivery

1. Setup + Foundational → 调度基础设施与数据表就绪
2. US1 列表 → 运维可见任务状态
3. US2 表单 → 可改表达式与启用
4. US3 自动执行 → 定时可靠运行
5. US4 日志 → 可排障
6. US5 手动触发 → 运维验证便利
7. Polish → 安全、文档、E2E

### Parallel Team Strategy

| 开发者 | 任务流 |
|--------|--------|
| A | Foundational → US1 → US2 |
| B | Foundational 完成后 → US3 runner 测试 |
| C | Foundational 完成后 → US4 日志 UI |
| D | US5 手动触发（Foundational 后） |

---

## Notes

- 首版不开放 `POST` 创建任务；仅种子 + 代码注册 handler
- 移除 `notification_cleanup` 进程后不得与 `scheduled_tasks_runner` 双跑清理
- 配置变更通过 DB `updated_at` 轮询，≤30s 生效；非 Redis pub/sub
- 六段式 cron 强制；最短间隔 60 秒
- `[P]` 任务避免同文件冲突；`ScheduledTaskListView.vue` 在 US1/US2/US5 顺序修改
- 每个 Checkpoint 可独立提交；全量门禁见 `specs/004-admin-crontab-tasks/quickstart.md`
