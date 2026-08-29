---
description: "Task list for admin tabs, breadcrumb, and route loading"
---

# Tasks: 管理后台标签页与导航增强

**Input**: Design documents from `/specs/002-admin-tabs-navigation/`

**Prerequisites**: plan.md, spec.md, research.md, data-model.md, contracts/navigation-ui.md, quickstart.md

**Tests**: 规格未强制 TDD；plan 与宪章要求 Vitest 覆盖 store/面包屑/进度条，测试任务集中在 Polish 阶段与各故事验收检查点。

**Organization**: 按用户故事分组，支持分阶段独立交付与验证。

## Format: `[ID] [P?] [Story] Description`

- **[P]**: 可并行（不同文件、无未完成依赖）
- **[Story]**: 用户故事标签（US1–US4）
- 描述中须含确切文件路径

## Path Conventions

- 管理端：`apps/admin/src/`、`apps/admin/tests/`
- 无后端或数据库变更

---

## Phase 1: Setup (Shared Infrastructure)

**Purpose**: 安装依赖与全局样式基础

- [x] T001 Add `nprogress` and `@types/nprogress` to `apps/admin/package.json` and update `pnpm-lock.yaml` via `pnpm install`
- [x] T002 [P] Import nprogress stylesheet and set admin primary bar color (`#38bdf8`) in `apps/admin/src/main.ts` or `apps/admin/src/style.css`

---

## Phase 2: Foundational (Blocking Prerequisites)

**Purpose**: 路由 meta、Pinia store 与路由同步——所有用户故事的前置条件

**⚠️ CRITICAL**: 完成本阶段前不得开始用户故事实现

- [x] T003 Extend `RouteMeta` with `affix`, `hideInTabs`, and optional `breadcrumb` in `apps/admin/src/router/index.ts` per `specs/002-admin-tabs-navigation/contracts/navigation-ui.md`
- [x] T004 Mark dashboard `affix: true` and forbidden `hideInTabs: true` on route records in `apps/admin/src/router/index.ts`
- [x] T005 Implement Pinia `useTabsStore` with `AdminTab` types and all actions (`openTab`, `activateTab`, `closeTab`, `closeOthers`, `closeAll`, `updateTitle`, `syncFromRoute`, `reset`) in `apps/admin/src/stores/tabs.ts`
- [x] T006 [P] Add layout-child detection helper `shouldTrackTab(route)` in `apps/admin/src/router/tabSync.ts` (skip login, first-password, hideInTabs)
- [x] T007 Wire `router.afterEach` to call `tabsStore.syncFromRoute(to)` only when `shouldTrackTab(to)` in `apps/admin/src/router/index.ts`

**Checkpoint**: Store 可单测；路由导航后 `opened`/`activeKey` 与 `fullPath` 同步

---

## Phase 3: User Story 1 - 标签页切换 (Priority: P1) 🎯 MVP

**Goal**: 默认固定「工作台」标签；菜单/链接打开新页自动建标签；点击标签切换页面；`keep-alive` 保留页面状态

**Independent Test**: 登录后见工作台标签 → 打开「课程管理」「订单管理」→ 再进课程管理不重复建标签 → 点击标签切换且 URL 一致（见 spec US1 独立测试）

### Implementation for User Story 1

- [x] T008 [US1] Create `AdminTabBar.vue` with tab list rendering, active state, and `router.push` on click in `apps/admin/src/components/AdminTabBar.vue`
- [x] T009 [US1] Integrate `AdminTabBar`, `nav-chrome` layout region, and `<keep-alive :max="20">` wrapped `router-view` in `apps/admin/src/layouts/AdminLayout.vue`
- [x] T010 [US1] Sync tab activation on browser `popstate` / route changes via `watch(route)` in `apps/admin/src/layouts/AdminLayout.vue` or `apps/admin/src/router/index.ts`
- [x] T011 [US1] Export `updateTitle(key, title)` usage pattern and call from one dynamic route view (e.g. `apps/admin/src/views/catalog/CourseEditView.vue`) for distinct edit-tab titles

