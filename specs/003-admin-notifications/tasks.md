---
description: "Task list for admin notifications and learner message center"
---

# Tasks: 管理后台通知与学员消息中心

**Input**: Design documents from `/specs/003-admin-notifications/`

**Prerequisites**: plan.md, spec.md, research.md, data-model.md, contracts/, quickstart.md

**Design baseline**: 宪章 v1.2.0；Webman 2.2 + PHP 8.4；think-orm 唯一 ORM；新增 `webman/push`、`workerman/crontab`；复用既有 `learner_notifications` / `MessageService` / `MessagesView`。

**Tests**: plan 与 quickstart 要求 PHPUnit + Vitest 覆盖发送、隔离、推送鉴权、清理边界；测试任务与各用户故事同阶段或紧随其后。

**Organization**: 按用户故事（US1–US5）分组；Setup + Foundational 阻塞所有故事。

## Format: `[ID] [P?] [Story] Description`

- **[P]**: 可并行（不同文件、无未完成依赖）
- **[Story]**: 用户故事标签（US1–US5）
- 描述中须含确切文件路径

## Path Conventions

- 后端：`apps/api/`
- 管理端：`apps/admin/src/`、`apps/admin/tests/`
- 学习端：`apps/web/src/`、`apps/web/tests/`
- 契约：`packages/contracts/src/`

---

## Phase 1: Setup (Shared Infrastructure)

**Purpose**: 安装推送与定时任务依赖，配置容器与前端环境变量

- [X] T001 Install `webman/push` and `workerman/crontab` in `apps/api/composer.json` and update `apps/api/composer.lock` via `composer update`
- [X] T002 [P] Expose push WebSocket `3131` and HTTP API `3232` on the `api` service in `compose.yaml`
- [X] T003 [P] Add `PUSH_APP_KEY`, `PUSH_APP_SECRET`, `VITE_PUSH_URL`, and `VITE_PUSH_APP_KEY` to `.env.example`
- [X] T004 [P] Add WebSocket `wss` proxy location for `/app/` to `api:3131` in `docker/web/nginx.conf`
- [X] T005 [P] Declare `VITE_PUSH_URL` and `VITE_PUSH_APP_KEY` in `apps/web/src/env.d.ts`

---

## Phase 2: Foundational (Blocking Prerequisites)

**Purpose**: 数据库、契约、推送基础设施、权限与系统通知 push 挂钩——所有用户故事的前置条件

**⚠️ CRITICAL**: 完成本阶段前不得开始用户故事实现

- [X] T006 Create migration `apps/api/database/migrations/20260829000001_notification_dispatches.php` for `notification_dispatches`, `notification_dispatch_recipients`, and `learner_notifications` extensions (`kind` VARCHAR(32), `dispatch_id`)
- [X] T007 [P] Add `notification.manage` permission to `apps/api/database/seeds/PermissionSeeder.php`
- [X] T008 [P] Create `packages/contracts/src/adminNotification.ts` per `specs/003-admin-notifications/contracts/admin-notifications.md`
- [X] T009 [P] Extend `packages/contracts/src/notification.ts` with `announcement`/`internal_message` kinds, `dispatch_id`, and `LearnerUnreadCountDTO`
- [X] T010 Export new notification schemas from `packages/contracts/src/index.ts`
- [X] T011 [P] Add contract tests in `packages/contracts/src/__tests__/notification.test.ts` and `packages/contracts/src/__tests__/adminNotification.test.ts`
- [X] T012 Implement `apps/api/app/service/PushNotificationService.php` wrapping `Webman\Push\Api` and `private-learner-{id}` channel trigger
- [X] T013 Configure `webman/push` plugin defaults in `apps/api/config/plugin/webman/push/app.php` and register push worker in `apps/api/config/plugin/webman/push/process.php`
- [X] T014 Customize learner private-channel auth in `apps/api/config/plugin/webman/push/route.php` using `TokenService` and `account_id` channel match
- [X] T015 Register `PushNotificationService` and `NotificationDispatchService` in `apps/api/config/dependence.php`
- [X] T016 Map admin notification routes to `notification.manage` in `apps/api/app/middleware/Authorize.php`
- [X] T017 Extend `apps/api/app/service/MessageService.php` `emit()` to call `PushNotificationService` after inbox insert

