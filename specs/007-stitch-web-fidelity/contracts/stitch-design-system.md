# 学习端 Stitch 设计系统契约

**范围**: `apps/web` 全局视觉与壳层；非 REST API。

**基线**: Stitch 项目 `673993425689807844`（拾阶学社 UI 设计系统）。

## 色彩

| 角色 | Token | 值 |
|------|-------|-----|
| 页面背景 | `--paper` | `#faf8fe` |
| 次级背景 | `--paper-2` / `--card-2` | `#f4f3f8` / `#eeedf3` |
| 表面 | `--card` | `#ffffff` |
| 主色 | `--seal` | `#912132` |
| 主色深 | `--seal-deep` | `#b23a48` |
| 主色浅底 | `--seal-soft` | `#ffdadb` |
| 标题墨 | `--ink` | `#1a1b1f` |
| 正文墨 | `--ink-2` | `#574142` |
| 辅助墨 | `--ink-3` | `#8b7171` |
| 边框 | `--line` | `#e3e2e7` |
| 强调边框 | `--line-2` | `#debfc0` |

Element Plus 主色必须映射到 `--seal` 系列（已在 `style.css` `:root` 配置）。

## 字体

| 用途 | Token | 字体栈 |
|------|-------|--------|
| 品牌/大标题 | `--serif` | Noto Serif SC, Songti SC, serif |
| UI 正文 | `--sans` | Noto Sans SC, PingFang SC, sans-serif |
| 等宽/版权 | `--mono` | JetBrains Mono, monospace |

**规则**:
- 首页主标题、侧栏「拾阶目录」、课程详情主标题使用 `var(--serif)`。
- 导航、表单、表格使用 `var(--sans)`。

## 间距与容器

| 项 | 约定 |
|----|------|
| 内容最大宽度 | 与 `.masthead-inner` / `.foot-inner` 对齐，约 `1200px` |
| 页面水平 padding | `24px`（桌面） |
| 区块垂直间距 | 主区块 `40px`；列表内 `16px` |
| 圆角 | 默认 `--r`（8px）；大卡片/轮播 `12px` |
| 阴影 | 默认 `--shadow`；悬停卡片 `--shadow-lg` |

## 断点

| 名称 | 宽度 | 布局行为 |
|------|------|----------|
| `mobile` | ≤375px | 单列；导航折叠为菜单按钮 |
| `tablet` | 768px | 侧栏与主栏开始堆叠或缩小 |
| `desktop` | ≥1024px | Stitch 桌面参考布局 |
| `wide` | ≥1280px | spec 桌面验收基准 |

## 壳层契约

### 顶栏（`LearnerLayout`）

- 单行：`品牌 | 主导航 | 工具区（夜读/用户/登录）`
- 导航当前项：主色文字 + 底边或背景 `--seal-soft`
- 消息未读：`nav-badge` 角标

### 页脚（`SiteFooter`）

- 居中：品牌名 → 链接行 → 版权
- 不展示站点长文案 `site_intro.body_html`（保持简约）

## 禁止项

- 不得新增 npm 依赖（FR-008）。
- 不得用 `prompt`/`confirm`/`alert` 原生弹窗（项目 H2）。
- 不得用静态全站 hero 替代 `home.banners` 接口数据。