**Checkpoint**: US1 验收场景 1–4 可通过人工或后续 E2E 验证

---

## Phase 4: User Story 2 - 面包屑导航 (Priority: P1)

**Goal**: 标签栏下方显示层级面包屑；文案来自 `AdminMenu` + `meta.title`；上级节点可点击返回

**Independent Test**: `/org/staff` 显示「组织管理 → 员工管理」；`/courses/new` 显示课程模块与「新建课程」（见 spec US2 独立测试）

### Implementation for User Story 2

- [x] T012 [P] [US2] Implement `useAdminBreadcrumb(route, menuEntries)` with longest-prefix menu match and `meta.breadcrumb` override in `apps/admin/src/composables/useAdminBreadcrumb.ts`
- [x] T013 [US2] Create `AdminBreadcrumb.vue` using `el-breadcrumb` with clickable parent `router.push` in `apps/admin/src/components/AdminBreadcrumb.vue`
- [x] T014 [US2] Mount `AdminBreadcrumb` below `AdminTabBar` in `apps/admin/src/layouts/AdminLayout.vue`
- [x] T015 [P] [US2] Add `meta.breadcrumb` overrides for deep routes (`courses/new`, `courses/:id/edit`, `courses/:id/preview`, `org/staff/:id/overrides`) in `apps/admin/src/router/index.ts`

**Checkpoint**: US2 验收场景 1–4 通过；面包屑与标签标题语义一致

---

## Phase 5: User Story 3 - 批量关闭标签 (Priority: P2)

**Goal**: 关闭当前 / 关闭其他 / 关闭全部；工作台不可关闭；关闭后激活相邻或回工作台

**Independent Test**: 打开 3+ 业务标签后分别执行三种关闭操作，工作台始终保留（见 spec US3 独立测试）

### Implementation for User Story 3

- [x] T016 [US3] Add per-tab close button (hidden when `!closable`) and tab context menu entries in `apps/admin/src/components/AdminTabBar.vue`
- [x] T017 [US3] Wire `closeTab`, `closeOthers`, `closeAll` to store actions and `router.push` to new `activeKey` in `apps/admin/src/components/AdminTabBar.vue`
- [x] T018 [US3] Add header-level dropdown「关闭其他」「关闭全部」shortcuts adjacent to tab bar in `apps/admin/src/layouts/AdminLayout.vue` or `AdminTabBar.vue`

**Checkpoint**: US3 验收场景 1–4 通过；关闭工作台无效

---

## Phase 6: User Story 4 - 全局加载进度条 (Priority: P2)

**Goal**: AdminLayout 内路由跳转显示 nprogress 顶栏；登录/改密页不显示；导航结束进度条消失

**Independent Test**: 菜单与标签切换可见进度条；列表页筛选不改变路由时不触发（见 spec US4 独立测试）

### Implementation for User Story 4

- [x] T019 [P] [US4] Implement `startRouteLoading` / `finishRouteLoading` with 180ms minimum visible duration in `apps/admin/src/router/loading.ts`
- [x] T020 [US4] Call loading start in `beforeEach` and finish in `afterEach` + `onError` for layout child routes only in `apps/admin/src/router/index.ts`
- [x] T021 [US4] Mount nprogress bar globally via `apps/admin/src/components/RouteLoadingBar.vue` included in `apps/admin/src/App.vue` or `AdminLayout.vue`

**Checkpoint**: US4 验收场景 1–4 通过；连续快速跳转不卡住

---

## Phase 7: Polish & Cross-Cutting Concerns

**Purpose**: 登出清理、权限边界、自动化测试与门禁

