# Implementation Plan: 管理后台标签页与导航增强

**Branch**: `002-admin-tabs-navigation` | **Date**: 2026-08-29 | **Spec**: [spec.md](./spec.md)

**Input**: Feature specification from `/specs/002-admin-tabs-navigation/spec.md`

**Note**: 纯管理端前端增强，无后端 API、数据库或学习端改动。

## Summary

在 `apps/admin` 主布局中引入多标签页导航、面包屑与全局路由加载进度条。标签状态由 Pinia 管理，默认固定「工作台」标签；面包屑从 `AdminMenu` 与路由 `meta` 推导；页面跳转使用 `nprogress` 在顶栏显示加载反馈。实现范围限于 `AdminLayout` 及其周边组合式函数/组件，复用现有权限与路由守卫，不新增服务端契约。

## Technical Context

**Language/Version**: TypeScript 5.x (strict)；Vue 3.5；Node.js 22 LTS

**Primary Dependencies**: Vue Router 4、Pinia 2、Element Plus 2、Tailwind CSS（布局原子类）、`nprogress`（新增，用于顶栏进度条）

**Storage**: 无持久化存储；标签与会话导航状态仅存 Pinia 内存，登出时 `reset()`

**Testing**: Vitest + `@vue/test-utils` + happy-dom；现有 `AdminLayout.test.ts`、`AdminRouteAccess.test.ts` 扩展；可选 Playwright 冒烟补充标签切换

**Target Platform**: 管理端 SPA（`apps/admin`），桌面 Web 为主

**Project Type**: Monorepo 内独立前端应用增强（`apps/admin` only）

**Performance Goals**: SC-002/SC-004 — 标签与面包屑在导航后 1 秒内就绪；进度条在导航结束后 500ms 内消失

**Constraints**: 宪章要求 Composition API + `<script setup>`；Pinia 仅承载跨路由状态；不得改动学习端；登录/改密页不使用主布局；无 `any`；生产构建须通过 `vue-tsc`、ESLint、Vitest

**Scale/Scope**: 约 20+ 后台路由；4 个用户故事、18 条功能需求；预计新增 6–10 个源文件、更新 `AdminLayout` 与 `router/index.ts`

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

| 门禁 | 结论 | 说明 |
|---|---|---|
| I. 容器即运行契约 | PASS | 仅前端变更；验证仍通过 Compose 内 `apps/admin` 构建与测试 |
| II. 稳定兼容且可复现 | PASS | 新增 `nprogress` 须写入 `apps/admin/package.json` 并更新 `pnpm-lock.yaml` |
| III. 契约优先与端到端类型安全 | PASS | 无新 REST API；路由 `meta` 扩展与 Pinia store 接口在 `contracts/navigation-ui.md` 文档化；TS 严格类型 |
| IV. 数据变更安全可追溯 | PASS | 无数据库/迁移 |
| V. 质量、安全与可运维性内建 | PASS | Vitest 覆盖 store、面包屑解析、布局集成；Lint/类型检查门禁不变 |
| VI. 令牌鉴权 | PASS | 登出/会话失效时清空标签 store；权限拦截路由不创建标签 |
| 双前端独立构建 | PASS | 变更隔离在 `apps/admin` |
| Pinia 使用边界 | PASS | 标签导航为跨路由生命周期状态，符合宪章 Pinia 使用场景 |
| Element Plus + Tailwind 分工 | PASS | 标签/面包屑用 Element Plus 组件；间距与布局用 Tailwind |

Phase 1 复查：设计未引入后端、Redis 或学习端耦合；`nprogress` 为轻量 UI 依赖，不违反「不得为假设性未来引入框架」原则（用户明确要求进度条体验）。

## Project Structure

### Documentation (this feature)

```text
specs/002-admin-tabs-navigation/
├── plan.md
├── research.md
├── data-model.md
├── quickstart.md
├── contracts/
│   └── navigation-ui.md
├── checklists/
│   └── requirements.md
└── spec.md
```

### Source Code (repository root)

```text
apps/admin/
├── package.json                    # + nprogress, @types/nprogress
├── src/
│   ├── layouts/
│   │   ├── AdminLayout.vue         # 集成标签栏、面包屑、RouteLoadingBar
│   │   └── AdminMenu.ts            # 复用；可能导出面包屑查找辅助
│   ├── components/
│   │   ├── AdminTabBar.vue         # 标签栏 UI + 关闭菜单
│   │   ├── AdminBreadcrumb.vue     # 面包屑
│   │   └── RouteLoadingBar.vue     # nprogress 挂载点（或薄包装）
│   ├── composables/
│   │   └── useAdminBreadcrumb.ts   # 从 menu + route 推导路径
│   ├── stores/
│   │   └── tabs.ts                 # Pinia：opened tabs、激活、关闭策略
│   ├── router/
│   │   ├── index.ts                # meta 扩展、守卫挂钩 tabs + nprogress
│   │   └── loading.ts              # start/finish 封装（对齐 web 模式）
│   └── main.ts                     # 引入 nprogress 样式
└── tests/
    ├── tabsStore.test.ts
    ├── AdminBreadcrumb.test.ts
    ├── RouteLoading.test.ts
    └── AdminLayout.test.ts         # 扩展
```

**Structure Decision**: 所有实现落在 `apps/admin`。不抽取到 `packages/contracts`（无跨应用共享需求）。学习端 `RouteLoadingBar` 保持独立实现，避免为进度条创建共享包。

## Complexity Tracking

> 无宪章违规需豁免。

| Violation | Why Needed | Simpler Alternative Rejected Because |
|-----------|------------|-------------------------------------|
| — | — | — |

## Phase 0: Research Summary

见 [research.md](./research.md)。关键结论：

- Pinia `tabs` store + `fullPath` 作为标签唯一键
- 面包屑由 `AdminMenu` 最长前缀匹配 + `route.meta` 推导，详情页支持 `meta.breadcrumb` 覆盖
- `nprogress` 经 `router.beforeEach` / `afterEach` 驱动；登录布局外页面不挂载
- `<keep-alive>` 包裹 `router-view`，`:key="route.fullPath"` 保留标签切换时的表单状态
- 权限拦截落点（如 `forbidden`、重定向）不创建业务标签

## Phase 1: Design Summary

见 [data-model.md](./data-model.md)、[contracts/navigation-ui.md](./contracts/navigation-ui.md)、[quickstart.md](./quickstart.md)。

- **客户端实体**：`AdminTab`、`BreadcrumbItem`、`TabsSessionState`
- **路由 meta 契约**：`title`、`affix`、`hideInTabs`、可选 `breadcrumb` 覆盖
- **Store 公开 API**：`openTab`、`activateTab`、`closeTab`、`closeOthers`、`closeAll`、`syncFromRoute`、`reset`
- **验证**：Vitest 单元 + 人工 quickstart 剧本

## Implementation Notes (for tasks phase)

1. **路由 meta 补齐**：为 `dashboard` 设 `affix: true`；`forbidden` 设 `hideInTabs: true`；课程编辑等动态页允许页面内 `router.replace` 更新 `meta.title`（如带课程名）
2. **AdminLayout 结构**：`header` 下新增 `nav-chrome` 区域（标签栏 + 面包屑），`main` 内 `keep-alive` + `router-view`
3. **关闭策略**：关闭当前 → 激活右侧标签，无则左侧，再无则工作台；`closeAll` 保留 `affix` 标签
4. **登出钩子**：`AdminLayout.onCommand(logout)` 与 `http` 401 跳转前调用 `tabsStore.reset()`
5. **样式**：nprogress 颜色对齐管理端主色（`#38bdf8` / Element Plus primary）
