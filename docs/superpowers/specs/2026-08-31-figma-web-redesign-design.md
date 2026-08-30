# 学习端 Figma 设计稿全覆盖重写设计

## 目标

依据 Figma 文件 `拾阶学社` (`https://www.figma.com/design/oqYbej5QX6Ks1xqZBKsSzw`)
中除移动端以外的全部 11 个桌面级页面，逐页重写 `apps/web` 现有实现，使前端视觉、
组件结构、信息架构与设计稿完全对齐。

本次重写只动前端，不调整后端 API；后端字段不足时用占位 / 合并现有字段凑合并
标注 `// ponytail: backend gap`，所有差异汇总到 `tasks/lessons.md`。

## 当前事实

- `apps/web` 是 Vue 3 + Vite + TS strict + Element Plus 2.8.4 + Tailwind + Pinia 单页应用。
- `packages/contracts` 已提供 `home.ts / banner.ts / index.ts` 等 Zod schema。
- 现有视图覆盖：HomeView、MapListView、MapDetailView、CourseDetailView、LessonView、
  CheckoutView、LoginView、RegisterView、MyLearningView、FavoritesView、MyOrdersView、
  MessagesView、CheckinListView、AccountView。
- 现有组件：HomeBannerCarousel、SiteFooter、VideoPlayer、PdfViewer、MarkdownRenderer、
  DailyCheckinDialog、SharePosterDialog、CourseEntryRow、CheckinPlanEditor、RouteLoadingBar。
- Figma 设计稿包含 14 个画板，其中 3 个为移动端（移动端-详情、Header、Main Content），
  本次不实现。桌面端 11 个画板全部纳入。
- 当前存在未提交改动（`M` 状态），本次在已有改动上增量修改，不覆盖或回退。
- 时区统一 `Asia/Shanghai`，所有日期字段经过 service 内的 `todayDate()/nowDatetime()`。
- 颜色主题（纸 / 墨 / 朱砂 / 黛青 / 苔绿 / 金）已在 `style.css` 定义。

## 范围边界

### 纳入：11 个桌面页面

| # | Figma 画板 | 重写目标 |
| --- | --- | --- |
| 1 | 首页 HomeView | `views/home/HomeView.vue` 整体重写 |
| 2 | 首页-空状态 HomeView EmptyState | HomeView 内部 `v-if="data.length === 0"` 分支 |
| 3 | 首页-加载中 HomeView Loading | HomeView 内部 `v-if="loading"` 分支 |
| 4 | 学习地图列表 MapListView | `views/maps/MapListView.vue` 整体重写 |
| 5 | 学习地图详情 MapDetailView | `views/maps/MapDetailView.vue` 整体重写 |
| 6 | 课程详情 CourseDetailView | `views/catalog/CourseDetailView.vue` 整体重写 |
| 7 | 学习页 LessonView | `views/learn/LessonView.vue` 整体重写 |
| 8 | 结算 CheckoutView | `views/checkout/CheckoutView.vue` 整体重写 |
| 9 | 登录/注册 LoginRegisterView | `views/auth/LoginView.vue` + `RegisterView.vue` 合并为单视图带 Tab 切换 |
| 10 | 学员中心 StudentCenterView | `views/me/*` 6 个文件合并为 1 个 `StudentCenterView.vue` 含 Tab 切换 |
| 11 | 我的收藏-空状态 FavoritesView EmptyState | 学员中心 Tab 内空状态分支 |

### 不纳入

- 移动端 3 个画板（用户明确排除）
- 后端 API / 数据库 / 迁移
- `apps/admin` 后台管理端
- `packages/contracts` 已有 schema 不重写，仅按需扩展

### 必须约束（项目硬规则）

- H1：路由参数取 id 一律走 `computed` + `Number.isFinite` 守卫
- H2：弹窗 / 确认框优先 `ElMessageBox`，不引自造 `.badge / .notice`
- H3：Service 层私有方法 + audit；store 仅薄包装
- H4：测试与功能同 commit
- H5：弹窗 / 抽屉状态走 composable

## 组件与样式架构

### 已有可复用（不重写）

`HomeBannerCarousel`、`SiteFooter`、`VideoPlayer`、`PdfViewer`、`MarkdownRenderer`、
`DailyCheckinDialog`、`SharePosterDialog`、`CourseEntryRow`、`CheckinPlanEditor`、
`RouteLoadingBar`、`Element Plus`、`Tailwind`、`unplugin-auto-import`。

