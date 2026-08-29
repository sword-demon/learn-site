# Data Model: 002-admin-tabs-navigation

本功能无服务端持久化。以下为管理端 SPA 客户端状态模型。

## AdminTab

表示主布局中一个已打开页面实例。

| 字段 | 类型 | 规则 |
|---|---|---|
| key | string | 等于 Vue Router `fullPath`；全局唯一 |
| title | string | 展示标题；默认来自 `route.meta.title`，缺失时用「未命名页面」 |
| path | string | `route.path`；用于菜单高亮与面包屑 |
| name | string \| undefined | `route.name`；调试与测试用 |
| affix | boolean | `true` 表示固定标签，不可关闭；首版仅工作台 |
| closable | boolean | `!affix`；UI 是否显示关闭按钮 |

**校验**：

- `key` 非空
- `affix === true` 时 `closable` 必须为 `false`
- 同一 `key` 在 `opened` 列表中最多出现一次

**状态转换**：

```text
[导航到新 fullPath] --> openTab (若不存在) --> opened[]
[点击标签 / 路由变化] --> activateTab --> activeKey = key
[closeTab] --> 从 opened 移除；activeKey 按相邻规则重算
[closeOthers] --> opened 保留 affix + activeKey
[closeAll] --> opened 仅保留 affix 项；activeKey = 工作台
[logout / reset] --> opened 重置为仅含工作台；activeKey = '/'
```

## BreadcrumbItem

面包屑中的一个导航节点。

| 字段 | 类型 | 规则 |
|---|---|---|
| title | string | 展示文案；来自菜单 label 或 `meta.title` |
| path | string \| undefined | 可点击跳转目标；叶子节点可为 `undefined`（仅展示） |
| clickable | boolean | `path` 存在且不等于当前 `fullPath` 时为 `true` |

**推导顺序**（默认模式）：

1. 自 `AdminMenu` 解析菜单祖先链（最长路径前缀匹配）
2. 若当前页 `meta.title` 与链末不同，追加叶子项
3. 若 `meta.breadcrumb` 存在，完全替换上述结果

## TabsSessionState

Pinia store 根状态。

| 字段 | 类型 | 规则 |
|---|---|---|
| opened | AdminTab[] | 有序；工作台（affix）始终在首位 |
| activeKey | string | 必须引用 `opened` 中某项的 `key`，或导航完成后即将同步 |

**不变量**：

- `opened` 至少包含一条 `affix` 工作台标签（`key` 对应 `/`）
- `activeKey` 对应的标签必须存在于 `opened`（同步后）
- 登出或 `reset()` 后恢复初始态

## RouteMeta 扩展（与 router 共享）

| 字段 | 类型 | 规则 |
|---|---|---|
| title | string | 已有；标签与面包屑标题源 |
| affix | boolean | 可选；`true` 时标签不可关闭 |
| hideInTabs | boolean | 可选；`true` 时不自动创建标签（如 `forbidden`） |
| breadcrumb | BreadcrumbItem[] | 可选；覆盖自动推导 |

## 与现有系统关系

```text
Vue Router (fullPath, meta)
        │
        ├─► tabs store (opened, activeKey)
        │         └─► AdminTabBar
        │
        ├─► useAdminBreadcrumb ──► AdminMenu (ENTRIES)
        │         └─► AdminBreadcrumb
        │
        └─► router loading ──► nprogress / RouteLoadingBar
```

无 MySQL 表、无 Redis 键、无 `packages/contracts` Zod schema 变更。