**Checkpoint**: 迁移可 up；契约测试通过；系统事件（问答/进度/授权）写入后可触发 push；无 `notification.manage` 时管理端路由返回 403

---

## Phase 3: User Story 1 - 管理员发送公告 (Priority: P1) 🎯 MVP

**Goal**: 管理员向全体在册学员广播公告；创建发送记录并 fan-out 到学员收件箱；在线学员收到 push

**Independent Test**: 管理员发送公告 → 两名学员（一在线一离线）均在消息列表看到未读公告；后台 API 返回 `recipient_count`（见 spec US1 独立测试）

### Implementation for User Story 1

- [X] T018 [US1] Implement `sendAnnouncement()` with chunked fan-out, `idempotency_key`, push trigger, and `audit_log` in `apps/api/app/service/NotificationDispatchService.php`
- [X] T019 [US1] Implement `storeAnnouncement()` in `apps/api/app/controller/admin/NotificationController.php`
- [X] T020 [US1] Register `POST /api/admin/v1/notifications/announcements` in `apps/api/app/route.php`
- [X] T021 [P] [US1] Add `sendAnnouncement()` with Zod parsing in `apps/admin/src/api/notifications.ts`
- [X] T022 [US1] Add announcement compose form (title/body/submit) in `apps/admin/src/views/notifications/NotificationComposeDialog.vue`
- [X] T023 [P] [US1] Write `apps/api/tests/NotificationDispatchTest.php` covering announcement fan-out, `recipient_count`, and `notification.manage` permission gate

**Checkpoint**: US1 验收场景 1–3 可通过 API + 学习端既有 `MessagesView` 验证（kind 标签完整样式在 US4）

---

## Phase 4: User Story 2 - 管理员发送站内信 (Priority: P1)

**Goal**: 管理员向指定学员发送站内信；仅收件人收件箱可见；写入 `notification_dispatch_recipients`

**Independent Test**: 向学员甲、乙发送站内信 → 仅甲、乙列表可见；丙不可见（见 spec US2 独立测试）

### Implementation for User Story 2

- [X] T024 [US2] Implement `sendInternalMessage()` with recipient validation and `notification_dispatch_recipients` insert in `apps/api/app/service/NotificationDispatchService.php`
- [X] T025 [US2] Implement `storeInternalMessage()` in `apps/api/app/controller/admin/NotificationController.php`
- [X] T026 [US2] Register `POST /api/admin/v1/notifications/internal-messages` in `apps/api/app/route.php`
- [X] T027 [P] [US2] Add learner multi-select and `sendInternalMessage()` in `apps/admin/src/views/notifications/NotificationComposeDialog.vue` and `apps/admin/src/api/notifications.ts`
- [X] T028 [P] [US2] Extend `apps/api/tests/NotificationDispatchTest.php` for recipient isolation, empty `learner_ids` rejection, and inactive learner filtering

**Checkpoint**: US2 验收场景 1–4 可通过 API 验证；非收件人泄露率为 0%（SC-004）

---

## Phase 5: User Story 3 - 管理员查询已发送消息 (Priority: P1)

**Goal**: 后台通知列表与详情；按类型、时间筛选分页；展示目标范围摘要

**Independent Test**: 发送 1 公告 + 2 站内信后，列表筛选与分页结果正确（见 spec US3 独立测试）

### Implementation for User Story 3

- [X] T029 [US3] Implement `list()` and `show()` with filters in `apps/api/app/service/NotificationDispatchService.php`
- [X] T030 [US3] Implement `index()` and `show()` in `apps/api/app/controller/admin/NotificationController.php`
- [X] T031 [US3] Register `GET /api/admin/v1/notifications` and `GET /api/admin/v1/notifications/{id}` in `apps/api/app/route.php`
- [X] T032 [P] [US3] Add `listNotifications()` and `getNotification()` in `apps/admin/src/api/notifications.ts`
- [X] T033 [US3] Create list/detail UI with type and date filters in `apps/admin/src/views/notifications/NotificationListView.vue`
- [X] T034 [US3] Add `/notifications` route with `notification.manage` guard in `apps/admin/src/router/index.ts` and menu entry in `apps/admin/src/layouts/AdminMenu.ts`
- [X] T035 [P] [US3] Write `apps/admin/tests/NotificationListView.test.ts` for list rendering, filters, and compose dialog open

