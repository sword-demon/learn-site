# 学习端 Element Plus 组件化改造实施计划

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 在保持“拾阶学社”现有高保真视觉和全部业务行为不变的前提下，将学习端标准交互控件迁移到 Element Plus，并用 Tailwind CSS 统一布局与响应式规则。

**Architecture:** Vue 模板直接使用 Element Plus，不增加透传型 UI 包装层。现有 CSS variables 继续作为主题真相源，Element Plus 负责交互组件，Tailwind 负责布局，独特的纸墨视觉继续由全局或 scoped CSS 承担。

**Tech Stack:** Vue 3、TypeScript strict、Element Plus 2.14、`@element-plus/icons-vue` 2.3、Tailwind CSS 3、Vue Test Utils、Vitest、Playwright、Docker Compose。

**Spec:** `docs/superpowers/specs/2026-08-30-web-element-plus-component-modernization-design.md`

## Global Constraints

- 视觉基线固定为 `designs/learn-portal/index.html`，不得改成 Element Plus 默认后台风格。
- 不修改 API、DTO、Pinia、路由、令牌、授权、订单和学习进度规则。
- 保留当前工作区已有未提交改动，不覆盖、不回退、不顺带重构。
- Vue 源码模板最终不得直接出现 `button`、`input`、`select`、`textarea`、`dialog`、`details` 和 `summary`。
- 保留 `header`、`main`、`nav`、`section`、`article`、`aside`、`footer`、`video`、`canvas`、`img`、`router-link` 和真实导航链接。
- 保留现有中文文案、`data-action`、可访问名称和 E2E role 定位契约。
- 不执行 `git add`、`git commit`、`git push` 或分支操作。
- 每次修改学习端源码后，最终必须执行 `make rebuild-web`。

---

## File Map

- `apps/web/tests/ElementPlusControlsAudit.test.ts`: 按迁移批次审计 Vue 源码中的禁用原生交互标签。
- `apps/web/tests/ElementPlusFoundation.test.ts`: 锁定图标依赖、Tailwind tokens 和 Element Plus 主题变量。
- `apps/web/tailwind.config.js`: 暴露纸墨语义颜色、圆角、阴影和响应式布局 tokens。
- `apps/web/src/style.css`: 统一 Element Plus 日间/夜间主题及共享组件变体，删除失效的原生控件样式。
- `apps/web/src/layouts/LearnerLayout.vue`: 顶部菜单、主题切换和退出按钮组件化。
- `apps/web/src/views/auth/*.vue`, `apps/web/src/views/me/AccountView.vue`: 表单控件组件化。
- `apps/web/src/views/catalog/Review*.vue`, `apps/web/src/views/learn/QuestionPanel.vue`: 评价、回复、问答和筛选组件化。
- `apps/web/src/views/catalog/*.vue`, `apps/web/src/views/checkout/CheckoutView.vue`, `apps/web/src/components/SharePosterDialog.vue`: 课程、授权、分享和购买控件组件化。
- `apps/web/src/views/home/*.vue`, `apps/web/src/views/maps/*.vue`: 分类树和学习地图控件组件化。
- `apps/web/src/views/learn/LessonView.vue`, `apps/web/src/views/me/*.vue`, `apps/web/src/components/PdfViewer.vue`: 学习与个人中心操作组件化。
- `pnpm-lock.yaml`, `apps/web/package.json`: 新增学习端 Element Plus 图标依赖。

---

### Task 1: 建立组件审计和主题基础

**Files:**
- Create: `apps/web/tests/ElementPlusControlsAudit.test.ts`
- Create: `apps/web/tests/ElementPlusFoundation.test.ts`
- Modify: `apps/web/package.json`
- Modify: `pnpm-lock.yaml`
- Modify: `apps/web/tailwind.config.js`
- Modify: `apps/web/src/style.css`

**Interfaces:**
- Produces: `controlGroups: Record<string, string[]>`，供后续任务按组运行静态审计。
- Produces: Tailwind `paper/card/ink/seal/indigo/moss/gold` tokens 和统一 `--el-*` 主题变量。

- [ ] **Step 1: 写入会失败的基础测试和分组审计**

