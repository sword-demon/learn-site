# Research: 007-stitch-web-fidelity

## R1: 视觉基线来源 — Stitch 项目与屏幕映射

**Decision**: 以 Stitch 项目 `拾阶学社 UI 设计系统`（项目 ID `673993425689807844`）为唯一视觉基线；通过 Stitch MCP `list_screens` / `get_screen` 拉取 HTML 参考，按路由映射到 `apps/web` 已有视图；规格范围以 **13 个桌面端**参考屏幕为主，375px 移动端按同一 token 与组件规则压缩为单列，不单独拉取 Stitch 移动屏幕。

**Rationale**:
- 规格假设已明确 Stitch 为唯一基线；学习端路由与业务已稳定，视觉对齐应「按页面对照」而非重造路由。
- 桌面参考已覆盖首页、课程详情、学习页、个人中心、地图、结算、登录等主路径；移动端验收以 spec 边界场景（375px）与现有响应式断点为准。

**Alternatives considered**:

| 方案 | 弃用原因 |
|------|----------|
| 仅截图手工临摹 | 无结构化 HTML/CSS 参考，间距与字体易漂移 |
| 引入 Figma Tokens 插件 | 规格禁止新依赖；Stitch 已提供可解析 HTML |
| 全站重写为 Tailwind-only | 违反 FR-006/FR-008；Element Plus 控件须保留 |

---

## R2: 设计 token 落地 — 全局 CSS 变量 + Element Plus 主题桥接

**Decision**:
- 在 `apps/web/src/style.css` 定义 Stitch 浅色主题 token：`--paper`（宣纸白 `#faf8fe`）、`--seal`（印章红 `#912132`）、`--serif`/`--sans`（Noto Serif SC / Noto Sans SC）、边框与阴影层级。
- 通过 `:root` 将 token 映射到 `--el-color-primary` 等 Element Plus CSS 变量，避免大面积 `!important` 覆盖组件。
- 页面级布局间距优先使用现有 utility（`.page`、`.home-grid`、`.masthead`）与 scoped 样式扩展，不新增 UI 框架。

**Rationale**:
- 宪章要求 Element Plus 负责交互、Tailwind/原子类负责布局；全局 token 一次定义可多页复用，满足 FR-002/FR-008。
- 仓库已有 `--seal`、`--paper` 等变量与 night 模式；延续同一机制降低回归风险。

**Alternatives considered**:

| 方案 | 弃用原因 |
|------|----------|
| 每页独立色值 | 违反 FR-008，night 模式难维护 |
| CSS-in-JS | 与项目 Vue SFC + style.css 惯例不一致 |
| 替换 Element Plus 主题包 | 增加依赖与构建复杂度 |

---

## R3: 组件与页面改动策略 — 视图内布局 + 共享壳层

**Decision**:
- **壳层**：`LearnerLayout.vue`（顶栏品牌+导航+工具区单行）、`SiteFooter.vue`（居中简约页脚）对齐 Stitch 全局导航/页脚。
- **页面**：按 spec 用户故事逐视图调整模板结构与 scoped CSS，保留 `data-testid`、`data-action` 等测试钩子。
- **数据**：轮播使用 `GET /home` 的 `banners`（`HomeBannerCarousel`）；地图/课程封面无图时使用 `public/assets/stitch-*.jpg` 仅作地图卡片 fallback，首页主视觉不得用静态图替代接口轮播。
- **不新增** Pinia store、API 端点或 npm 依赖。

**Rationale**:
- FR-001/FR-008 要求业务能力与状态管理不变；壳层统一后各页只需关心内容区。
- 部分页面已在实现分支中调整，计划阶段记录为「增量对齐 + 视觉走查收尾」。

**Alternatives considered**:

| 方案 | 弃用原因 |
|------|----------|
| 新建 `StitchPage` 包装组件包裹所有页 | 过度抽象，每页布局差异大 |
| 复制 Stitch HTML 为静态页 | 无法绑定现有数据与路由 |
| 管理端同步改 Stitch | 超出 spec 范围 |

---

## R4: 响应式与可访问性

**Decision**:
- 断点验收：375px（移动）、768px（平板过渡）、1024px+（桌面侧栏/双栏）；与 spec 边界场景一致。
- 学习页桌面保持三栏（目录 / 内容 / 辅助）；≤768px 目录折叠或底部抽屉，沿用现有 `LessonView` 响应逻辑并仅调整样式。
- 保留 `aria-label`、焦点环、`router-link` 语义；`el-button`/`el-tree` 等不改为原生按钮除非 Stitch 强制且测试同步更新。

**Rationale**:
- FR-004/FR-006 与 SC-001/SC-003 要求窄屏可用且不破坏 a11y 测试。

**Alternatives considered**:

| 方案 | 弃用原因 |
|------|----------|
| 仅验收 1280px 桌面 | 违反 spec 375px 验收 |
| 移动端单独设计稿 | spec 假设以 Stitch 桌面为主 extrapolate |

---

## R5: 测试与验收策略

**Decision**:
- 延续 Vitest + `@vue/test-utils`：更新断言文本/类名/结构，不降低覆盖率。
- 视觉验收：Stitch 参考 HTML 存于 `specs/007-stitch-web-fidelity/reference/`（可选归档）；人工对比 7 个主页面首屏截图。
- 门禁：`make test-web`（Prettier、ESLint、tsc、Vitest、生产构建）必须通过。

**Rationale**:
- 宪章 V 要求前端全门禁；纯视觉变更仍需防止测试钩子断裂（H4 测试与功能同 commit）。

**Alternatives considered**:

| 方案 | 弃用原因 |
|------|----------|
| Percy/Chromatic 截图 CI | 未在项目中配置，首版不引入 |
| 仅人工走查 | 无法防回归 |

---

## R6: 范围外与已知依赖

**Decision**: 不修改 `apps/api`、`apps/admin`、`packages/contracts`（除非测试文案/类名连带）；Push/WebSocket、banner 业务逻辑不在本特性范围，但若视觉改动触及 `notifications` 或 `home` store 仅允许展示层调整。

**Rationale**: spec 假设明确排除 API/DB/权限变更。
