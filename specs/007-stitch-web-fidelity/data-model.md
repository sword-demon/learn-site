# Data Model: 007-stitch-web-fidelity

本特性**不引入数据库表或 API 实体变更**。下列为 UI/设计域概念，用于规划实现与验收，对应 spec「关键实体」。

## 设计 token（DesignToken）

全局视觉常量，定义于 `apps/web/src/style.css` `:root`。

| 名称 | CSS 变量 | 典型值 | 用途 |
|------|----------|--------|------|
| 宣纸背景 | `--paper` | `#faf8fe` | 页面底色 |
| 卡片表面 | `--card` | `#ffffff` | 面板、卡片 |
| 主色（印章红） | `--seal` | `#912132` | 链接、强调、主按钮 |
| 主色浅底 | `--seal-soft` | `#ffdadb` | 导航选中、树节点高亮 |
| 正文墨 | `--ink` / `--ink-2` / `--ink-3` | 灰阶 | 标题/正文/辅助文字 |
| 边框 | `--line` / `--line-2` | 浅灰/淡红 | 分隔线、侧栏边 |
| 衬线标题 | `--serif` | Noto Serif SC | 品牌、页面主标题 |
| 无衬线正文 | `--sans` | Noto Sans SC | UI 正文、表单 |
| 圆角 | `--r` | `8px`（局部 `12px` 用于大卡片） | 容器圆角 |
| 阴影 | `--shadow` / `--shadow-lg` | 低透明度 | 卡片悬停 |

**校验规则**:
- 新样式必须引用 token，禁止页面内硬编码 `#912132` 等（允许 stitch 资源图路径例外）。
- Night 模式通过 `.night` 或现有 `useTheme` 覆盖 token，不得单独写死暗色 hex 散落各页。

## 参考屏幕（ReferenceScreen）

Stitch 中一个可导出的 HTML 屏幕，映射到一个或多个学习端路由。

| Stitch 屏幕（示例标题） | 学习端路由 | 主要 Vue 文件 |
|-------------------------|------------|---------------|
| 首页（桌面） | `/` | `HomeView.vue`, `HomeBannerCarousel.vue` |
| 课程详情 | `/courses/:id` | `CourseDetailView.vue` |
| 课节学习 | `/learn/:courseId/:lessonId` | `LessonView.vue` |
| 个人中心 | `/me/*` | `StudentCenterView.vue` |
| 学习地图列表 | `/maps` | `MapListView.vue` |
| 学习地图详情 | `/maps/:id` | `MapDetailView.vue` |
| 结算 | `/checkout/:courseId` | `CheckoutView.vue` |
| 登录/注册 | `/login`, `/register` | `LoginRegisterView.vue` |

**关系**: 一个 `ReferenceScreen` 可定义多个 **布局区域**；实现时以 HTML 层级（header / sidebar / main / footer）对照，不替换业务字段。

## 页面表面（PageSurface）

一个路由在某一断点下的完整 UI 状态集合。

| 状态 | 说明 | 必须稳定 |
|------|------|----------|
| `loading` | 骨架屏或 `el-skeleton` | 导航与页脚仍可见 |
| `ready` | 数据已加载 | 符合 Stitch 层级 |
| `empty` | 列表/轮播无数据 | `EmptyState` / `el-empty`，无空白块 |
| `error` | 接口失败 | `el-alert`，可重试或提示 |
| `night` | 夜读模式 | 对比度可读（spec 边界） |

**校验规则**（来自 FR-007）:
- 图片 `onerror` 或 `v-if` fallback 不得导致主布局坍塌。
- 长文本在容器内 `truncate` 或换行，不遮挡价格/CTA。

## 布局区域（LayoutRegion）

页面内可复用的结构块，跨多页共享。

| 区域 ID | 组件/位置 | Stitch 对齐要点 |
|---------|-----------|-----------------|
| `masthead` | `LearnerLayout` 顶栏 | 品牌 seal + 单行导航 + 工具区 |
| `site-footer` | `SiteFooter` | 居中品牌、链接、版权 |
| `home-hero` | `HomeBannerCarousel` | 接口 `banners` 轮播，非静态占位 |
| `home-sidebar` | `HomeView` 分类树 | 拾阶目录侧栏 sticky |
| `course-hero` | `CourseDetailView` | 封面+价格面板 |
| `lesson-shell` | `LessonView` | 三栏/移动单列 |
| `student-tabs` | `StudentCenterView` | URL 派生 tab + 侧栏 |

## 与后端实体关系

| 后端实体 | 页面呈现 | 本特性变更 |
|----------|----------|------------|
| `HomePayload.banners` | 首页轮播 | 仅展示样式 |
| `CourseListItemDTO` | 首页/列表行 | 仅 `CourseEntryRow` 样式 |
| `PublicCourseDetailDTO` | 课程详情 | 布局/样式 |
| `LearnerMap*` | 地图列表/详情 | 卡片与时间轴样式 |
| 订单/登录表单 | 结算/登录 | 表单与反馈样式 |

无新增外键、迁移或契约字段。
