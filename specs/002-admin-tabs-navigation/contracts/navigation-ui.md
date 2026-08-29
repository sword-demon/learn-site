# Navigation UI Contract: 管理后台标签页与导航

**Scope**: `apps/admin` 客户端导航行为契约。无 HTTP API。

**Version**: 1.0.0（与 `002-admin-tabs-navigation` 规格对齐）

## 1. 路由 Meta 契约

扩展 `vue-router` 的 `RouteMeta`（`apps/admin/src/router/index.ts`）：

| 字段 | 类型 | 必填 | 说明 |
|---|---|---|---|
| `title` | `string` | 推荐 | 标签标题与面包屑默认文案 |
| `public` | `boolean` | 已有 | 公开路由（登录页等） |
| `permission` | `string` | 已有 | 权限码 |
| `affix` | `boolean` | 否 | `true`：固定标签，不可关闭 |
| `hideInTabs` | `boolean` | 否 | `true`：不自动加入标签栏 |
| `breadcrumb` | `BreadcrumbItem[]` | 否 | 完全覆盖自动面包屑 |

### BreadcrumbItem

```ts
interface BreadcrumbItem {
  title: string;
  path?: string; // 省略或等于当前页时表示不可点击叶子
}
```

### 路由约定

| 路由 | affix | hideInTabs | 说明 |
|---|---|---|---|
| `/` (dashboard) | `true` | — | 工作台，始终存在 |
| `/forbidden` | — | `true` | 权限拒绝落点，不建业务标签 |
| `/login`, `/first-password` | — | — | 非 AdminLayout 子路由，不参与标签系统 |
| 其他 layout 子路由 | — | — | 默认 `closable`，自动建标签 |

动态页（如 `courses/:id/edit`）可在视图加载后调用 `tabsStore.updateTitle(fullPath, newTitle)` 更新标签文案，不改变 `key`。

## 2. Tabs Store 公开 API

模块：`apps/admin/src/stores/tabs.ts`

```ts
interface AdminTab {
  key: string;
  title: string;
  path: string;
  name?: string;
  affix: boolean;
  closable: boolean;
}

interface TabsState {
  opened: AdminTab[];
  activeKey: string;
}

// Actions（命名稳定，供布局与守卫调用）
function openTab(route: RouteLocationNormalized): void;
function activateTab(key: string): void;
function closeTab(key: string): void;
function closeOthers(activeKey: string): void;
function closeAll(): void;
function updateTitle(key: string, title: string): void;
function syncFromRoute(route: RouteLocationNormalized): void;
function reset(): void;
```

### 行为契约

| 方法 | 前置 | 后置 |
|---|---|---|
| `openTab` | `route` 为 layout 子路由且非 `hideInTabs` | 若 `key=fullPath` 不存在则追加；`activeKey` 更新 |
| `activateTab` | `key` 存在于 `opened` | `activeKey = key` |
| `closeTab` | `closable === true` | 移除标签；`activeKey` 设为右邻 → 左邻 → 首个 affix |
| `closeOthers` | — | 保留所有 `affix` 与 `activeKey` 指向项 |
| `closeAll` | — | `opened` 仅剩 affix；`activeKey` 为工作台 |
| `reset` | — | 等同初始：仅工作台，`activeKey='/'` |

## 3. 面包屑解析契约

模块：`apps/admin/src/composables/useAdminBreadcrumb.ts`

**输入**：`route: RouteLocationNormalized`，`menuEntries: AdminMenuEntry[]`（已按权限过滤）

**输出**：`BreadcrumbItem[]`，至少 1 项

**算法**：

1. 若 `route.meta.breadcrumb` 为数组 → 直接返回
2. 在 `menuEntries` 中找最长 `path` 前缀匹配 `route.path` 的叶子或组
3. 组匹配时输出 `[组.label, 叶子.label]`（若当前 path 深于叶子，追加 `meta.title`）
4. 无菜单匹配时输出 `[{ title: meta.title ?? '未命名页面' }]`

**点击**：`path` 存在且 `path !== route.path` 时 `router.push(path)`

## 4. 路由加载进度契约

模块：`apps/admin/src/router/loading.ts`

```ts
function startRouteLoading(): void;
function finishRouteLoading(): void;
```

**触发规则**：

| 事件 | 动作 |
|---|---|
| `beforeEach`：目标为 AdminLayout 子路由 | `startRouteLoading()` |
| `afterEach`：同上 | `finishRouteLoading()` |
| `onError` | `finishRouteLoading()` |
| 目标为 login / first-password / 无 layout matched | 不调用 |

实现使用 `nprogress`；`finishRouteLoading` 保证最短可见时间 ≥ 180ms。

## 5. 布局集成契约

`AdminLayout.vue` 结构（逻辑顺序）：

```text
el-aside (menu)
el-container
  el-header (collapse + user)
  nav-chrome
    AdminTabBar      → tabs store + router.push
    AdminBreadcrumb  → useAdminBreadcrumb
  RouteLoadingBar    → nprogress 或包装组件
  el-main
    keep-alive > router-view
```

**登出**：`clearTokens()` 之前或同时 `tabsStore.reset()`。

**401 跳转登录**：HTTP 客户端统一处理处调用 `tabsStore.reset()`（若尚未调用）。

## 6. 测试契约

| 用例 ID | 描述 | 通过条件 |
|---|---|---|
| NAV-T01 | 默认工作台 | 登录后仅 affix 工作台，且为 active |
| NAV-T02 | 打开新页 | 菜单进入课程管理 → 新增标签，标题「课程管理」 |
| NAV-T03 | 复用标签 | 再次进入课程管理不增加 `opened.length` |
| NAV-T04 | 关闭当前 | 关闭订单管理后激活相邻标签 |
| NAV-T05 | 关闭其他 | 仅保留工作台 + 当前 |
| NAV-T06 | 关闭全部 | 仅工作台，且 active |
| NAV-T07 | 面包屑层级 | `/org/staff` 显示组织管理 → 员工管理 |
| NAV-T08 | 进度条 | layout 内跳转 start/finish 成对调用 |
| NAV-T09 | 登出清空 | logout 后 `opened.length === 1` |
| NAV-T10 | 无权限 | 直达无权限 URL 不保留非法标签 |

Vitest 文件：`tabsStore.test.ts`、`AdminBreadcrumb.test.ts`、`RouteLoading.test.ts`。
