# Implementation Plan: 学习端 Stitch 设计对齐

**Branch**: `007-stitch-web-fidelity` | **Date**: 2026-08-31 | **Spec**: [spec.md](./spec.md)

**Input**: Feature specification from `/specs/007-stitch-web-fidelity/spec.md`

**Note**: 纯前端视觉特性——对齐 Stitch 项目 `673993425689807844` 到 `apps/web` 已有路由与组件；不修改 API、数据库、管理端或共享契约（除非测试/文档连带）。

## Summary

将学习端 Vue SPA 的**浅色主题、排版、壳层布局与 7 个主页面表面**对齐 Stitch「拾阶学社 UI 设计系统」桌面参考；375px 移动端在同一 token 下验收单列布局。通过全局 CSS 变量 + Element Plus 主题桥接、逐视图 scoped 样式调整实现；**复用现有接口与状态管理**，首页轮播继续消费 `GET /api/learner/v1/home` 的 `banners` 字段。

## Technical Context

**Language/Version**: TypeScript 5.x strict；Vue 3.5 Composition API；Node.js 22 LTS

**Primary Dependencies**: Vue 3、Element Plus、Pinia、Vue Router、Axios、Zod（仅既有 API 响应校验）、Tailwind（`style.css` 中 `@tailwind`）、Vitest + `@vue/test-utils`

**Storage**: N/A（无数据层变更）；静态资源 `apps/web/public/assets/stitch-*.jpg` 仅作地图等 fallback 图

**Testing**: Vitest（`apps/web/tests/`）；门禁 `make test-web`

**Target Platform**: Docker Compose `web` 容器（Nginx 静态 + `/api` 反代）；本地 Vite `5173` 开发

**Project Type**: Monorepo 子应用 — 仅 `apps/web`

**Performance Goals**:
- SC-001：7 个主页面首屏在 1280px/375px 无横向滚动
- SC-002：全量前端门禁通过，现有路由测试不回归

**Constraints**:
- FR-001/FR-008：不改鉴权、业务规则；不新增 npm 依赖或 API
- 宪章：Element Plus + 原子样式；禁止原生 `alert/confirm/prompt`
- 保留 `data-testid`、`data-action` 等测试钩子（可增补不可删除）
- Night 模式与现有 `useTheme` 兼容

**Scale/Scope**: 5 个用户故事、8 条功能需求；约 15–20 个 Vue/CSS 文件；0 迁移

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

| 门禁 | 结论 | 说明 |
|------|------|------|
| I. 容器即运行契约 | PASS | 仅 `web` 镜像构建；无宿主机 PHP/Node 依赖 |
| II. 稳定兼容且可复现 | PASS | 无新 lockfile 依赖 |
| III. 契约优先与端到端类型安全 | PASS | 不修改 `packages/contracts`；Zod 用法不变 |
| IV. 数据变更安全可追溯 | PASS | 无迁移、无 ORM |
| V. 质量、安全与可运维性内建 | PASS | `make test-web` + 视觉走查 |
| VI. 令牌鉴权 | PASS | 不触碰登录/刷新逻辑 |
| Redis 使用边界 | PASS | 无 Redis 变更 |
| 双前端独立构建 | PASS | 仅 `apps/web` |

**Phase 1 复查**: 设计产物为 UI 契约与 token 表，无 API/DB 设计；复杂度追踪表留空。

## Project Structure

### Documentation (this feature)

```text
specs/007-stitch-web-fidelity/
├── plan.md              # 本文件
├── research.md          # Phase 0
├── data-model.md        # Phase 1 — UI 域模型
├── quickstart.md        # Phase 1 — 验收指南
├── contracts/
│   ├── stitch-design-system.md
│   └── page-surfaces.md
├── checklists/
│   └── requirements.md
└── spec.md
```

### Source Code (repository root)

```text
apps/web/
├── public/assets/
│   └── stitch-*.jpg          # 地图等 fallback 图（非首页 hero）
├── src/
│   ├── style.css             # 全局 token、壳层、响应式
│   ├── layouts/
│   │   └── LearnerLayout.vue # 顶栏品牌+导航
│   ├── components/
│   │   ├── SiteFooter.vue
│   │   ├── HomeBannerCarousel.vue
│   │   ├── CourseEntryRow.vue
│   │   ├── EmptyState.vue
│   │   └── VideoPlayer.vue
│   └── views/
│       ├── home/HomeView.vue
│       ├── catalog/CourseDetailView.vue
│       ├── learn/LessonView.vue
│       ├── maps/MapListView.vue, MapDetailView.vue
│       ├── me/StudentCenterView.vue
│       ├── checkout/CheckoutView.vue
│       └── auth/LoginRegisterView.vue
└── tests/
    ├── HomeView.test.ts
    ├── HomeBannerCarousel.test.ts
    ├── LearnerLayout.test.ts
    ├── MapListView.test.ts
    └── …（随改动同步）

specs/007-stitch-web-fidelity/reference/   # 可选：Stitch 导出 HTML
```

**Structure Decision**: 不新建页面路由；在既有 SFC 内调整模板与 scoped/global CSS。全局 token 集中在 `style.css`；页面特异样式保留在视图 `scoped` 块，避免过度抽象组件。

## Complexity Tracking

> 无宪章违规项，本表留空。

| Violation | Why Needed | Simpler Alternative Rejected Because |
|-----------|------------|-------------------------------------|
| — | — | — |

## Phase 0 输出

见 [research.md](./research.md)：Stitch 映射、token 策略、组件改动边界、响应式与测试策略。

## Phase 1 输出

| 产物 | 路径 |
|------|------|
| UI 数据模型 | [data-model.md](./data-model.md) |
| 设计系统契约 | [contracts/stitch-design-system.md](./contracts/stitch-design-system.md) |
| 页面表面契约 | [contracts/page-surfaces.md](./contracts/page-surfaces.md) |
| 验收指南 | [quickstart.md](./quickstart.md) |

## 实现阶段指引（供 `/speckit-tasks`）

建议任务拆分顺序：

1. **P0 全局 token + 壳层** — `style.css`、`LearnerLayout`、`SiteFooter`
2. **P1 首页 + 轮播** — `HomeView`、`HomeBannerCarousel`（接口 `banners`）
3. **P1 课程详情 + 学习页** — `CourseDetailView`、`LessonView`
4. **P2 个人中心 + 地图** — `StudentCenterView`、`MapListView`、`MapDetailView`
5. **P2 结算 + 登录** — `CheckoutView`、`LoginRegisterView`
6. **P3 测试与走查** — 更新 Vitest；按 quickstart 双断点截图对比

**当前分支状态**：壳层、首页、多页样式已有部分实现；计划阶段记录为「增量对齐 + 门禁 + 视觉走查收尾」，任务生成时应标注已完成项与待验收项。

## 风险与缓解

| 风险 | 缓解 |
|------|------|
| Stitch HTML 与 Element Plus 结构不一致 | 只提取间距/色/字体，不复制 DOM 结构 |
| 测试因文案/类名变更失败 | 同 commit 更新 `apps/web/tests` |
| 轮播与静态 hero 混淆 | 契约明确：仅 `home.banners` |
| 移动端横向溢出 | 375px DevTools 每页检查 |