- [x] T022 Call `useTabsStore().reset()` before `router.push('/login')` on logout in `apps/admin/src/layouts/AdminLayout.vue`
- [x] T023 [P] Call `useTabsStore().reset()` on session expiry / 401 redirect path in `apps/admin/src/api/http.ts`
- [x] T024 [P] Ensure `syncFromRoute` skips tags for guard redirect targets (`forbidden`, login) in `apps/admin/src/stores/tabs.ts` or `apps/admin/src/router/tabSync.ts`
- [x] T025 [P] Add unit tests for tabs store close/affix/reset behavior in `apps/admin/tests/tabsStore.test.ts`
- [x] T026 [P] Add unit tests for breadcrumb resolution in `apps/admin/tests/AdminBreadcrumb.test.ts`
- [x] T027 [P] Add unit tests for route loading start/finish timing in `apps/admin/tests/RouteLoading.test.ts`
- [x] T028 Extend `AdminTabBar` and default dashboard tab assertions in `apps/admin/tests/AdminLayout.test.ts`
- [x] T029 Run `pnpm --filter @learn-site/admin lint typecheck test build` and manual `specs/002-admin-tabs-navigation/quickstart.md` checklist

---

## Dependencies & Execution Order

### Phase Dependencies

- **Setup (Phase 1)**: 无依赖，立即开始
- **Foundational (Phase 2)**: 依赖 Phase 1 — **阻塞所有用户故事**
- **US1 (Phase 3)**: 依赖 Phase 2 — MVP
- **US2 (Phase 4)**: 依赖 Phase 2；集成依赖 US1 的 `AdminLayout` 结构（T009 完成后可并行开发 composable T012）
- **US3 (Phase 5)**: 依赖 US1 的 `AdminTabBar.vue`（T008）
- **US4 (Phase 6)**: 依赖 Phase 2；可与 US2/US3 并行（不同文件）
- **Polish (Phase 7)**: 依赖 US1–US4 核心实现完成

### User Story Dependencies

| 故事 | 优先级 | 依赖 | 可独立验证 |
|------|--------|------|------------|
| US1 标签页切换 | P1 | Phase 2 | ✅ |
| US2 面包屑 | P1 | Phase 2 + Layout 集成 | ✅（composable 单测 + 布局验收） |
| US3 批量关闭 | P2 | US1 AdminTabBar | ✅ |
| US4 加载进度 | P2 | Phase 2 | ✅ |

### Parallel Opportunities

- **Phase 1**: T001 与 T002 可并行（T002 在 T001 装包后更稳，T002 标 [P] 可在装包同时改样式）
- **Phase 2**: T006 与 T005 可部分并行（T006 不依赖 store 实现细节）
- **US2**: T012 与 T015 可与 T013 并行（不同文件）
- **US4**: T019 可与 US2/US3 并行
- **Polish**: T025、T026、T027、T023 可并行

### Parallel Example: User Story 2

```bash
# 并行启动 US2 底层与路由配置：
Task T012: "Implement useAdminBreadcrumb in apps/admin/src/composables/useAdminBreadcrumb.ts"
Task T015: "Add meta.breadcrumb overrides in apps/admin/src/router/index.ts"

# T014 依赖 T013 完成后集成到 AdminLayout
```

### Parallel Example: Polish Tests

```bash
Task T025: "tabsStore.test.ts"
Task T026: "AdminBreadcrumb.test.ts"
Task T027: "RouteLoading.test.ts"
```

---

## Implementation Strategy

### MVP First (User Story 1)

1. Complete Phase 1: Setup
2. Complete Phase 2: Foundational
3. Complete Phase 3: User Story 1
4. **STOP and VALIDATE**: quickstart 剧本 §1（标签打开与切换）
5. 可演示最小可用多标签后台

### Incremental Delivery

1. Setup + Foundational → 基础就绪
2. US1 → 多标签 MVP
3. US2 → 面包屑（同为 P1，建议紧随 US1）
4. US3 → 批量关闭
5. US4 → nprogress
6. Polish → 登出清理 + Vitest + 全量门禁

### Suggested MVP Scope

**Phase 1 + 2 + 3（T001–T011）** — 交付固定工作台与多标签切换，不含面包屑、批量关闭菜单与进度条。

---

## Notes

- 所有任务均含文件路径，格式 `- [ ] T### [P?] [US?] Description`
- 不修改 `apps/web` 或 `apps/api`
- 动态页标题更新为可选增强（T011）；至少覆盖一门课程编辑页作范例
- `pnpm-lock.yaml` 变更须随 T001 一并提交