`ElementPlusControlsAudit.test.ts` 使用以下核心逻辑剥离 script/style 后扫描模板：

```ts
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { describe, expect, it } from 'vitest';

const root = fileURLToPath(new URL('../src/', import.meta.url));
const forbidden = /<(button|input|select|textarea|dialog|details|summary)\b/g;
const controlGroups: Record<string, string[]> = {
  'shell and forms': [
    'layouts/LearnerLayout.vue',
    'views/auth/LoginView.vue',
    'views/auth/RegisterView.vue',
    'views/me/AccountView.vue',
    'components/PdfViewer.vue',
  ],
  'reviews and questions': [
    'views/catalog/ReviewTree.vue',
    'views/catalog/ReviewReplyBranch.vue',
    'views/learn/QuestionPanel.vue',
  ],
  'catalog and sharing': [
    'views/catalog/AccessGate.vue',
    'views/catalog/CategoryView.vue',
    'views/catalog/CourseDetailView.vue',
    'views/catalog/CourseOutline.vue',
    'views/catalog/ShareBar.vue',
    'views/checkout/CheckoutView.vue',
    'components/SharePosterDialog.vue',
  ],
  'discovery and maps': [
    'views/home/HomeView.vue',
    'views/home/CourseShelfCard.vue',
    'components/CourseEntryRow.vue',
    'views/maps/MapListView.vue',
    'views/maps/MapDetailView.vue',
  ],
  'learning and personal': [
    'views/learn/LessonView.vue',
    'views/me/MyLearningView.vue',
    'views/me/FavoritesView.vue',
    'views/me/MyOrdersView.vue',
    'views/me/MessagesView.vue',
  ],
};

function violations(relativePath: string): string[] {
  const source = readFileSync(`${root}${relativePath}`, 'utf8')
    .replace(/<script\b[\s\S]*?<\/script>/g, '')
    .replace(/<style\b[\s\S]*?<\/style>/g, '');
  return [...source.matchAll(forbidden)].map((match) => `${relativePath}: <${match[1]}>`);
}

describe('Element Plus control migration', () => {
  it.each(Object.entries(controlGroups))('%s has no native interactive controls', (_, files) => {
    expect(files.flatMap(violations)).toEqual([]);
  });
});
```

`ElementPlusFoundation.test.ts` 读取 package、Tailwind 配置和 CSS，断言图标依赖存在、`seal` 映射存在、`--el-color-primary: var(--seal)` 以及夜间 fill/mask 变量存在。

- [ ] **Step 2: 运行测试并确认失败原因正确**

Run:

```bash
pnpm --filter @learn-site/web test -- ElementPlusFoundation.test.ts ElementPlusControlsAudit.test.ts
```

Expected: foundation 因图标依赖和主题变量缺失失败；五个 control groups 因当前原生标签失败。

- [ ] **Step 3: 添加图标依赖并更新 lockfile**

Run:

```bash
pnpm --filter @learn-site/web add @element-plus/icons-vue@^2.3.2
```

- [ ] **Step 4: 配置 Tailwind 语义 tokens**

将 `tailwind.config.js` 的 `theme.extend` 设置为：

```js
extend: {
  colors: {
    paper: 'var(--paper)',
    card: 'var(--card)',
    ink: 'var(--ink)',
    muted: 'var(--ink-2)',
    seal: 'var(--seal)',
    indigo: 'var(--indigo)',
    moss: 'var(--moss)',
    gold: 'var(--gold)',
    line: 'var(--line)',
  },
  borderRadius: { paper: 'var(--r)' },
  boxShadow: { paper: 'var(--shadow)', elevated: 'var(--shadow-lg)' },
},
```

- [ ] **Step 5: 完成统一 Element Plus 主题**

在 `:root` 和 `html[data-theme='night']` 中补齐 primary/success/warning/danger/info、fill、mask、disabled 和 border 变量；全局 primary 使用 `var(--seal)`。将 auth 页局部 primary 覆盖删除，保留尺寸和验证码布局。

- [ ] **Step 6: 运行基础测试至通过**

Run:

```bash
pnpm --filter @learn-site/web test -- ElementPlusFoundation.test.ts
```

Expected: PASS。组件审计继续保持红色，供 Task 2-6 逐组关闭。

---

### Task 2: 迁移应用壳、登录注册和账户表单

**Files:**
- Modify: `apps/web/src/layouts/LearnerLayout.vue`
- Modify: `apps/web/src/views/auth/LoginView.vue`
- Modify: `apps/web/src/views/auth/RegisterView.vue`
- Modify: `apps/web/src/views/me/AccountView.vue`
- Modify: `apps/web/src/components/PdfViewer.vue`
- Create: `apps/web/tests/AccountView.test.ts`
- Modify: `apps/web/src/style.css`

**Interfaces:**
- Consumes: Task 1 主题 tokens 和图标依赖。
- Preserves: `onLogout()`、`loadCaptcha()`、`onSubmit()`、`save()`、`PdfViewer` 的 `open` emit。

- [ ] **Step 1: 写账户表单行为测试并运行 shell/forms 审计为红**

`AccountView.test.ts` mock `fetchProfile`/`updateProfile`，挂载后断言手机号禁用、昵称可更新、公开开关可切换，点击“保存资料”后仍提交 `{ nickname, show_on_course }`。

Run:

```bash
pnpm --filter @learn-site/web test -- AccountView.test.ts ElementPlusControlsAudit.test.ts -t "shell and forms|AccountView"
```

Expected: audit 因原生按钮和输入失败；账户行为测试在组件迁移前记录现有契约。

- [ ] **Step 2: 迁移 LearnerLayout 和 PdfViewer**

- 菜单、日夜主题、退出和 PDF 操作改为 `el-button`。
- 使用 `Menu`、`Moon`、`Sunny`、`SwitchButton`、`Document` 图标。
- 保留 `aria-expanded`、`aria-controls`、tooltip、路由链接和原点击行为。

- [ ] **Step 3: 迁移登录、注册和账户表单**

- 验证码刷新改为包含图片的 `el-button`，保留图片 alt 和 loading/disabled。
- 错误提示改为可持久阅读的 `el-alert`。
- 账户页改为 `el-form`、`el-form-item`、`el-input`、`el-switch` 和 loading `el-button`。
- 用 Tailwind utilities 处理表单行、按钮宽度和移动端堆叠。

- [ ] **Step 4: 运行目标测试和审计至通过**

Run:

```bash
pnpm --filter @learn-site/web test -- AccountView.test.ts AuthSession.test.ts ElementPlusControlsAudit.test.ts -t "shell and forms|AccountView|auth"
```

Expected: shell/forms 审计 PASS；登录错误保留、账户保存和 PDF emit 行为 PASS。

---

### Task 3: 迁移评价、回复和课节问答

**Files:**
- Modify: `apps/web/src/views/catalog/ReviewTree.vue`
- Modify: `apps/web/src/views/catalog/ReviewReplyBranch.vue`
- Modify: `apps/web/src/views/learn/QuestionPanel.vue`
- Create: `apps/web/tests/ReviewTreeView.test.ts`
- Create: `apps/web/tests/QuestionPanel.test.ts`
- Modify: `apps/web/src/style.css`

**Interfaces:**
- Preserves: 评价新增/编辑/删除、回复、分页、问题筛选、提问、打开线程和追问 API 调用次数。
- Produces: `el-rate` 评分、`el-collapse` 回复树、`el-segmented` 问题状态筛选。

- [ ] **Step 1: 写评价和问答行为测试**

- `ReviewTreeView.test.ts` mock 评价 API，断言 `el-rate` 更新 rating、textarea 提交正文、删除确认和 `el-pagination` 只触发一次目标页加载。
- `QuestionPanel.test.ts` mock 问答 API，断言 segmented 切换 `pending` 后只加载一次、提问和追问仍提交原 payload。

Run:

```bash
pnpm --filter @learn-site/web test -- ReviewTreeView.test.ts QuestionPanel.test.ts ElementPlusControlsAudit.test.ts -t "reviews and questions|ReviewTree|QuestionPanel"
```

Expected: 新测试或审计因原生控件失败。

