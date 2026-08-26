# HANDOFF — learn-site Phase 4 收尾 & 后续 Phase 5 入口

> 创建时间: 2026-08-24
> 来源: /speckit-implement Phase 4 完成后,用户请求 /handoff
> 增补时间: 2026-08-24 (Phase 5 完成后,用户请求 /handoff 继续补充)
> 当前阶段: **Phase 5 完成,Phase 6 待启动**

---

## 1. 当前位置

- **完成阶段**: Phase 4 (User Story 2: 管理员在后台组织并发布课程) — T034–T040 全部勾选
- **下一阶段**: Phase 5 (User Story 3: 学员浏览/报名/学习流程) — 任务编号 T041 起
- **当前分支**: main (本地未提交任何 commit)
- **工作目录**: `/Volumes/MOVESPEED/ai-coding/learn-site/`

---

## 2. 已完成的工作

### 2.1 后端 (PHP / Webman)

| 任务 | 文件 | 关键点 |
|------|------|--------|
| T034 | `apps/api/database/migrations/2026_08_23_000003_create_catalog.php` | 5 表:categories / courses / chapters / lessons / assets; FK RESTRICT/CASCADE 符合计划 |
| T035 | `apps/api/app/model/{Category,Course,Chapter,Lesson,Asset}.php` | 5 个 think-orm 模型,模板与 Department/Account 一致 |
| T036 | `apps/api/app/support/HtmlSanitizer.php` + `apps/api/app/service/CourseService.php` | DOMDocument 白名单净化;category/department 启用校验;price 不变量;发布前置;data-scope 占位 |
| T037 | `apps/api/app/controller/admin/{CategoryController,CourseController}.php` | 分类树/平铺下拉/CRUD;CATEGORY_IN_USE 409 |
| T038 | `apps/api/app/controller/admin/AssetController.php` | pdf/video multipart, MIME + size (env 可调),直接 `status='ready'` |

### 2.2 前端 (Vue 3 + Element Plus)

| 任务 | 文件 |
|------|------|
| T039 | `apps/admin/src/views/auth/LoginView.vue`、`apps/admin/src/layouts/AdminLayout.vue`、`apps/admin/src/views/catalog/{CategoryListView,CourseListView,CourseEditView}.vue` |
| T040 | `apps/admin/src/views/catalog/CoursePreviewView.vue` |

### 2.3 共享契约 / 基础设施

- `packages/contracts/src/catalog.ts` — Zod schemas (CategoryDTO/CourseDTO/ChapterDTO/LessonDTO/AssetDTO + 输入)
- `packages/contracts/src/tokenEnvelope.ts` — `parseTokenEnvelope` 返回 `TokenPair & { must_change_password?: boolean }`
- `apps/admin/src/api/catalog.ts` — HTTP wrappers (含 `uploadAsset` FormData + `onUploadProgress`)
- `apps/admin/src/router/index.ts` — 路由 + `meta.public` 守卫 + `mustChangePassword` 短路
- `apps/admin/src/api/http.ts` — 导出 `hasTokens()`, refresh 改用 `parseTokenEnvelope`
- `apps/admin/eslint.config.js` — vue-eslint-parser + 全局允许 `vue/no-v-html` (HtmlSanitizer 是信任边界)

---

## 3. 本会话已通过的验证

```
pnpm exec vue-tsc --noEmit          # apps/admin — 0 errors
pnpm exec eslint . --max-warnings 0  # apps/admin — 0 warnings
pnpm exec tsc --noEmit              # packages/contracts — 0 errors
```

未在本会话执行的运行时冒烟 (需本地 docker compose):

- Phinx migrate 落 5 张表
- curl 端到端: captcha → admin login → 三层分类 → 课程 → 章节 → 三种课节 (含 asset) → publish
- 学员侧 `GET /api/learner/v1/home` 一致性 (Phase 5 准备)
- Logger scrub grep password/token/captcha_answer/phone

---

## 4. Constitution v1.2.0 仍生效的硬约束 (Phase 5 需遵守)

- **数据栈**: webman + think-orm;**禁止** illuminate/database
- **镜像**: MySQL `8.4.11@sha256`、Redis `7.4.11@sha256` 必须 pin digest
- **鉴权**: 无 Session,access 15m / refresh 7d rotation,deny-wins RBAC
- **Captcha**: TTL 120s
- **日志安全 (FR-093)**: 永远不记录 password / token / captcha_answer / phone / payment_credential
- **错误响应**: 统一走 `App\support\ApiResponse::fail($code, $message)`,稳定错误码 (CAPTCHA_INVALID / TOKEN_EXPIRED / TOKEN_REVOKED / UNAUTHENTICATED / FORBIDDEN / NOT_FOUND / VALIDATION_FAILED / LOGIN_INVALID / RATE_LIMITED / INTERNAL,Phase 4 新增 CONFLICT / CATEGORY_IN_USE)

