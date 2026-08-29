# Research: 002-admin-tabs-navigation

日期：2026-08-29。本功能为纯管理端前端增强，Technical Context 无未决 NEEDS CLARIFICATION。

## 标签页状态管理

- **Decision**: 使用 Pinia store（`stores/tabs.ts`）管理已打开标签、当前激活 `fullPath` 与固定标签配置。
- **Rationale**: 宪章规定 Pinia 承载跨路由生命周期状态；标签栏、面包屑、`router-view` 与守卫均需读写同一状态，组件内 `ref` 无法覆盖。
- **Alternatives considered**: 纯 `provide/inject`（跨守卫不便）；`sessionStorage` 持久化（规格假设会话内内存态，且登出需清零）；全局 event bus（不可测试、难维护）。

## 标签唯一标识

- **Decision**: 以 `route.fullPath`（含 query）作为标签 `key`；标题取自 `meta.title`，动态页可由视图在数据加载后更新 store 中对应 tab 的 `title`。
- **Rationale**: 规格要求「同一业务页不同实例视为不同标签」（如 `/courses/1/edit` vs `/courses/2/edit`）；`fullPath` 自然区分 params 与 query。同名路由复用：导航到已存在 `fullPath` 时只 `activateTab`。
- **Alternatives considered**: 仅用 `route.name`（无法区分多实例）；`name + JSON.stringify(params)`（与 `fullPath` 等价但需自行规范化 query 顺序）。

## 固定工作台标签

- **Decision**: 初始化 store 时注入一条 `path: '/'`、`affix: true`、`closable: false` 的工作台标签；路由 `meta.affix` 标记其他不可关闭页（首版仅工作台）。
- **Rationale**: 对齐 FR-002/FR-008；与 vue-element-admin 等后台惯例一致。
- **Alternatives considered**: 无固定标签、靠「关闭全部」后 redirect（违反规格）；多固定首页（规格明确首版仅工作台）。

## 标签切换与页面缓存

- **Decision**: `AdminLayout` 内使用 `<keep-alive :max="20">` 包裹 `<component :is="..." :key="route.fullPath" />` 或等效 `router-view` v-slot 模式，缓存已访问页面组件实例。
- **Rationale**: 多标签后台的核心价值是切换时不丢失列表筛选、表单草稿；不缓存会导致体验倒退。
- **Alternatives considered**: 每次切换销毁重建（状态丢失）；无限缓存（内存风险，`max=20` 与常见打开标签上限一致）。

## 面包屑推导

- **Decision**: `useAdminBreadcrumb` 组合式函数：① 在 `AdminMenu` 可见项中按 `route.path` 做最长前缀匹配得到菜单链；② 若当前 `meta.title` 与链末项不同则追加为叶子；③ 支持 `meta.breadcrumb` 数组完全覆盖（用于「课程管理 → 编辑课程」等深层页）。
- **Rationale**: 规格要求文案与菜单/页面标题一致，且首版不建独立面包屑配置后台；复用 `AdminMenu` 单一数据源避免与侧栏漂移。
- **Alternatives considered**: 每路由手写完整 breadcrumb（重复、难维护）；仅显示当前标题（不满足层级需求）。

## 全局加载进度条

- **Decision**: 引入 `nprogress`，在 `router.beforeEach` 调用 `NProgress.start()`，`afterEach` / `onError` 调用 `NProgress.done()`；仅在已进入 `AdminLayout` 的子路由间导航时启用（通过 `to.matched` 是否含 layout 判断）。
- **Rationale**: 用户明确要求 nprogress；库成熟、体积小，比从零实现渐变更符合请求。学习端已有自定义 `RouteLoadingBar`，但宪章允许两端独立，且用户指定管理端用 nprogress。
- **Alternatives considered**: 复制学习端 CSS 动画条（未满足用户指定）；在 Axios 拦截器显示（无法覆盖纯路由切换、违反 FR-015）。

## 进度条闪烁控制

- **Decision**: 借鉴 `apps/web/src/router/loading.ts`，对 `NProgress.done()` 施加最短可见时间（约 180ms），快速导航时不闪烁。
- **Rationale**: 满足 SC-004「不卡住」同时避免极短跳转时进度条一闪而过无感知。
- **Alternatives considered**: 无最小时长（体验差）；人为延迟路由（损害性能）。

## 权限拦截与无效标签

- **Decision**: 仅在 `router.afterEach` 且目标路由为 layout 子路由、非 `hideInTabs`、导航未被 guard 重定向到登录/改密时调用 `openTab`；若 guard 将用户重定向到 `forbidden` 或 fallback，以最终落点为准，不为中间失败目标建标签。
- **Rationale**: FR-017 与边界场景「无权限不保留无效标签」。
- **Alternatives considered**: `beforeEach` 预建标签（拦截后留下脏标签）；在 guard 内耦合 store（守卫职责过重）。

## UI 组件选型

- **Decision**: 标签栏使用 Element Plus `el-scrollbar` + 自定义 tag 样式（或 `el-tag` + `closable`），右键/下拉菜单用 `el-dropdown` 提供「关闭其他」「关闭全部」；面包屑用 `el-breadcrumb` + `el-breadcrumb-item`。
- **Rationale**: 宪章要求 Element Plus 负责基础交互；与现有管理端视觉一致。
- **Alternatives considered**: `el-tabs`（与路由多实例、固定标签语义耦合重）；第三方 vue-admin-tabs 包（额外依赖、样式不可控）。

## 测试策略

- **Decision**: Vitest 单测覆盖 `tabs` store 关闭策略、面包屑解析、路由 loading 启停；`AdminLayout` 快照/行为测验证工作台默认标签与菜单共存；E2E 可选一条 smoke「打开两页 → 切换 → 关闭其他」。
- **Rationale**: 宪章 V 要求与风险相称的自动化测试；逻辑集中在 store/utils 便于单测。
- **Alternatives considered**: 仅人工测试（回归成本高）；仅 E2E（反馈慢）。
