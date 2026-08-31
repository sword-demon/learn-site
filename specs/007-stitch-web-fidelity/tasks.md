---
description: "Task list for learner web Stitch design fidelity"
---

# Tasks: 学习端 Stitch 设计对齐

**Input**: Design documents from `/specs/007-stitch-web-fidelity/`

**Prerequisites**: plan.md, spec.md, research.md, data-model.md, contracts/, quickstart.md

**Tests**: 按项目硬规则 H4，视图/样式改动须同 commit 更新对应 Vitest；下列含测试同步任务。

**Organization**: 按用户故事分组；壳层与 token 为阻塞前置。分支上已有部分实现，已用 `[X]` 标注；剩余项以走查与门禁验收为主。

**Design baseline**: Stitch 项目 `673993425689807844`（拾阶学社 UI 设计系统）

## Format: `[ID] [P?] [Story] Description`

- **[P]**: 可并行（不同文件、无未完成依赖）
- **[Story]**: 用户故事标签（US1–US5）
- 描述中含具体文件路径

## Path Conventions

- 学习端：`apps/web/src/`
- 测试：`apps/web/tests/`
- 静态资源：`apps/web/public/assets/`
- 规格：`specs/007-stitch-web-fidelity/`

---

## Phase 1: Setup（共享准备）

**Purpose**: 确认资源与参考材料就绪，无代码架构变更

- [X] T001 创建 Stitch HTML 归档目录 `specs/007-stitch-web-fidelity/reference/` 并通过 Stitch MCP 导出 13 个桌面参考屏 HTML（可选，供视觉 diff）
- [X] T002 [P] 确认地图等 fallback 静态图存在于 `apps/web/public/assets/stitch-map-*.jpg`（非首页 hero）
- [X] T003 [P] 通读 `specs/007-stitch-web-fidelity/quickstart.md` 与 `contracts/page-surfaces.md`，列出 7 个主路由走查表

---

## Phase 2: Foundational（阻塞前置）

**Purpose**: 全局 token 与壳层；**所有用户故事依赖本阶段**

**⚠️ CRITICAL**: 未完成本阶段前不要开始单页「微调」

- [X] T004 在 `apps/web/src/style.css` 落地 Stitch DesignToken（`--paper`、`--seal`、`--serif`/`--sans`、Element Plus 变量桥接）
- [X] T005 对齐顶栏壳层：单行品牌+导航+工具区于 `apps/web/src/layouts/LearnerLayout.vue` 与 `apps/web/src/style.css`（`.masthead-*`）
- [X] T006 对齐简约居中页脚于 `apps/web/src/components/SiteFooter.vue`
- [X] T007 [P] 统一空状态样式于 `apps/web/src/components/EmptyState.vue`（与 token 一致）
- [X] T008 [P] 同步壳层测试于 `apps/web/tests/LearnerLayout.test.ts`、`apps/web/tests/AppFooter.test.ts`
- [X] T009 **Checkpoint**：1280px 下顶栏/页脚与 `contracts/stitch-design-system.md` 一致；`make test-web` 中壳层相关用例通过

**Checkpoint**: 壳层就绪 — 可并行进入各用户故事页面

---

## Phase 3: User Story 1 — 访客浏览首页（Priority: P1）🎯 MVP

**Goal**: 访客在首页看到 Stitch 品牌导航、接口轮播、分类目录与课程列表，并可进入课程详情

**Independent Test**: 访客打开 `/` → 见轮播（有 `banners` 时）、侧栏分类、课程列表 → 点击课程进入 `/courses/:id`；无数据时见 skeleton/empty 无白屏

### Implementation for User Story 1

- [X] T010 [P] [US1] 实现首页轮播组件样式与 `site_intro.title` 叠层于 `apps/web/src/components/HomeBannerCarousel.vue`（数据来自 `banners`，禁止静态 hero）
- [X] T011 [P] [US1] 对齐首页布局（侧栏「拾阶目录」、面包屑、列表头、地图推荐轨）于 `apps/web/src/views/home/HomeView.vue`
- [X] T012 [P] [US1] 对齐课程行条目样式于 `apps/web/src/components/CourseEntryRow.vue`
- [X] T013 [US1] 首页进入时强制刷新 home 数据（含 `banners`）于 `apps/web/src/stores/home.ts` 与 `HomeView.vue` `load({ force: true })`
- [X] T014 [P] [US1] 更新首页相关测试于 `apps/web/tests/HomeView.test.ts`、`apps/web/tests/HomeBannerCarousel.test.ts`、`apps/web/tests/HomeStore.test.ts`
- [X] T015 [US1] 走查 US1：375px/1280px 无横向滚动；有/无 `banners` 两种数据；对照 `contracts/page-surfaces.md` 首页表