- [ ] **Step 2: 迁移 ReviewTree 和 ReviewReplyBranch**

- 表单使用 `el-form`、`el-rate`、textarea `el-input` 和 loading `el-button`。
- 回复分支使用 `el-collapse`，保留递归结构、回复数量和展开状态。
- 分页改为 `el-pagination`，通过 `current-change` 调用现有 `loadList(page)`。
- 删除操作使用 danger button 和页面内确认区，不改变当前不可恢复提示。

- [ ] **Step 3: 迁移 QuestionPanel**

- 状态筛选改为 `el-segmented`，使用当前 `statusOptions` 数据。
- 提问标题和正文改为 `el-input`；追问改为 textarea `el-input`。
- 所有提交与线程按钮改为 `el-button`，保留 `aria-pressed`、loading 和 data attributes。

- [ ] **Step 4: 运行目标测试和审计至通过**

Run:

```bash
pnpm --filter @learn-site/web test -- review-tree.spec.ts ReviewTreeView.test.ts QuestionApi.test.ts QuestionPanel.test.ts ElementPlusControlsAudit.test.ts -t "reviews and questions|review|question"
```

Expected: reviews/questions 审计和全部评价问答行为 PASS。

---

### Task 4: 迁移课程、授权、分享和购买控件

**Files:**
- Modify: `apps/web/src/views/catalog/AccessGate.vue`
- Modify: `apps/web/src/views/catalog/CategoryView.vue`
- Modify: `apps/web/src/views/catalog/CourseDetailView.vue`
- Modify: `apps/web/src/views/catalog/CourseOutline.vue`
- Modify: `apps/web/src/views/catalog/ShareBar.vue`
- Modify: `apps/web/src/views/checkout/CheckoutView.vue`
- Modify: `apps/web/src/components/SharePosterDialog.vue`
- Modify: `apps/web/tests/AccessGate.test.ts`
- Modify: `apps/web/tests/CourseDetailView.test.ts`
- Modify: `apps/web/tests/ShareBar.test.ts`
- Modify: `apps/web/src/style.css`

**Interfaces:**
- Preserves: `AccessGate` slot/`entitled` emit、免费加入、购买、课节导航、收藏、复制链接、生成海报和创建订单。
- Produces: 受控 `el-dialog`，Dialog state 为 `dialogVisible: Ref<boolean>`。

- [ ] **Step 1: 更新测试以锁定可访问名称和行为，而不是 `.btn-*` class**

将课程详情测试定位改为稳定的 `data-action` 或按钮文本；AccessGate 测试断言 Dialog 打开/关闭和 entitled emit；ShareBar 保留现有四个 API/路由测试。

Run:

```bash
pnpm --filter @learn-site/web test -- AccessGate.test.ts CourseDetailView.test.ts ShareBar.test.ts ElementPlusControlsAudit.test.ts -t "catalog and sharing|AccessGate|CourseDetailView|ShareBar"
```

Expected: audit 因原生 button/dialog 失败。

- [ ] **Step 2: 迁移 AccessGate 和 SharePosterDialog**

- 移除 `HTMLDialogElement.showModal()/close()`，改用 `v-model="dialogVisible"` 的 `el-dialog`。
- 关闭、登录、免费加入、购买、订单和保存海报均使用 `el-button`。
- 保留 Esc、遮罩关闭、busy、错误文本和 `entitled` emit。

- [ ] **Step 3: 迁移课程详情、目录、分类、分享和购买按钮**

- 课程页 tabs 使用 `el-tabs`/`el-tab-pane`，保留 `intro/catalog/reviews` keys。
- 课节目录按钮、免费加入、购买、收藏、复制、分享和订单动作使用 `el-button`。
- 评价/试看/免费/锁定状态使用 `el-tag`；翻页使用 `el-pagination`。
- 复制和生成过程使用 loading，错误继续页面内展示。

- [ ] **Step 4: 运行目标测试和审计至通过**

Run:

```bash
pnpm --filter @learn-site/web test -- AccessGate.test.ts CourseDetailView.test.ts CourseDetailPrivacy.test.ts ShareBar.test.ts OrderApi.test.ts ElementPlusControlsAudit.test.ts -t "catalog and sharing|AccessGate|CourseDetail|ShareBar|order"
```