**Checkpoint**: US1 独立测试中「后台列表可查发送记录」在此阶段完整可用

---

## Phase 6: User Story 4 - 学员消息中心与实时推送 (Priority: P1)

**Goal**: 消息列表含全部类型；未读角标；标记已读；在线 push 更新列表/计数；REST 兜底

**Independent Test**: 同一学员依次收到系统通知、公告、站内信 → 未读样式正确 → 标记已读角标减 1 → 在线 5 秒内 push 更新（见 spec US4 独立测试）

### Implementation for User Story 4

- [X] T036 [US4] Add `unreadCount()` and extend list `kind`/`dispatch_id` mapping in `apps/api/app/controller/learner/NotificationController.php`
- [X] T037 [US4] Register `GET /api/learner/v1/messages/unread-count` in `apps/api/app/route.php`
- [X] T038 [P] [US4] Add `fetchUnreadCount()` in `apps/web/src/api/notifications.ts`
- [X] T039 [US4] Extend `kindLabel()` for `announcement` and `internal_message` in `apps/web/src/views/me/MessagesView.vue`
- [X] T040 [P] [US4] Add push client wrapper in `apps/web/src/utils/push.ts` based on webman `push-vue.js` pattern
- [X] T041 [US4] Create `apps/web/src/composables/usePushNotifications.ts` to subscribe `private-learner-{id}` and refresh unread count / message list
- [X] T042 [US4] Show unread badge on messages nav link in `apps/web/src/layouts/LearnerLayout.vue` using `usePushNotifications` and logout teardown
- [X] T043 [P] [US4] Write `apps/api/tests/NotificationPushAuthTest.php` for private channel auth and cross-learner subscription denial
- [X] T044 [P] [US4] Extend `apps/api/tests/NotificationContractTest.php` and `apps/web/tests/MessagesView.test.ts` for new kinds, unread count, and mark-read idempotency

**Checkpoint**: US4 验收场景 1–5 可通过；SC-002/SC-003/SC-005 可度量

---

## Phase 7: User Story 5 - 系统自动清理过期消息 (Priority: P2)

**Goal**: 每日定时分批删除 2 个月前 `learner_notifications`；写审计；不影响未过期消息

**Independent Test**: 预置 61 天前与 59 天前记录 → 清理后仅超期删除（见 spec US5 独立测试）

### Implementation for User Story 5

- [X] T045 [US5] Implement `purgeExpired()` with batch `DELETE` and `audit_log` in `apps/api/app/service/NotificationCleanupService.php`
- [X] T046 [US5] Create `apps/api/app/process/NotificationCleanup.php` with `Workerman\Crontab\Crontab` schedule `0 30 3 * * *`
- [X] T047 [US5] Register `notification_cleanup` process handler in `apps/api/config/process.php`
- [X] T048 [P] [US5] Write `apps/api/tests/NotificationCleanupTest.php` for 61-day deletion and 59-day retention boundaries

**Checkpoint**: US5 验收场景 1–3 通过；`notification_dispatches` 不被清理（后台记录保留）

---

## Phase 8: Polish & Cross-Cutting Concerns

**Purpose**: 权限文案、契约文档、安全泄漏测试、迁移门禁与端到端冒烟

- [X] T049 [P] Add `notification.manage` label in `apps/api/app/controller/admin/RoleController.php` permission map
- [X] T050 [P] Document notification endpoints in `specs/001-personal-learning-site/contracts/admin-api.md` and `specs/001-personal-learning-site/contracts/learner-api.md`
- [X] T051 [P] Extend `apps/api/tests/AuthorizeLeakTest.php` for admin notification routes and learner message routes cross-token isolation
- [X] T052 [P] Extend `apps/api/tests/MigrationReleaseGateTest.php` for `20260829000001_notification_dispatches.php`
- [X] T053 Run validation scenarios in `specs/003-admin-notifications/quickstart.md` and fix any gaps found
- [X] T054 [P] Add notification list/send smoke step to `apps/admin/tests/e2e/smoke.spec.ts` and unread badge step to `apps/web/tests/e2e/smoke.spec.ts`

---

## Dependencies & Execution Order

### Phase Dependencies

