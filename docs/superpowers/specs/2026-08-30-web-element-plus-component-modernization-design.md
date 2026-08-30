# 学习端 Element Plus 组件化改造设计

## 目标

在不改变学习端信息架构、业务行为和“拾阶学社”新中式纸墨视觉的前提下，将 `apps/web` 中具备明确组件库对应物的原生交互控件迁移到 Element Plus，并用 Tailwind CSS 统一布局、间距和响应式规则。

本次改造解决的是交互组件实现不一致，不是重新设计页面。现有高保真设计 `designs/learn-portal/index.html` 是视觉基线。

## 当前事实

- 学习端使用 Vue 3、Element Plus 2.14、Tailwind CSS 3 和 TypeScript strict。
- Element Plus 已全局安装，但当前主要只用于登录和注册页。
- `apps/web/src` 当前包含 72 个原生 `button`、4 个 `input`、4 个 `textarea`、1 个 `dialog` 和 1 组 `details/summary`，分布在 24 个 Vue 组件中。
- 现有 `style.css` 已定义纸、墨、朱砂、黛青、苔绿和金色等语义变量，并包含日间与夜间主题。
- 当前工作区存在与登录、课程详情、课节学习和问答相关的未提交改动；实施必须在这些改动上增量修改，不得覆盖或回退。

## 范围边界

### 必须组件化的交互控件

源码模板中以下原生交互标签应迁移到 Element Plus，除非下方白名单明确允许：

| 当前实现 | 目标组件 |
| --- | --- |
| `input`、`textarea` | `el-input` |
| `input[type=checkbox]` | `el-switch` 或语义更合适的 `el-checkbox` |
| 普通操作、提交、重试和翻页 `button` | `el-button` |
| 原生 `dialog` | `el-dialog` |
| `details/summary` | `el-collapse` / `el-collapse-item` |
| 星级输入 | `el-rate` |
| 前后页按钮组 | `el-pagination` |
| 状态筛选按钮组 | `el-segmented` |
| 分类树 | `el-tree`，通过节点插槽保留数量和选中态 |
| 持久错误和提示 | `el-alert` |
| 空状态 | `el-empty` |
| 首屏或区块加载 | `el-skeleton` |
| 状态标签 | `el-tag`，仅用于确实表达状态的标签 |

### 保留原生语义的内容

- `header`、`main`、`nav`、`section`、`article`、`aside`、`footer` 等页面语义标签。
- 标题、段落、列表、时间和富文本内容标签。
- 原生 `video`、`canvas`、`img` 等媒体承载标签。
- `router-link` 和真正执行导航的链接。
- 课程列表行、地图步骤、课节正文等业务内容结构；不得为了“全部 Element Plus”强行改成卡片或后台表格。

完成后，Vue 源码模板不再直接出现 `button`、`input`、`select`、`textarea`、`dialog`、`details` 和 `summary`。Element Plus 最终渲染为原生 HTML 属于正常实现，不在静态审计范围内。

## 组件与样式架构

### Element Plus 使用方式

页面直接使用 Element Plus 组件，不新增 `AppButton`、`AppInput` 一类只做透传的包装组件。只有当多个页面出现相同的业务组合和状态协议时，才提取业务组件。

学习端新增 `@element-plus/icons-vue` 依赖，图标按需在组件内导入。菜单、日夜主题、收藏、分享、关闭、前后翻页和状态操作使用同一图标集，不继续使用字符图标或 emoji 作为操作图标。

### 主题变量

现有 CSS variables 继续作为视觉真相源：

- 朱砂 `--seal`：主要操作、危险确认和当前焦点强调。
- 黛青 `--indigo`：链接、信息和辅助交互。
- 苔绿 `--moss`：免费、成功和已完成状态。
- 金色 `--gold`：评分、试看和价格提示。
- `--paper`、`--card`、`--ink`、`--line`：页面、表面、文字和边框。

在根主题和夜间主题中统一补全 Element Plus 的 primary、success、warning、danger、info、fill、mask、border、text 和 disabled 变量。主要按钮默认使用朱砂色，不再由各页面分别覆盖 `.el-button--primary`。

### Tailwind CSS 职责

`tailwind.config.js` 映射上述 CSS variables，形成 `paper`、`card`、`ink`、`seal`、`indigo`、`moss`、`gold` 等语义颜色，并映射现有圆角和阴影。

Tailwind 负责：

- flex、grid、gap、对齐和换行；
- 宽度、最大宽度、内外边距；
- 375 px、768 px、1024 px 和 1440 px 响应式布局；
- 少量 hover、focus-visible 和 reduced-motion 状态。

`style.css` 和 scoped CSS 继续负责纸张纹理、印章、封面、课程目录线条、富文本、视频和 PDF 等独特视觉。不得用大量 `@apply` 重建另一套组件库，也不得为了使用 Tailwind 把稳定的艺术样式机械拆成冗长 utilities。

## 迁移顺序

### 阶段一：基础层