### 新增组件（最小集，按需实现）

| 组件 | 路径 | 用途 |
| --- | --- | --- |
| `PageHeader` | `src/components/PageHeader.vue` | 学员中心 + 二级页顶栏；logo / 搜索 / 用户入口 / 导航 |
| `EmptyState` | `src/components/EmptyState.vue` | 统一空状态：图 + 文 + CTA |
| `SkeletonBlock` | `src/components/SkeletonBlock.vue` | 统一骨架屏 |
| `LearnerTabs` | `src/components/LearnerTabs.vue` | 学员中心 Tab 切换容器 |

不引 `AppButton / AppInput / AppCard` 等纯透传包装（H2 / YAGNI）。

### 样式策略

- 颜色 / 字号 / 间距从 Figma 节点直接读取，写入 Tailwind utility class。
- 现有 CSS variables（`--paper / --ink / --vermilion / --dark-cyan / --moss / --gold`）继续作为视觉真相源。
- Element Plus 主题色 `#409eff` 不变；如 Figma 需要主色微调，改 `:root` 变量而非组件 props。
- 响应式：仅桌面端；移动端本次不做。

### 状态管理

- 现有 `useHomeStore / useLearnerProfileStore / useNotificationsStore` 保留。
- 按页面新增：`useMapListStore / useMapDetailStore / useCourseDetailStore / useLessonStore / useCheckoutStore / useCenterStore`。
- Store 仅薄包装 API 调用 + 状态；业务规则（如"已购课程直接进入学习"）抽 service 私有方法。
- 跨页面状态（如 `loggedIn`）走 composable（`useLearnerSession`），与 `v-if` 同步。

## 数据契约

**重要前提**：`packages/contracts/src/` 已存在 27 个 DTO 文件、`apps/web/src/api/` 已存在 33+ `fetch*` 函数（详见 `apps/web/src/views/*` 调用映射）。本次重写**优先复用现有契约**，仅在以下场景扩展：

### 复用现有 DTO（不重写）

| Figma 页 | 现有 DTO |
|---|---|
| HomeView（含空/加载/有数据三态） | `HomePayload`（已有 categories / site_intro / recent_courses / banners） |
| MapListView / MapDetailView | `LearnerMapListDTO` / `LearnerMapDetailDTO`（含 enrollment + next_step） |
| CourseDetailView | `PublicCourseDetailDTO`（含 viewer_* 字段） |
| LessonView | `LessonDeliveryDTO`（discriminatedUnion：markdown / pdf / video）+ `LessonProgressDTO` |
| CheckoutView | `CreateOrderResponseDTO` + `PaymentEnvelopeDTO` + `OrderDTO` |
| LoginRegisterView | `LearnerLoginInput` + `CaptchaChallenge` + `TokenPair` |
| StudentCenterView | `MyLearningListDTO` + `FavoriteListDTO` + `OrderListDTO` + `LearnerNotificationListDTO` + `LearnerCheckinListDTO` + `LearnerProfileDTO` |
| FavoritesView EmptyState | `FavoriteListDTO`（items=[] 时 v-if 分支） |

### 扩展点（仅 1 处）

`packages/contracts/src/home.ts` 的 `HomePayload` 增加 `recommended_maps` 字段（首页 1:1464 设计要求"推荐学习地图"3 卡网格），沿用 `LearnerMapListDTO.items` 中的元素 shape（`MapSummaryDTO & { enrollment: MapEnrollmentDTO.nullable }`）。后端 `HomeController` 同步返回该字段。

**不新建任何 map/course/lesson/checkout/me DTO 文件**——这些字段已存在且被现有 fetch* 完整覆盖。

### 不需要新增 DTO 的"看似缺字段"场景

经排查，前端可通过以下方式从现有 DTO 派生/占位，无需新 DTO：

- StudentCenterView 的 `STREAK` / 热力图 → 从 `MyLearningListDTO.items[].updated_at` 派生连续天数 + 从 `LearnerCheckinListDTO.items[].checkin_date` 派生最近 28 天热力图
- 课程详情 `(45)` 评价数 → 走 `fetchCourseReviews` 第一页拿 `total`
- 登录注册合并视图 → 复用 `LearnerLoginInput` 与 `LearnerRegisterInput` 同 shape（同为 phone/password/captcha_id/captcha_answer），加 Tab 视觉切换

### 后端字段缺失（标记 ponytail，不阻塞）