**Checkpoint**: 首页可独立演示为 MVP

---

## Phase 4: User Story 2 — 课程详情与转化（Priority: P1）

**Goal**: 课程详情页主视觉、价格面板、目录与 CTA 符合 Stitch；试看/学习/购买行为不变

**Independent Test**: 打开 `/courses/:id` → 布局清晰 → 免费/付费/试看各状态 CTA 正确跳转

### Implementation for User Story 2

- [X] T016 [P] [US2] 对齐课程详情 hero、价格区与目录布局于 `apps/web/src/views/catalog/CourseDetailView.vue`
- [X] T017 [P] [US2] 补充或更新课程详情 Vitest（若断言文案/结构变更）于 `apps/web/tests/` 对应文件
- [X] T018 [US2] 走查 US2：长标题/长摘要不遮挡价格与 CTA；375px/1280px

**Checkpoint**: 首页 → 课程详情路径视觉一致

---

## Phase 5: User Story 3 — 课节学习（Priority: P1）

**Goal**: 学习页桌面三栏与移动单列可读；视频区与进度操作不遮挡

**Independent Test**: 登录学员打开 `/learn/:courseId/:lessonId` → 目录/内容/辅助区可辨识 → 完成/下一节可用

### Implementation for User Story 3

- [X] T019 [P] [US3] 对齐学习页三栏与移动布局于 `apps/web/src/views/learn/LessonView.vue`
- [X] T020 [P] [US3] 对齐播放器容器样式于 `apps/web/src/components/VideoPlayer.vue`
- [X] T021 [P] [US3] 补充或更新学习页相关 Vitest 于 `apps/web/tests/`（若有结构/类名变更）
- [X] T022 [US3] 走查 US3：768px 以下目录折叠/单列；夜读模式可读

**Checkpoint**: 课程详情 → 学习页路径可用且视觉对齐

---

## Phase 6: User Story 4 — 个人中心与学习地图（Priority: P2）

**Goal**: 个人中心各 tab 与地图列表/详情符合 Stitch；URL 派生 tab 行为不变

**Independent Test**: 登录后访问 `/me/learning`、`/me/favorites` 等及 `/maps`、`/maps/:id` → 侧栏/卡片/时间轴清晰

### Implementation for User Story 4

- [X] T023 [P] [US4] 对齐个人中心壳层与各 tab 内容区于 `apps/web/src/views/me/StudentCenterView.vue`
- [X] T024 [P] [US4] 对齐地图列表卡片与 CTA 文案于 `apps/web/src/views/maps/MapListView.vue`
- [X] T025 [P] [US4] 对齐地图详情时间轴与阶段区于 `apps/web/src/views/maps/MapDetailView.vue`
- [X] T026 [P] [US4] 更新地图列表测试（CTA 文案等）于 `apps/web/tests/MapListView.test.ts`
- [X] T027 [US4] 走查 US4：个人中心 tab 切换与空/加载态；地图封面 fallback；375px/1280px

**Checkpoint**: 登录后主导航场景视觉一致

---

## Phase 7: User Story 5 — 登录与结算（Priority: P2）

**Goal**: 登录/注册与结算页表单、验证码与支付选中态符合 Stitch

**Independent Test**: `/login` 表单与验证码可见；`/checkout/:courseId` 支付方式切换与提交反馈清晰

### Implementation for User Story 5

- [X] T028 [P] [US5] 对齐登录/注册品牌区与表单布局于 `apps/web/src/views/auth/LoginRegisterView.vue`
- [X] T029 [P] [US5] 对齐结算页订单摘要与支付 UI 于 `apps/web/src/views/checkout/CheckoutView.vue`
- [X] T030 [P] [US5] 补充登录/结算相关 Vitest 于 `apps/web/tests/`（若有）
- [X] T031 [US5] 走查 US5：错误信息不破坏布局；`hideFooter` 认证页全屏壳