- 添加 Element Plus 图标依赖。
- 完善日间和夜间 Element Plus 主题变量。
- 扩展 Tailwind 语义 tokens。
- 建立原生交互标签静态审计及明确白名单。

### 阶段二：表单层

- 账户资料：`el-form`、`el-input`、`el-switch`、`el-button`。
- 评价：`el-form`、`el-rate`、`el-input type="textarea"`、`el-button`。
- 问答：`el-form`、`el-input`、`el-segmented`、`el-button`。
- 登录与注册：保留现有 Element Plus 表单，补齐验证码刷新按钮、错误提示和统一布局。

### 阶段三：浮层与复合控件

- 访问门禁迁移为受控 `el-dialog`。
- 分享海报迁移为 `el-dialog` 和标准按钮。
- 评价回复折叠迁移为 `el-collapse`。
- 首页分类迁移为 `el-tree`。
- 评价列表分页迁移为 `el-pagination`。

### 阶段四：普通操作与状态

- 重试、收藏、分享、加入地图、继续学习、购买、上一节、下一节、完成课节等操作迁移为 `el-button`。
- 适合图标表达的操作补充 Element Plus 图标和可访问名称。
- 统一 loading、disabled、active、focus 和 danger 状态。

### 阶段五：样式清理

- 删除已无调用方的 `.btn`、原生输入、原生弹窗、原生分页和原生折叠样式。
- 合并重复的 Element Plus 局部覆盖，仅保留组件变体和业务视觉需要的规则。
- 保留高保真设计所需的页面结构和艺术样式。

## 行为与数据边界

- 不修改 API、DTO、Pinia store、路由、令牌刷新、课程访问权、订单和学习进度规则。
- 原有 `v-model`、emit、点击事件和提交事件保持同一行为。
- 异步操作使用 Element Plus 的 `loading` 和 `disabled`，防止重复提交。
- `el-dialog` 使用显式布尔状态控制，关闭、Esc 和遮罩行为必须与现有门禁规则一致。
- 重要错误使用页面内 `el-alert` 持久展示；成功消息可以使用 `ElMessage`，但不得用瞬时消息替代必须阅读的失败原因。
- 分类树、分页、折叠和筛选迁移后必须保持原查询参数、选中项和加载调用次数。

## 可访问性

- 图标按钮必须提供可读的 `aria-label` 和 tooltip；带文字按钮不重复添加无意义 tooltip。
- 保持标题层级、landmark 和导航链接语义。
- 所有交互均可通过键盘完成，焦点顺序与视觉顺序一致。
- 日间和夜间主题正文文字对比度至少达到 WCAG AA。
- 动效遵守 `prefers-reduced-motion`，不得用缩放 hover 导致布局跳动。
- 移动端触控目标不小于 40 x 40 px，组件文字不得溢出或遮挡。

## 测试策略

### 静态审计

新增可重复执行的审计，扫描 `apps/web/src/**/*.vue` 的 template 源码。测试先证明当前原生交互标签会失败，迁移完成后通过。审计只检查源码标签，不检查 Element Plus 渲染后的 DOM。

### 组件与流程测试

- 使用 Vue Test Utils 和 Vitest 更新受影响测试，不通过 mock Element Plus 内部 DOM 来证明业务行为。
- 覆盖表单值绑定、提交、loading、disabled、错误提示、Dialog 开关、Tree 选中、Segmented 筛选、Collapse 展开和 Pagination 翻页。
- 保留现有 E2E 通过 role 和可访问名称定位的方式，避免依赖 Element Plus 私有 class。
- 当前未提交的登录错误、课程解锁、课节路由重载和问答筛选行为必须继续通过原有测试。

### 完整验证

- Web Vitest 全量测试。
- ESLint、TypeScript typecheck 和生产构建。
- `docker compose config --quiet`。
- `make rebuild-web`，确保运行服务使用新镜像。
- 浏览器验证首页、登录、注册、课程详情、购买确认、课节学习、问答、学习地图、我的学习、收藏、订单、消息和账户页。
- 桌面、平板和 375 px 移动端均检查日间与夜间主题。
- 与 `designs/learn-portal/index.html` 及改造前截图比较文案、布局、字体、颜色、间距、图标、控件状态和响应式行为。

## 验收标准

- Vue 源码模板中不存在未列入白名单的原生交互标签。
- 有明确 Element Plus 对应物的交互均使用对应组件，不以自定义 CSS 模拟同类组件。
- 页面信息架构、文案、API 调用和业务流程不变。
- 日间、夜间和各目标视口无横向滚动、重叠、裁切或不可读控件。
- 全量自动化验证通过，浏览器控制台无新增错误。
- 与现有高保真设计相比没有未说明的重大视觉偏差。

## 非目标

- 不重新设计品牌、导航、课程结构或页面信息架构。
- 不把学习端改成 Element Plus 默认后台风格。
- 不引入新的表单框架、CSS-in-JS、自动导入插件或另一套组件库。
- 不修改管理端、API 或数据库实现。
- 不在本次改造中处理与组件迁移无关的业务需求或重构。