---

## 5. 已知 Phase 4 缺口 / ponytail 占位 (留给后续 phase)

- 富文本编辑器: 当前 `<textarea>`,待 Phase 5+ 按需引入 WYSIWYG (例如 `@wangeditor/editor-for-vue`)
- 首次改密页: `mustChangePassword` 现在弹 alert + clearTokens 退回 /login,Phase 8 做专门页
- Asset GC: 删 lesson/course 不删磁盘文件,Phase 9 引入清理 worker
- 课程复制 / 模板: 不实现
- 数据范围: Phase 4 仅 super_admin 全部 / 其他空集;Phase 10 接管 DataScopeService
- 删除校验 `course_enrollments` / `orders`: try/catch 容错,Phase 6 自然生效
- Asset 后台处理: 直接落 `status='ready'`,Phase 9 再做转码

---

## 6. Phase 5 入口 (T041 起)

参考 `specs/001-personal-learning-site/tasks.md` 中 Phase 5 任务清单。预期覆盖:

- 学员侧匿名浏览: 首页 / 分类 / 课程详情 / 试看 (用现有 courses + chapters + lessons + assets)
- 报名 / 订单: 引入 `course_enrollments` / `orders` 表 (Phase 6 也会用)
- 学习侧目录: 调 `GET /api/learner/v1/home` 之外的 learner catalog APIs
- 复用 Phase 4 的 `CategoryController::index` 已暴露给 learner(已在 `HomeService::nest()` 上工作)
- 学员 token 由 Phase 1/2 `learner auth` 流程颁发

---

## 7. 提示给下一个 agent 的 skills

> 实际执行下一步前,**必须**先用 Skill tool 调用对应 skill,不要直接动手。

| 场景 | skill 名称 | 触发条件 |
|------|------------|----------|
| 启动新一轮 /speckit-implement (Phase 5) | `speckit-implement` | 用户说 "Phase 5" / "继续下一阶段" 时 |
| 任何非琐碎任务前的规划 | `using-superpowers` + `brainstorming` | 启动对话时先调 using-superpowers |
| 写代码前的测试先行 | `tdd-guide` | 新功能 / bug 修复 |
| 代码写完后评审 | `code-reviewer` | 写完 / 修改完代码立即调 |
| 安全相关代码 | `security-reviewer` | 鉴权 / 报名 / 支付 / 用户输入处理 |
| 复杂多步任务 | `planner` | Phase 5 整体规划 |
| 架构决策 | `architect` | 数据范围 / 报名流程架构 |
| 构建失败 | `build-error-resolver` | phinx / pnpm / docker 报错 |
| E2E 流程 | `e2e-runner` | 学员核心流程跑通 |
| 重构清理 | `refactor-cleaner` | Phase 4 留下的 ponytail 占位清理 |
| 文档更新 | `doc-updater` | 改 README / API doc |

---

## 8. 关键参考路径

- 计划: `/Users/wxvirus/.claude/plans/giggly-shimmying-biscuit.md` (Phase 4 完整设计)
- 任务列表: `specs/001-personal-learning-site/tasks.md` (T001–T040 已勾选,T041+ 待办)
- Constitution: `.specify/memory/constitution.md` (v1.2.0)
- 数据模型: `specs/001-personal-learning-site/data-model.md` (Phase 5 需先阅读确认是否新增表)
- API 契约: `packages/contracts/src/` + `specs/001-personal-learning-site/contracts/`
- 共享 envelope: `packages/contracts/src/envelope.ts` (ApiOk / ApiErr / ApiResponse / ErrorCode)

---

## 9. 不要做的事

- 不要重新生成 tasks.md (除非用户明确要求 /speckit-tasks)
- 不要重写 Phase 4 已勾选的文件 (T034–T040),除非有 bug 报告
- 不要引入 illuminate/database (Constitution 禁止)
- 不要新增 enum,使用 string literal union
- 不要在 controller 里直接写 SQL,全部走模型或 `support\think\Db`
- 不要 hardcode 任何 secret / token / phone / password
- 不要引入 console.log,使用 `App\support\Logger` (后端) 或项目内日志约定 (前端)

---