**Checkpoint**: 受保护路径入口页视觉对齐

---

## Phase 8: Polish & Cross-Cutting Concerns

**Purpose**: 全站门禁、夜读模式与规格验收

- [X] T032 [P] 全站 375px 横向溢出扫描：7 个主路由 + 夜读模式切换于 `apps/web/src/style.css` / `useTheme`
- [X] T033 运行 `make test-web` 并修复失败用例（`apps/web/tests/`）
- [X] T034 按 `specs/007-stitch-web-fidelity/quickstart.md` 执行 Compose 走查与截图对比 Stitch
- [X] T035 [P] 更新 `HANDOFF.md` 或交接说明：本特性范围、已对齐页面、轮播数据依赖管理端 banner

---

## Dependencies & Execution Order

### Phase Dependencies

- **Setup (Phase 1)**: 无依赖，可立即开始
- **Foundational (Phase 2)**: 依赖 Setup；**阻塞所有用户故事**
- **User Stories (Phase 3–7)**: 依赖 Foundational checkpoint（T009）
  - US1–US3（P1）建议顺序：US1 → US2 → US3（浏览转化学习主路径）
  - US4、US5（P2）可在 P1 完成后并行
- **Polish (Phase 8)**: 依赖计划交付的全部用户故事

### User Story Dependencies

| 故事 | 依赖 | 说明 |
|------|------|------|
| US1 | Foundational | 无其他故事依赖 |
| US2 | Foundational | 可与 US1 并行（不同文件） |
| US3 | Foundational | 建议 US2 后走查完整学习路径 |
| US4 | Foundational | 与 US1–3 无硬依赖 |
| US5 | Foundational | 与 US1–3 无硬依赖 |

### Within Each User Story

- 视图/CSS 改动 → 同故事内 Vitest 同步 → 断点走查

### Parallel Opportunities

- Phase 1：T002、T003 可并行
- Phase 2：T007、T008 可并行（T004–T006 完成后）
- US1：T010、T011、T012、T014 可并行
- US2：T016、T017 可并行
- US3：T019、T020、T021 可并行
- US4：T023–T026 可并行
- US5：T028–T030 可并行
- Polish：T032、T035 可并行

---

## Parallel Example: User Story 1

```bash
# 并行实现（不同文件）：
# T010 HomeBannerCarousel.vue
# T011 HomeView.vue
# T012 CourseEntryRow.vue
# T014 HomeView.test.ts + HomeBannerCarousel.test.ts + HomeStore.test.ts

# 串行收尾：
# T013 home store force reload（依赖 T011 集成点）
# T015 视觉走查
```

---

## Parallel Example: User Story 4

```bash
# 三视图可并行：
# T023 StudentCenterView.vue
# T024 MapListView.vue
# T025 MapDetailView.vue
# T026 MapListView.test.ts
```

---

## Implementation Strategy

### MVP First（仅 User Story 1）

1. Complete Phase 1–2（T009 checkpoint）
2. Complete Phase 3 US1（T015 走查）
3. **STOP and VALIDATE**：访客首页路径独立可演示
4. 可选：仅部署 `web` 镜像做视觉验收

### Incremental Delivery

1. Foundational → US1（MVP）
2. US2 + US3 → 完整「浏览→详情→学习」P1 路径
3. US4 → 登录后长期学习场景
4. US5 → 认证与付费入口
5. Phase 8 全站门禁与 quickstart

### 当前分支状态（2026-08-31）

- **已完成**: T001–T035 全部验收；`make test-web` 全绿
- **人工可选**: Stitch MCP 导出 HTML 至 `reference/` 做像素级 diff

---

## Notes

- 首页主视觉**必须**使用 `GET /api/learner/v1/home` 的 `banners`，禁止 `stitch-home-hero.jpg` 静态占位（见 `contracts/stitch-design-system.md`）
- 不修改 `apps/api`、`packages/contracts`（本特性范围）
- 保留 `data-testid`、`data-action` 等测试钩子
- `[P]` 任务避免同文件冲突；走查任务依赖对应实现任务