Expected: catalog/sharing 审计和相关业务测试 PASS。

---

### Task 5: 迁移首页分类与学习地图

**Files:**
- Modify: `apps/web/src/views/home/HomeView.vue`
- Delete: `apps/web/src/views/home/CategoryBranch.vue`
- Modify: `apps/web/src/views/home/CourseShelfCard.vue`
- Modify: `apps/web/src/components/CourseEntryRow.vue`
- Modify: `apps/web/src/views/maps/MapListView.vue`
- Modify: `apps/web/src/views/maps/MapDetailView.vue`
- Create: `apps/web/tests/HomeView.test.ts`
- Modify: `apps/web/tests/MapListView.test.ts`
- Modify: `apps/web/tests/MapDetailView.test.ts`
- Modify: `apps/web/src/style.css`

**Interfaces:**
- Preserves: 首页默认全部分类、分类选中后课程加载、地图重试、加入地图和步骤操作。
- Produces: Home `el-tree`，`node-key="id"`，节点 label 读取 `name`，选中回调继续调用现有分类加载函数。

- [ ] **Step 1: 写 HomeView tree 行为测试并更新地图按钮定位**

`HomeView.test.ts` mock Pinia home store 和课程 API，断言默认分类、点击 tree 节点后的分类 id 与请求次数；地图测试继续通过 `data-action="retry"`、`data-action="start-map"` 定位。

Run:

```bash
pnpm --filter @learn-site/web test -- HomeView.test.ts MapListView.test.ts MapDetailView.test.ts ElementPlusControlsAudit.test.ts -t "discovery and maps|HomeView|Map"
```

Expected: audit 和 HomeView tree 断言失败。

- [ ] **Step 2: 用 el-tree 替换递归 CategoryBranch**

- HomeView 直接渲染 `el-tree`，通过 default slot 保留展开箭头、分类名和课程数量。
- 根“全部分类”作为独立 `el-button text` 或 tree 根节点，保持默认选中。
- 删除不再使用的 `CategoryBranch.vue` 及对应样式。

- [ ] **Step 3: 迁移课程条目和地图操作**

- 收藏、重试、开始地图、继续、购买和复习操作改为 `el-button`。
- 状态使用 `el-tag`，进度使用 `el-progress`。
- 保留课程与地图本身的 router-link/article 结构。

- [ ] **Step 4: 运行目标测试和审计至通过**

Run:

```bash
pnpm --filter @learn-site/web test -- HomeStore.test.ts HomeView.test.ts MapListView.test.ts MapDetailView.test.ts ElementPlusControlsAudit.test.ts -t "discovery and maps|home|Map"
```

Expected: discovery/maps 审计和首页地图行为 PASS。

---

### Task 6: 迁移课节学习和个人中心操作

**Files:**
- Modify: `apps/web/src/views/learn/LessonView.vue`
- Modify: `apps/web/src/views/me/MyLearningView.vue`
- Modify: `apps/web/src/views/me/FavoritesView.vue`
- Modify: `apps/web/src/views/me/MyOrdersView.vue`
- Modify: `apps/web/src/views/me/MessagesView.vue`
- Modify: `apps/web/tests/LessonView.test.ts`
- Modify: `apps/web/tests/MyLearningView.test.ts`
- Modify: `apps/web/tests/FavoritesView.test.ts`
- Modify: `apps/web/tests/MessagesView.test.ts`
- Modify: `apps/web/src/style.css`

**Interfaces:**
- Preserves: 上一节/下一节、标记完成、重新加入、取消收藏、消息已读和订单刷新行为。
- Consumes: Task 2-5 的统一 button/tag/alert/loading 样式。

- [ ] **Step 1: 更新行为测试定位并运行审计为红**

- LessonView 使用 `data-action="previous-lesson|next-lesson|complete-lesson"`。
- MyLearning 保留 `data-action="rejoin"`。
- Favorites 使用 `data-action="remove-favorite"`。
- Messages 保留 `data-read-id`。