# 增补 — Phase 5 完成 (US1 学员发现并学习课程, MVP 学习闭环)

> 完成时间: 2026-08-24
> 范围: T041 / T042 / T043 / T044 / T045 — 全部 `[x]`
> 计划文件: `/Users/wxvirus/.claude/plans/transient-tumbling-oasis.md`

## 10. Phase 5 关键交付

### 10.1 后端 (PHP / Webman)

| 任务 | 文件 | 关键点 |
|------|------|--------|
| T041 | `apps/api/app/service/PublicCatalogService.php` | 分类 sub-tree 收集 (深度 ≤3);`shapeListItem` / `shapeDetailCourse` / `shapeLessonSummary`;`locked = !is_preview && !viewer_authorized` (FR-029) |
| T041 | `apps/api/app/controller/learner/CatalogController.php` | `coursesByCategory` + `courseDetail` 两个 GET,公开 (无中间件) |
| T042 | `apps/api/app/service/PublicLessonService.php` | preview 开放;非 preview 在 Phase 6 之前硬返 403;Markdown 走 `HtmlSanitizer` (FR-009);PDF/视频返 `storage_path` 直链 |
| T042 | `apps/api/app/controller/learner/LessonController.php` | 单 GET,挂在 `LearnerAuth` 中间件组 |
| 路由 | `apps/api/app/route.php` | 3 路由串入既有 `$learnerV1` 分组 (公开组 + 鉴权组) |

**Ponytail 占位 (留给 Phase 6):**
- `PublicCatalogService::publicLearnerCount()` — try/catch 容错,Phase 6 接管 `course_enrollments`
- `PublicCatalogService::viewerAuthorized()` / `PublicLessonService::viewerAuthorized()` — 硬返回 `false`,Phase 6 接 `EntitlementService`
- 注: DTO 形状已经稳定,Phase 6 仅替换返回源,前端零改动

### 10.2 前端 (Vue 3 + Vite + TS strict)

| 任务 | 文件 | 关键点 |
|------|------|--------|
| 契约 | `packages/contracts/src/catalog.ts` | 公开 DTO: `CourseListItemDTO` / `PublicCourseDTO` / `PublicCourseDetailDTO` / `ChapterWithLessonSummariesDTO` / `LessonSummaryDTO` / `CategoryCoursesEnvelopeDTO` / `LessonDeliveryDTO` (discriminated union on `kind`) |
| API | `apps/web/src/api/learner.ts` | `fetchCategoryCourses` / `fetchCourseDetail` / `fetchLesson` (沿用 `ApiResponse(schema).parse()` + `throwApi(err)` 模式) |
| T043 | `apps/web/src/views/catalog/CategoryView.vue` | 课程 grid + 分页 + 面包屑 + 试看徽标 + 学员数 |
| T043 | `apps/web/src/views/catalog/CourseDetailView.vue` | Hero + 富文本简介 (`v-html`,server-sanitized) + 章节/课节列表 + AccessGate 包装每条课节 |
| T044 | `apps/web/src/views/learn/LessonView.vue` | kind 分流 (markdown `v-html` / pdf `<a>` / video `<video controls>`) + 上下节导航 + 错误码文案 |
| T045 | `apps/web/src/views/catalog/AccessGate.vue` | 三态 CTA: 未登录→登录 / 已登录免费→"即将开放" / 已登录付费→订单页 |
| 路由 | `apps/web/src/router/index.ts` | `/courses/:id` (公开) + `/learn/:courseId/:lessonId` (`requireLearnerAuth` 保护) |

### 10.3 关键设计取舍

- **Markdown 渲染**: 走内联 mini-parser (paragraphs / headings h1-h3 / lists / code fences / http(s) 链接 / bold/italic/inline-code),前端只 `v-html` 一次 (server sanitizer 是单一真理源)
- **视频/PDF**: 直接 `storage_path`,**未**做签名/Token URL — Phase 9 worker 接管,DTO 形状不变
- **错误码**: 前端只看稳定 code (`FORBIDDEN` / `NOT_FOUND` / `TOKEN_EXPIRED` / `UNAUTHENTICATED`),业务细节 (e.g. `LESSON_LOCKED`) 落 `message`
- **导航**: Phase 5 加 `requireLearnerAuth` 守卫,前端 `hasTokens()` 判定,过期跳 `/login?redirect=...`

### 10.4 本会话验证结果

```
pnpm --filter @learn-site/contracts build   # 0 errors
apps/web 下 pnpm exec vue-tsc --noEmit      # EXIT=0 (整库 TS strict 通过)
```