- **Setup (Phase 1)**: 无依赖，可立即开始
- **Foundational (Phase 2)**: 依赖 Setup（T001 插件安装）— **阻塞所有用户故事**
- **US1 (Phase 3)**: 依赖 Foundational — **MVP 起点**
- **US2 (Phase 4)**: 依赖 Foundational；与 US1 共享 `NotificationDispatchService` / compose UI
- **US3 (Phase 5)**: 依赖 Foundational；列表数据依赖 US1/US2 已有发送记录（验收时）
- **US4 (Phase 6)**: 依赖 Foundational（T017 push hook）；与 US1/US2 并行开发，完整验收需有发送数据
- **US5 (Phase 7)**: 依赖 Foundational（迁移）；可与 US4 并行
- **Polish (Phase 8)**: 依赖所有目标用户故事完成

### User Story Dependencies

```text
Setup → Foundational
              ├─→ US1 (公告) ──┐
              ├─→ US2 (站内信) ─┼─→ US3 (后台查询，验收需 US1+US2 数据)
              ├─→ US4 (学员中心+push，验收需 US1+US2 数据)
              └─→ US5 (清理，可独立)
```

| 故事 | 依赖 | 可独立测试 |
|------|------|------------|
| US1 | Foundational | 是 — API 发公告 + 学员列表见未读行 |
| US2 | Foundational | 是 — API 发站内信 + 收件人隔离 |
| US3 | Foundational + 建议有 US1/US2 种子数据 | 是 — 空列表空态亦有效 |
| US4 | Foundational | 部分 — push/角标需登录；全类型需 US1/US2 |
| US5 | Foundational | 是 — 测试库预置过期行 |

### Within Each User Story

- Service 层先于 Controller
- Controller 先于路由注册
- API 客户端与后端路由同步
- 管理端/学习端 UI 在对应 API 就绪后接入
- 测试在实现后或通过红绿循环紧跟故事

### Parallel Opportunities

- Phase 1: T002–T005 全部 [P]
- Phase 2: T007–T011、T014 可与 T006 之后并行（T006 迁移最先）
- US1: T021、T023 与 T022 并行（不同文件）
- US2: T027、T028 并行
- US3: T032、T035 并行
- US4: T038、T040、T043、T044 并行
- US5: T048 与 T045–T047 中服务实现后可并行
- Polish: T049–T052、T054 全部 [P]

---

## Parallel Example: User Story 4

```bash
# 契约与 API 客户端（后端 T036–T037 完成后）:
Task T038: apps/web/src/api/notifications.ts
Task T040: apps/web/src/utils/push.ts

# 测试（实现完成后）:
Task T043: apps/api/tests/NotificationPushAuthTest.php
Task T044: apps/web/tests/MessagesView.test.ts
```

---

## Parallel Example: Foundational

```bash
# T006 迁移落地后并行:
Task T007: PermissionSeeder.php
Task T008: adminNotification.ts
Task T009: notification.ts
Task T011: contract tests
```

---

## Implementation Strategy

### MVP First (User Story 1)

1. Complete Phase 1: Setup
2. Complete Phase 2: Foundational
3. Complete Phase 3: User Story 1（公告发送 + fan-out + push）
4. **STOP and VALIDATE**: `NotificationDispatchTest` + 学员 `MessagesView` 可见未读公告
5. 可演示最小闭环后再加 US2–US4

### Incremental Delivery

1. Setup + Foundational → 推送与数据基础就绪
2. US1 公告 → 运营可广播
3. US2 站内信 → 定向触达
4. US3 后台查询 → 运营可核对发送记录
5. US4 学员中心 → 角标 + 实时 push 完整体验
6. US5 清理 → 存储可控
7. Polish → 文档、安全、E2E

### Parallel Team Strategy

| 开发者 | 任务流 |
|--------|--------|
| A | Foundational → US1 → US2 |
| B | Foundational 完成后 → US3 admin UI |
| C | Foundational 完成后 → US4 web push |
| D | US5 清理（Foundational 后随时） |

---

## Notes

- 后台发送记录（`notification_dispatches`）首版不清理；仅 `learner_notifications` 按 2 个月策略删除
- 公告不追溯：仅发送时刻 `status=active` 学员收到
- Push 不可用时 REST 列表与 `unread-count` 必须仍可用（FR-014）
- `[P]` 任务避免同文件冲突；`NotificationComposeDialog.vue` 在 US1/US2 顺序修改
- 每个 Checkpoint 可独立提交；全量门禁见 `specs/003-admin-notifications/quickstart.md`