Run:

```bash
pnpm --filter @learn-site/web test -- LessonView.test.ts MyLearningView.test.ts FavoritesView.test.ts MessagesView.test.ts ElementPlusControlsAudit.test.ts -t "learning and personal|LessonView|MyLearning|Favorites|Messages"
```

Expected: audit 因原生 button 失败。

- [ ] **Step 2: 迁移课节学习操作**

- 上一节、下一节和完成操作改为 `el-button`，使用 ArrowLeft/ArrowRight/Check 图标。
- 完成操作使用 loading/disabled，完成状态使用 success tag。
- 访问错误和完成失败使用 `el-alert`，保留当前课节重载 watch 行为。

- [ ] **Step 3: 迁移个人中心操作与状态**

- 重新加入、取消收藏、消息已读和订单刷新改为 `el-button`。
- 列表状态改为 `el-tag`，空列表改为 `el-empty`，加载改为 `el-skeleton`。
- 保留 router-link、订单数据和所有 API payload。

- [ ] **Step 4: 运行目标测试和审计至通过**

Run:

```bash
pnpm --filter @learn-site/web test -- LessonView.test.ts MyLearningView.test.ts FavoritesView.test.ts MessagesView.test.ts ElementPlusControlsAudit.test.ts -t "learning and personal|LessonView|MyLearning|Favorites|Messages"
```

Expected: learning/personal 审计和全部行为测试 PASS。

---

### Task 7: 清理样式并完成全量验收

**Files:**
- Modify: `apps/web/src/style.css`
- Modify: affected scoped styles under `apps/web/src/**/*.vue`
- Modify: `apps/web/tests/e2e/smoke.spec.ts` only if accessible labels changed while visible copy remains identical

**Interfaces:**
- Consumes: Tasks 1-6 的全部组件迁移。
- Produces: 通过静态审计、自动化测试和视觉验收的学习端镜像。

- [ ] **Step 1: 运行完整静态审计并清除遗漏**

Run:

```bash
pnpm --filter @learn-site/web test -- ElementPlusControlsAudit.test.ts
```

Expected: 五个分组全部 PASS。若发现标签，迁移对应控件；不得扩大白名单掩盖遗漏。

- [ ] **Step 2: 删除无调用方 CSS 并格式化**

- 用 `rg` 核对 `.btn`、原生 input/textarea/dialog/details/pagination 样式是否仍有调用方。
- 删除无调用方规则，保留纸张、印章、封面、富文本、媒体和业务布局规则。
- 仅对本计划实际修改的文件运行 `pnpm --filter @learn-site/web exec prettier --write <files...>`，避免格式化无关的用户改动。

- [ ] **Step 3: 运行学习端全量验证**

Run:

```bash
pnpm --filter @learn-site/web lint
pnpm --filter @learn-site/web typecheck
pnpm --filter @learn-site/web test
pnpm --filter @learn-site/web build
docker compose config --quiet
git diff --check
```

Expected: 全部命令退出码 0；Vite 大 chunk 警告可记录，但不得有编译、测试或 lint 失败。

- [ ] **Step 4: 重建运行镜像**

Run:

```bash
make rebuild-web
docker compose ps web
docker compose exec -T web wget -qO- http://127.0.0.1/healthz
```

Expected: web 容器 healthy，healthz 输出 `ok`。

- [ ] **Step 5: 浏览器视觉与交互验收**

- 在 1440 x 900、768 x 1024 和 375 x 812 检查首页、登录、注册、课程详情、购买确认、课节、问答、地图和个人中心。
- 检查日间和夜间主题、键盘焦点、loading、disabled、Dialog、Tree、Segmented、Collapse、Pagination。
- 与 `designs/learn-portal/index.html` 和改造前截图比较文案、结构、字体、颜色、间距、图标、响应式与首屏平衡。
- 读取浏览器 console，要求无新增 error。

- [ ] **Step 6: 最终工作区边界检查**

Run:

```bash
git status --short
git diff --stat
git diff --check
```

Expected: 只包含用户原有改动、设计/计划文档和本功能明确列出的学习端文件；不存在临时截图、调试日志或构建探针。