详见 `tasks/lessons.md` 的"figma-gap"小节。前端用以下策略：
1. `// ponytail: backend gap: <字段名>` 注释在 store/view 数据加工处标注
2. 用现有字段派生或常量占位
3. 每页 commit 后追加到 `tasks/lessons.md`

## 后端字段缺失处理

按以下优先级处理（不阻塞前端实现）：

1. 先确认 Figma 是否真的需要该字段（如"剩余 X 天"）。
2. 后端无字段 → 前端用占位常量或合并现有字段计算。
3. 标注 `// ponytail: backend gap: <字段名> <Figma 节点>`。
4. 每页 commit 后汇总到 `tasks/lessons.md`。

## 测试策略

### 单元测试（vitest，必备）

每页至少 1 个 `*.test.ts`：

- 渲染测试：`mount` + 断言关键 DOM / 组件存在
- 数据流：store action 调用 → 状态切换
- 关键交互：按钮点击 → 跳转 / 弹窗
- 空 / 加载分支断言

### 集成测试（vitest 替代原 e2e）

按用户决策：不引入 Playwright 浏览器场景。所有功能性函数（store action、composable、util）
走 vitest 单测，覆盖业务规则和边界条件。

### 覆盖率

- 该页面相关文件 ≥ 80%（沿用全局硬规则）。
- 关键路径（结算 / 学员中心 / 学习页）100%。

### 质量门（每页 commit 前）

```bash
pnpm -F @learn-site/web typecheck
pnpm -F @learn-site/web lint
pnpm -F @learn-site/web test
pnpm -F @learn-site/web build
```

全部通过才能进入下一个 commit。

## 实施节奏（10 个 commit）

```
commit 1: 脚手架
  - PageHeader / EmptyState / SkeletonBlock / LearnerTabs 4 个新组件
  - contracts 扩展点（home / map / course / lesson / checkout / me）
  - 单元测试覆盖新组件

commit 2: 首页组
  - HomeView 整体重写（含空 / 加载分支）
  - HomeView.test.ts + HomeStore.test.ts
  - 占位字段汇总

commit 3: 学习地图列表
  - MapListView 重写 + store + 测试

commit 4: 学习地图详情
  - MapDetailView 重写 + store + 测试

commit 5: 课程详情
  - CourseDetailView 重写 + store + 测试

commit 6: 学习页
  - LessonView 重写 + store + 测试

commit 7: 结算
  - CheckoutView 重写 + store + 测试

commit 8: 登录注册（合并）
  - LoginRegisterView 单视图带 Tab 切换（路由 `/login` 与 `/register` 均指向此视图）
  - 保留 LoginView.vue / RegisterView.vue 作为薄 redirect 组件（仅重定向到 `/login`）
  - 防止旧深链失效

commit 9: 学员中心
  - StudentCenterView 整合 6 个 Tab
  - 删除 MyLearningView / FavoritesView / MyOrdersView / MessagesView / CheckinListView / AccountView
  - router 重定向旧路径到新视图

commit 10: 教训汇总
  - tasks/lessons.md 追加所有 `// ponytail: backend gap` 字段
  - 项目硬规则按需提炼
```

每页 commit 独立可回滚；commit 之间不交叉。

## 风险与缓解

| 风险 | 缓解 |
| --- | --- |
| Figma 节点层级深，逐页读耗时长 | 优先 `get_metadata` 抓骨架，再用 `get_screenshot` 关键区域 |
| 后端字段不足导致 Figma 元素无法还原 | 占位 + `// ponytail` 注释，不阻塞 |
| 删除旧 me/* 视图导致深链失效 | router 重定向 + 保留 alias |
| 11 个 commit 中途失败 | 每页 commit 跑质量门，发现问题立即修 |
| 时间跨度长导致设计稿理解漂移 | 每页 commit 前再读一遍 Figma 关键区域 |

## 不做

- 移动端实现
- 后端 API / 数据库变更
- Element Plus 主题色全局替换
- Playwright e2e 浏览器测试
- 全链路性能优化（首屏 / 打包体积）
- i18n

## 验收标准

- 11 个 Figma 桌面画板逐页对应 `apps/web` 视图文件
- `pnpm typecheck / lint / test / build` 全部通过
- 每个视图至少 1 个 vitest 测试，关键路径覆盖 ≥ 80%
- `// ponytail: backend gap` 字段全部汇总到 `tasks/lessons.md`
- 10 个 commit 顺序、独立可回滚