**跳过/未做 (按 Phase 22 范围):**
- 本地无 `php` 命令 → PHP lint 跳过
- ESLint 在 `.vue` 上有**预存在**的 parser 错误 (HomeView 复现,与 Phase 5 无关)
- E2E (Playwright) → Phase 22 T107
- `course_enrollments` / `orders` 真实表 → Phase 6

## 11. Phase 5 留下的 ponytail 占位 (Phase 6 必须解决)

- `PublicCatalogService::publicLearnerCount()` → 接 `course_enrollments` 表 (Phase 6 新增)
- `PublicCatalogService::viewerAuthorized()` / `PublicLessonService::viewerAuthorized()` → 接 `EntitlementService`
- `AccessGate.vue` 的 "免费课程即将开放" 提示 → Phase 6 落地免费立即授权,删掉占位
- 资源直链 `storage_path` → Phase 9 worker 加签名 URL

## 12. Phase 6 入口 (T046 起)

参考 `specs/001-personal-learning-site/tasks.md` Phase 6 任务清单。预期覆盖:
- `EntitlementService` (免费/付费/退款撤销)
- `orders` + `course_enrollments` 表迁移
- 学员端: `POST /enrollments` / `POST /orders` / `GET /me/learning`
- 进度跟踪 (Phase 6 起算 video 90% = 完成)
- Payment gateway: 先 fake 适配器 (T108 测试要求 success 路径可观测)

复用 Phase 5 准备:
- `PublicCatalogService::viewerAuthorized()` 是接入点,DTO 形状稳定,改一处即可
- `AccessGate.vue` 三态文案可由 Phase 6 调整,无需重构
- 路由 `/me/learning` 等已在 Phase 5 路由表里占位 (T046 起填实)

## 13. 提示给下一个 agent 的 skills (Phase 6 视角)

> 实际执行下一步前,**必须**先用 Skill tool 调用对应 skill,不要直接动手。

| 场景 | skill 名称 | 触发条件 |
|------|------------|----------|
| 启动 /speckit-implement Phase 6 | `speckit-implement` | 用户说 "Phase 6" / "下一阶段" |
| 报名 + 支付架构 | `architect` | 涉及 EntitlementService / orders / payment gateway 决策 |
| TDD 必走 | `tdd-guide` | EntitlementService / 支付状态机 (T108 要求) |
| 安全评审 | `security-reviewer` | 报名 / 支付 / 撤销 (强触发) |
| 多步规划 | `planner` | Phase 6 整体拆任务 |
| 写代码后立即评审 | `code-reviewer` | 每个 service/controller 写完即调 |
| E2E 学员核心流程 | `e2e-runner` | Phase 22 之后;Phase 6 内可先 Vitest 单测 |
| 调试 | `build-error-resolver` | phinx / pnpm / docker 报错 |
| 清理 Phase 5 占位 | `refactor-cleaner` | 替换 `// ponytail:` 注释 + 删除 try/catch 容错 |

## 14. 不要做的事 (Phase 6 补充)

- 不要重新生成 tasks.md
- 不要重写 Phase 5 已勾选的文件 (T041–T045),除非 Phase 6 接入时显式改造
- 不要绕过 `EntitlementService` 直接查 `course_enrollments` — 必须走 service 层
- 不要在前端写进度计算逻辑,服务端权威
- 不要在订单回调里同步落 enrollment,避免超时重入
- 不要给"免费授权"加额外 UI 控件,Phase 6 自动触发现状即可
- 不要新增 enum;PaymentStatus / EnrollmentSource 用 string literal union

## 15. 关键参考路径 (Phase 6 入口)

- Phase 5 计划: `/Users/wxvirus/.claude/plans/transient-tumbling-oasis.md`
- 任务列表: `specs/001-personal-learning-site/tasks.md` (T001–T045 已勾选,T046+ 待办)
- Constitution: `.specify/memory/constitution.md` (v1.2.0)
- 数据模型: `specs/001-personal-learning-site/data-model.md` (Phase 6 需先确认 `course_enrollments` / `orders` schema)
- API 契约: `packages/contracts/src/catalog.ts` (Phase 5 DTO 稳定,Phase 6 扩展)
- 共享 envelope: `packages/contracts/src/envelope.ts` (ApiOk / ApiErr / ApiResponse / ErrorCode)
- Phase 5 注入点: `PublicCatalogService.php::viewerAuthorized` / `PublicLessonService.php::viewerAuthorized` / `PublicCatalogService.php::publicLearnerCount`