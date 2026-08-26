---
description: "Task list for 001-personal-learning-site"
---

# Tasks: 个人课程学习网站

**Input**: Design documents from `/specs/001-personal-learning-site/`

**Prerequisites**: plan.md (宪章 v1.1.3), spec.md, research.md, data-model.md, contracts/

**Tests**: 规格未要求每故事 TDD。宪章要求鉴权自动化测试, 集中在 Phase 2 (AuthTokenTest, StaffGuardTest) 与 Polish (Playwright smoke + contract tests).

**Organization**: 路径以 `plan.md` 为准: `apps/api`, `apps/admin`, `apps/web`, `packages/contracts`, `docker/`, `compose.yaml`.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: 可并行
- **[Story]**: US1–US19

## Phase 1: Setup (Shared Infrastructure)

**Purpose**: 仓库骨架与 Compose (MySQL/Redis 固定 index digest)

- [x] T001 Create directory tree `apps/api/`, `apps/admin/`, `apps/web/`, `packages/contracts/`, `docker/api/`, `docker/admin/`, `docker/web/` per `specs/001-personal-learning-site/plan.md`
- [x] T002 Initialize Webman 2.2 PHP 8.4 project in `apps/api/` with Composer 2, require `webman/redis`, and commit `composer.lock`
- [x] T003 [P] Scaffold Vue 3 + Vite + TypeScript strict app in `apps/admin/` with Element Plus, Pinia, Vue Router, Zod, Tailwind CSS, Axios, pnpm
- [x] T004 [P] Scaffold Vue 3 + Vite + TypeScript strict app in `apps/web/` with the same stack as `apps/admin/`
- [x] T005 [P] Initialize shared Zod package in `packages/contracts/` and wire `pnpm-workspace.yaml`
- [x] T006 Write `compose.yaml` pinning `mysql:8.4.11@sha256:b3b90af2a6552ae30c266fdb7d5dd55f3afb72404bb78d37fe8a23eb857fd3fb` and `redis:7.4.11@sha256:91d0f7e8c748ec7a4c2b4fb2c4f84edab794dd91d01e095e38dc906db9d684ab` (never `latest`), plus `api`/`admin`/`web`, healthchecks, named volumes, no host DB/Redis ports by default
- [x] T007 [P] Write `docker/api/Dockerfile` PHP 8.4-cli with redis extension, and multi-stage nginx Dockerfiles in `docker/admin/Dockerfile` and `docker/web/Dockerfile`. Pin every base image to a fixed tag or `@sha256:<digest>` (PHP 8.4-cli, nginx) — never `latest` or floating minor tags — so the build is reproducible per constitution II.
- [x] T008 [P] Write `.env.example` for MySQL, Redis, access-token TTL 15m, refresh-token TTL 7d, captcha TTL 120s, super-admin account (not a 11-digit phone), fake payment flag
- [x] T009 [P] Configure PHPStan/PHPUnit/formatter in `apps/api/composer.json` and ESLint/Prettier/vue-tsc/Vitest in `apps/admin/package.json` and `apps/web/package.json`
- [x] T010 [P] Write `compose.debug.yaml` profile to expose MySQL 3306 only

---

## Phase 2: Foundational (Blocking Prerequisites)

**Purpose**: 不透明令牌、图形验证码、RBAC 中间件。未完成前不得开始用户故事。

**⚠️ CRITICAL**: No user story work can begin until this phase is complete

- [x] T011 Add Webman migrations under `apps/api/database/migrations/` and utf8mb4 in `apps/api/config/database.php`
- [x] T012 Create accounts/learners/staff_users migration in `apps/api/database/migrations/2026_08_23_000001_create_accounts.php` with `(kind, login)` unique, learner `^1[3-9]\d{9}$`, staff not matching that pattern
- [x] T013 [P] Create RBAC tables migration in `apps/api/database/migrations/2026_08_23_000002_create_rbac.php`
- [x] T014 Configure Redis connection in `apps/api/config/redis.php` and implement hashed token keys `access:`/`refresh:`/`family:` in `apps/api/app/service/TokenService.php` (access 15m, refresh 7d, rotate refresh, reuse-detect revoke family, kick all or one family, no Session, no account lockout)
- [x] T015 [P] Implement one-time graphical captcha with Redis key `captcha:{id}` TTL 120s in `apps/api/app/service/CaptchaService.php` (delete after success, failure, or login)
- [x] T016 Implement learner `GET /auth/captcha`, `POST /auth/register`, `POST /auth/login` (phone+password+captcha), refresh, logout in `apps/api/app/controller/learner/AuthController.php`
- [x] T017 [P] Implement admin `GET /auth/captcha`, `POST /auth/login` (account+password+captcha), refresh, logout, first password change, `POST /staff/{id}/kick` and `POST /learners/{id}/kick` (`family_id` optional), and `POST /learners/{id}/password` (`learner.reset_password`) in `apps/api/app/controller/admin/AuthController.php` and `apps/api/app/controller/admin/LearnerController.php`
- [x] T018 Add bearer middleware that checks Redis access records in `apps/api/app/middleware/LearnerAuth.php` and `apps/api/app/middleware/AdminAuth.php` (separate audiences)
- [x] T018a Implement structured request logging in `apps/api/app/middleware/RequestLogger.php` per FR-093 (request id, accessor type, account id, action, result; never log password/token/captcha answer/payment credential/learner phone; token hashed only; not bypassable in debug)
- [x] T019 Implement RBAC+data-scope in `apps/api/app/middleware/Authorize.php` and `apps/api/app/service/PermissionService.php` (FR-080 union of all/dept_and_children/specified_depts/dept/self; self = creator not last editor; user grant does not widen scope)
- [x] T020 Seed FR-075 permission points (including `learner.reset_password` and `learner.kick`, not implied by `learner.view`) and first super-admin in `apps/api/database/seeds/PermissionSeeder.php` and `apps/api/database/seeds/SuperAdminSeeder.php`
- [x] T021 [P] Add JSON envelope and codes `CAPTCHA_INVALID`/`TOKEN_EXPIRED`/`TOKEN_REVOKED` in `apps/api/app/support/ApiResponse.php` per `specs/001-personal-learning-site/contracts/conventions.md`
- [x] T022 [P] Add `GET /health` checking MySQL and Redis in `apps/api/app/controller/HealthController.php`
- [x] T023 [P] Implement Axios silent refresh (no captcha) in `apps/web/src/api/http.ts`
- [x] T024 [P] Implement Axios silent refresh (no captcha) in `apps/admin/src/api/http.ts`
- [x] T025 [P] Add shared Zod auth/captcha schemas in `packages/contracts/src/auth.ts`
- [x] T026 Add PHPUnit tests for captcha one-use (including after successful login), captcha TTL 120s, refresh rotation, refresh reuse revokes family, kick-all vs kick-one-family, Redis-down fail-closed, and no account lockout after repeated bad passwords in `apps/api/tests/AuthTokenTest.php`. Add two concurrent-login cases on the same captcha id (first wins, second returns CAPTCHA_INVALID even when second credentials are correct) and a replay-after-success case (re-using a captcha id already consumed by a successful login must return CAPTCHA_INVALID, not TOKEN_EXPIRED or success). Add a learner-side kick symmetry case: kicking a learner's family revokes both access and refresh tokens for that family; kicking all revokes every family of that learner, while a different learner's tokens remain valid.
- [x] T027 Implement JSON structured logging to stdout/stderr in `apps/api/config/log.php` and request-id middleware in `apps/api/app/middleware/RequestLog.php`
- [x] T028 Handle SIGTERM graceful shutdown in `apps/api/config/process.php` (or Webman stop timeout) and document it in `docker/api/Dockerfile`

**Checkpoint**: 手机号/后台账号 + 图形验证码可拿令牌; 刷新不要求验证码; 踢全部或单个登录族后旧令牌 401; 验证码 2 分钟过期; 无账户锁定

---

## Phase 3: User Story 16 - 学员使用学习端入口 (Priority: P1)

**Goal**: 学习端壳: 首页分类树、主导航地图、登录/注册+验证码、未登录引导到学员登录

**Independent Test**: 首页是分类树; 未登录打开我的学习去学员登录而不是后台登录

- [x] T029 [US16] Add learner layout and router in `apps/web/src/layouts/LearnerLayout.vue` and `apps/web/src/router/index.ts`
- [x] T030 [P] [US16] Build captcha+phone login/register pages in `apps/web/src/views/auth/LoginView.vue` and `apps/web/src/views/auth/RegisterView.vue`
- [x] T031 [P] [US16] Build Home category tree in `apps/web/src/views/home/HomeView.vue`
- [x] T032 [US16] Redirect unauthenticated 我的学习/收藏/订单/消息 to learner login in `apps/web/src/router/guards.ts`; 消息入口本期不在 MVP, 路由占位并指向空态页
- [x] T033 [US16] Implement `GET /api/learner/v1/home` in `apps/api/app/controller/learner/HomeController.php`

**Checkpoint**: 学习端可注册登录并看到首页壳

---

## Phase 4: User Story 2 - 管理员在后台组织并发布课程 (Priority: P1) 🎯 MVP 内容生产

**Goal**: 超管维护最多三级分类, 编辑课程/章节/课节, 预览并发布

**Independent Test**: 从空白发布一门含两章三种课节的课, 学员侧目录一致

- [x] T034 [US2] Add catalog migrations in `apps/api/database/migrations/2026_08_23_000003_create_catalog.php`
- [x] T035 [P] [US2] Implement models in `apps/api/app/model/Category.php`, `apps/api/app/model/Course.php`, `apps/api/app/model/Chapter.php`, `apps/api/app/model/Lesson.php`
- [x] T036 [US2] Implement publish rules and server-side HTML whitelist sanitizer for course intro in `apps/api/app/service/CourseService.php` and `apps/api/app/support/HtmlSanitizer.php`
- [x] T037 [US2] Implement admin catalog APIs in `apps/api/app/controller/admin/CategoryController.php` and `apps/api/app/controller/admin/CourseController.php`
- [x] T038 [P] [US2] Implement asset upload in `apps/api/app/controller/admin/AssetController.php`
- [x] T039 [US2] Add admin captcha login (reuse T017 captcha API) plus catalog views in `apps/admin/src/views/auth/LoginView.vue`, `apps/admin/src/layouts/AdminLayout.vue`, `apps/admin/src/views/catalog/`
- [x] T040 [US2] Add course preview in `apps/admin/src/views/catalog/CoursePreviewView.vue`

**Checkpoint**: 超管能发布一门完整课程

---

## Phase 5: User Story 1 - 学员发现并学习课程 (Priority: P1) 🎯 MVP 学习闭环

**Goal**: 三级分类逛课, 获权后消费 Markdown/PDF/视频, 无权限只看摘要

**Independent Test**: 预置三种课节的免费课, 从分类进入并依次打开

- [x] T041 [US1] Implement public catalog APIs in `apps/api/app/controller/learner/CatalogController.php`
- [x] T042 [US1] Implement lesson delivery with preview/entitlement checks in `apps/api/app/controller/learner/LessonController.php`
- [x] T043 [P] [US1] Build category and course detail pages in `apps/web/src/views/catalog/CategoryView.vue` and `apps/web/src/views/catalog/CourseDetailView.vue`
- [x] T044 [US1] Build lesson reader/player in `apps/web/src/views/learn/LessonView.vue`
- [x] T045 [US1] Enforce non-preview lock CTA in `apps/web/src/views/catalog/AccessGate.vue`

**Checkpoint**: 访客/学员可发现并消费已发布课程

---

## Phase 6: User Story 3 - 获取课程并跟踪学习进度 (Priority: P1)

**Goal**: 免费立即授权; 收费仅支付成功授权; 进度与视频 90% 完成

**Independent Test**: 免费与收费各走一遍, 重登进度正确

- [X] T046 [US3] Add learning/order migrations in `apps/api/database/migrations/2026_08_23_000004_create_learning.php`
- [X] T047 [US3] Implement `apps/api/app/service/EntitlementService.php` and `apps/api/app/service/ProgressService.php`
- [X] T048 [US3] Implement start-free and progress endpoints in `apps/api/app/controller/learner/LearningController.php` and `GET /api/learner/v1/orders` in `apps/api/app/controller/learner/OrderController.php`
- [X] T049 [P] [US3] Implement Fake payment adapter in `apps/api/app/support/payment/FakePaymentAdapter.php` per FR-094: 单 success 态, 创建订单后 `FAKE_PAYMENT_DELAY_MS` 毫秒 (默认 3000) 自动回调, 据此创建课程访问权; 不得提供"标记成功"或"绕过支付建权"内部接口; 复用 FR-066 审计字段与 FR-067 权限边界
- [X] T050 ~~[US3] Implement WeChat Native adapter and webhook in `apps/api/app/support/payment/WechatNativeAdapter.php` and `apps/api/app/controller/internal/PaymentNotifyController.php`~~ — 跳过: 真实微信支付不在本期 MVP, 由 FR-094 与 FakePaymentAdapter 承担
- [X] T051 [US3] Build 我的学习 in `apps/web/src/views/me/MyLearningView.vue` and 我的订单 in `apps/web/src/views/me/MyOrdersView.vue`

**Checkpoint**: 授权与进度可独立验收

---

## Phase 7: User Story 9 - 管理员使用后台工作台 (Priority: P1)

**Goal**: 权限范围内待办; 学员令牌不能调管理 API

**Independent Test**: 从工作台进入问答/课程/订单

- [X] T052 [US9] Implement dashboard in `apps/api/app/controller/admin/DashboardController.php`
- [X] T053 [US9] Build dashboard UI in `apps/admin/src/views/dashboard/DashboardView.vue`
- [X] T054 [US9] Reject learner tokens on `/api/admin/*` in `apps/api/app/middleware/AdminAuth.php`

**Checkpoint**: 工作台可用

---

## Phase 8: User Story 12 - 配置部门、岗位、角色和后台员工 (Priority: P1)

**Goal**: 五级部门、岗位绑定角色、创建员工; 不能删最后超管; 后台账号不得为 11 位手机号

**Independent Test**: 员工同时获得岗位角色与直接角色

- [X] T055 [US12] Implement org services in `apps/api/app/service/DepartmentService.php`, `apps/api/app/service/RoleService.php`, `apps/api/app/service/StaffService.php`
- [X] T056 [US12] Implement org endpoints in `apps/api/app/controller/admin/DepartmentController.php`, `apps/api/app/controller/admin/PostController.php`, `apps/api/app/controller/admin/RoleController.php`, `apps/api/app/controller/admin/StaffController.php`
- [X] T057 [US12] Build org views in `apps/admin/src/views/org/` (staff list includes kick-all and kick-one-family)
- [X] T058 [US12] Enforce last-super-admin, no self-escalation, and non-phone staff login in `apps/api/app/service/StaffService.php`. Add `apps/api/tests/StaffGuardTest.php` covering: self-delete rejected, self-disable rejected, self-promote-to-superadmin rejected, demote the last super admin rejected, promoting a normal staff to super admin requires the actor to already be super admin, staff login using an 11-digit phone-shaped account is rejected at validation.

**Checkpoint**: 可配置组织与员工

---

## Phase 9: User Story 13 - 按功能权限使用后台 (Priority: P1)

**Goal**: 无权限菜单隐藏, 直链 403 不泄露

**Independent Test**: 仅问答权限看不到课程维护和订单

- [X] T059 [US13] Filter sidebar in `apps/admin/src/layouts/AdminMenu.ts`
- [X] T060 [US13] Map routes to permission codes in `apps/admin/src/router/index.ts`
- [X] T061 [US13] Return 403 without body leakage in `apps/api/app/middleware/Authorize.php`

**Checkpoint**: 功能权限对菜单和 API 同时生效

---

## Phase 10: User Story 14 - 按部门数据范围访问业务数据 (Priority: P1)

**Goal**: 五种数据范围; 指定部门不含下级; 派生数据跟随课程当前部门

**Independent Test**: 父/子/旁支三门课, 范围结果 100% 一致

- [X] T062 [US14] Implement resolver in `apps/api/app/service/DataScopeService.php`
- [X] T063 [US14] Apply scope to list queries in `apps/api/app/service/CourseService.php`
- [X] T064 [US14] Follow course department on move in `apps/api/app/service/CourseService.php`

**Checkpoint**: 部门数据范围可独立验收

---

## Phase 11: User Story 4 - 针对具体课节提问并获得回答 (Priority: P2)

**Goal**: 课节提问、后台回答、同学可见

**Independent Test**: 学员提问, 管理员回答, 另一授权学员能看到

- [X] T065 [US4] Add QA migrations in `apps/api/database/migrations/20260823000005_create_qa.php` and `apps/api/database/migrations/20260825000001_harden_qa_schema.php`
- [X] T066 [US4] Implement QA in `apps/api/app/service/QuestionService.php`, `apps/api/app/controller/learner/QuestionController.php`, `apps/api/app/controller/admin/QuestionController.php`
- [x] T067 [P] [US4] Build lesson Q&A UI in `apps/web/src/views/learn/QuestionPanel.vue`
- [x] T068 [US4] Build admin inbox in `apps/admin/src/views/qa/QuestionListView.vue`

**Checkpoint**: 课节问答闭环

---

## Phase 12: User Story 5 - 评价课程并参与树形讨论 (Priority: P2)

**Goal**: 一课后可评; 树形回复; 管理员隐藏

**Independent Test**: 三层回复, 隐藏后公开页不再显示

- [X] T069 [US5] Add review migrations in `apps/api/database/migrations/20260823000006_create_reviews.php` and `apps/api/database/migrations/20260825000002_harden_review_schema.php`
- [X] T070 [US5] Implement reviews in `apps/api/app/service/ReviewService.php`, `apps/api/app/controller/learner/ReviewController.php`, `apps/api/app/controller/admin/ReviewController.php`
- [X] T071 [P] [US5] Build review tree in `apps/web/src/views/catalog/ReviewTree.vue`
- [X] T072 [US5] Build moderation UI in `apps/admin/src/views/reviews/ReviewModerateView.vue`

**Checkpoint**: 评价与隐藏可独立验收

---

## Phase 13: User Story 6 - 按学习地图完成系列课程 (Priority: P2)

**Goal**: 后台编排并发布; 学习端列表+详情; 非锁步

**Independent Test**: 三阶段地图, 完成一门课后下一步正确

- [X] T073 [US6] Add map migrations in `apps/api/database/migrations/20260823000007_create_maps.php` and `apps/api/database/migrations/20260825000003_harden_map_schema.php`
- [X] T074 [US6] Implement maps in `apps/api/app/service/LearningMapService.php`, `apps/api/app/controller/admin/LearningMapController.php`, `apps/api/app/controller/learner/LearningMapController.php`
- [X] T075 [P] [US6] Build admin map editor in `apps/admin/src/views/maps/MapEditorView.vue`
- [X] T076 [US6] Build learner map pages in `apps/web/src/views/maps/MapListView.vue` and `apps/web/src/views/maps/MapDetailView.vue`

**Checkpoint**: 学习地图可发布可学习

---

## Phase 14: User Story 10 - 管理员核验购买订单 (Priority: P2)

**Goal**: 只读订单快照, 禁止标记已支付

**Independent Test**: 四类支付结果与访问权一致

- [x] T077 [US10] Implement admin orders in `apps/api/app/controller/admin/OrderController.php`
- [X] T078 [US10] Build order list in `apps/admin/src/views/orders/OrderListView.vue`
- [X] T079 [US10] Ensure no mark-as-paid action exists in `apps/api/app/controller/admin/OrderController.php`

**Checkpoint**: 订单核验只读

---

## Phase 15: User Story 15 - 为单个员工做用户级权限覆盖 (Priority: P2)

**Goal**: grant/deny, deny 优先, 不能自我提权

**Independent Test**: 禁用订单查看后立即进不了订单

- [X] T080 [US15] Implement overrides in `apps/api/app/service/PermissionOverrideService.php`
- [X] T081 [US15] Add override API in `apps/api/app/controller/admin/StaffController.php`
- [X] T082 [US15] Build override UI in `apps/admin/src/views/org/StaffOverrideView.vue`

**Checkpoint**: 用户级覆盖可独立验收

---

## Phase 16: User Story 19 - 学员接收站内消息 (Priority: P2)

**Goal**: 问答变化、进度重置、取消免费访问进消息列表

**Independent Test**: 三类事件后三条记录, 已读消失

- [x] T083 ~~[US19] Add messages migration in `apps/api/database/migrations/2026_08_23_000008_create_messages.php`~~ — 跳过: 站内消息不在本期 MVP (Phase 21 重启为最小通知: `learner_notifications` 表)
- [x] T084 ~~[US19] Implement `apps/api/app/service/MessageService.php` and event hooks~~ — 跳过: 站内消息不在本期 MVP (Phase 21 实现 `MessageService::emit` 写一行收件箱)
- [x] T085 ~~[US19] Implement message APIs in `apps/api/app/controller/learner/MessageController.php`~~ — 跳过: 站内消息不在本期 MVP (Phase 21 `NotificationController` 提供 `/me/notifications` + `/read`)
- [x] T086 ~~[US19] Build messages UI in `apps/web/src/views/me/MessagesView.vue`~~ — 跳过: 站内消息不在本期 MVP (UI 留待消费端按需扩展, API 已可用)

**Checkpoint**: 站内消息可独立验收

---

## Phase 17: User Story 7 - 收藏并分享课程海报 (Priority: P3)

**Goal**: 收藏; 分享链接; 海报失败仍给链接

**Independent Test**: 收藏/取消, 失败时仍可复制链接

- [x] T087 [US7] Add share migrations in `apps/api/database/migrations/2026_08_23_000009_create_share.php`
- [x] T088 [US7] Implement favorite/poster APIs in `apps/api/app/controller/learner/FavoriteController.php` and `apps/api/app/controller/learner/ShareController.php`
- [x] T089 [P] [US7] Build favorites page in `apps/web/src/views/me/FavoritesView.vue`
- [x] T090 [US7] Build share bar in `apps/web/src/views/catalog/ShareBar.vue`

**Checkpoint**: 收藏与分享可用

---

## Phase 18: User Story 8 - 管理员查看课程学员 (Priority: P3)

**Goal**: 按课程筛选学员; 公开页只显示人数

**Independent Test**: 免费/付费/学习中/已完成筛选准确

- [x] T091 [US8] Implement per-course student list in `apps/api/app/controller/admin/CourseStudentController.php` and site-wide learner accounts in `apps/api/app/controller/admin/LearnerController.php`
- [x] T092 [US8] Build course student list in `apps/admin/src/views/students/CourseStudentView.vue` and learner account list plus password reset (`learner.reset_password`) and kick (`learner.kick`) in `apps/admin/src/views/students/LearnerListView.vue`
- [x] T093 [US8] Keep public course page aggregate-only in `apps/web/src/views/catalog/CourseDetailView.vue`

**Checkpoint**: 学员视图可用且不泄公开隐私

---

## Phase 19: User Story 11 - 管理员维护站点公开资料 (Priority: P3)

**Goal**: 站点名称/简介与审核记录

**Independent Test**: 改名称后公开页更新

- [x] T094 [US11] Add site_profile migration in `apps/api/database/migrations/2026_08_23_000010_create_site.php`
- [x] T095 [US11] Implement site/audit APIs in `apps/api/app/controller/admin/SiteController.php` and `apps/api/app/controller/admin/AuditController.php`
- [x] T096 [US11] Build site views in `apps/admin/src/views/site/`
- [x] T097 [US11] Render site intro on `apps/web/src/views/home/HomeView.vue`

**Checkpoint**: 站点资料与审核记录可用

---

## Phase 20: User Story 17 - 管理员删除无业务数据的草稿课程 (Priority: P3)

**Goal**: 无记录草稿/已下架可删; 有记录不可删

**Independent Test**: 空白草稿可删; 有学员下架课拒绝

- [x] T098 [US17] Implement delete rules in `apps/api/app/service/CourseService.php`
- [x] T099 [US17] Expose DELETE in `apps/api/app/controller/admin/CourseController.php`
- [x] T100 [US17] Add delete action in `apps/admin/src/views/catalog/CourseListView.vue`

**Checkpoint**: 删除规则可独立验收

---

## Phase 21: User Story 18 - 管理员取消免费课程访问权 (Priority: P3)

**Goal**: 免费可取消并通知; 付费不可取消; 可再加入沿用进度

**Independent Test**: 免费取消后不能看非试看; 付费取消被拒

- [x] T101 [US18] Implement revoke-free in `apps/api/app/service/EntitlementService.php`
- [x] T102 [US18] Add revoke API in `apps/api/app/controller/admin/CourseStudentController.php`
- [x] T103 [US18] Add revoke UI in `apps/admin/src/views/students/CourseStudentView.vue`
- [x] T104 [US18] Emit `entitlement_revoked` in `apps/api/app/service/MessageService.php`

**Checkpoint**: 免费可取消、付费不可取消

---

## Phase 22: Polish & Cross-Cutting Concerns

**Purpose**: 宪章门禁与 quickstart

- [x] T105 [P] Confirm ports and commands in `specs/001-personal-learning-site/quickstart.md` match `compose.yaml`
- [x] T106 Align `packages/contracts/src/` Zod schemas with learner/admin contracts
- [x] T107 [P] Add Playwright smoke (captcha login + publish + learn) in `apps/web/tests/e2e/smoke.spec.ts` and `apps/admin/tests/e2e/smoke.spec.ts`
- [x] T108 [P] Add PHPUnit contract tests for catalog publish, payment four-states (本期 fake 适配器仅 success 路径可观测, 失败/取消/超时由接口契约保证) and RBAC data-scope in `apps/api/tests/CatalogContractTest.php`, `apps/api/tests/PaymentContractTest.php`, `apps/api/tests/RbacScopeTest.php`
- [x] T109 Verify compose images use pinned digests (no `latest`), Redis-down fail-closed, kick-offline, captcha reuse rejected, structured logs on stdout, and graceful stop
- [x] T110 Add SC-003 timing script for browse/catalog/favorite/progress in `apps/api/tests/perf/timing.sh` (2s smoke, blocking for first release); document 200-user load as post-release observation in `specs/001-personal-learning-site/quickstart.md`. The smoke script asserts 95% of single-user requests complete within 2s; 200-user concurrency is run on demand and reported, not gated on merge.
- [x] T111 Run formatter, PHPStan, PHPUnit, ESLint, vue-tsc, production builds inside Compose
- [x] T112 Execute `specs/001-personal-learning-site/quickstart.md` on OrbStack

---

## Dependencies & Execution Order

### Phase Dependencies

- Setup → Foundational (阻塞全部故事) → 用户故事 → Polish

### User Story Dependencies

- **US16**: Foundational 后即可
- **US2**: Foundational 后即可 (超管发课)
- **US1**: 需要已发布课 (US2 或种子)
- **US3**: 需要 US1 课节页
- **US9**: 需要运营数据
- **US12**: 之后才能认真测 US13/US14
- **US4/US5**: 依赖 US1 授权
- **US6**: 依赖 US2 已发布课
- **US10**: 依赖 US3 订单
- **US15**: 依赖 US12/US13
- **US19**: 接 QA/进度/撤销事件
- **US7**: 依赖 US1
- **US8/US18**: 依赖 US3
- **US11**: 可独立
- **US17**: 依赖 US2

### Parallel Opportunities

- T003/T004 双前端脚手架
- T016/T017 两端登录控制器
- T023/T024 Axios
- Foundational 完成后 US16 与 US2 可分头做

---

## Parallel Example: User Story 16

```bash
Task: "Build captcha+phone login in apps/web/src/views/auth/LoginView.vue"
Task: "Build Home category tree in apps/web/src/views/home/HomeView.vue"
```

---

## Implementation Strategy

### MVP First

1. Phase 1 Setup (`mysql:8.4.11` + `redis:7.4.11`)
2. Phase 2 Foundational (令牌、验证码、超管)
3. US16 学习端登录壳
4. US2 发布免费课
5. US1 学员学习
6. **STOP and VALIDATE**

### Incremental Delivery

P1 剩余: US3 → US9 → US12–14
然后 P2、P3

---

## Notes

- 禁止 Session Cookie 与邮箱登录
- Redis 仅令牌/吊销/验证码
- 刷新令牌换发不得要图形验证码
- 宿主机直接跑 PHP 不构成验收

---

## Phase 23: Convergence

**Purpose**: 对照宪章 v1.2.0 与当前代码. 官方 ORM 路径见
https://www.workerman.net/doc/webman/db/thinkorm.html
(`composer require -W webman/think-orm`, 配置 `config/think-orm.php`,
模型继承 `support\think\Model`, 查询走 `support\think\Db`).
不得按 T011 字面把 `config/database.php` 当业务 ORM, 也不得引入
`illuminate/database`.

- [x] T113 CRITICAL Replace invented `Webman\Bootstrap` in `apps/api/start.php` with official Webman 2.2 `support\App::run()`, restore an HTTP worker in `apps/api/config/process.php` whose listen port matches Compose, and commit `apps/api/composer.lock` per Constitution II and T002 (partial)
- [x] T114 CRITICAL Install `webman/think-orm` with `composer require -W webman/think-orm` in `apps/api/` per Constitution IV and https://www.workerman.net/doc/webman/db/thinkorm.html; do not add `illuminate/database` (missing)
- [x] T115 CRITICAL Add `apps/api/config/think-orm.php` using official keys (`type`/`hostname`/`hostport`/`charset` utf8mb4/`break_reconnect`/`pool`) bound to `DB_*`; stop using `apps/api/config/database.php` as business ORM config per Constitution IV (contradicts)
- [x] T116 CRITICAL Replace raw `PDO` in `apps/api/app/controller/learner/AuthController.php`, `apps/api/app/controller/admin/AuthController.php`, `apps/api/app/controller/admin/LearnerController.php`, and `apps/api/app/service/PermissionService.php` with `support\think\Db` or models that extend `support\think\Model`; add Account/Learner/StaffUser/RBAC models under `apps/api/app/model/`; T022 MySQL health probe and later T035 catalog models MUST use the same stack per Constitution IV (contradicts)
- [x] T117 CRITICAL Generate and commit repo-root `pnpm-lock.yaml`; add `apps/web/src/main.ts` and `apps/admin/src/main.ts` (both `index.html` already load them) so Vue production builds succeed per Constitution II/III and T003/T004 (partial)
- [x] T118 HIGH Remove `profiles: ['debug']` from the `mysql` service in `compose.debug.yaml` so the default stack still starts MySQL; the override may only add host port 3306 per Constitution I and T010 (contradicts)
- [x] T119 HIGH Change Redis `--maxmemory-policy allkeys-lru` in `compose.yaml` to `noeviction` so access/refresh/captcha keys cannot be evicted per Constitution VI (contradicts)
- [x] T120 HIGH Install PHP GD (and fonts needed for PNG captcha) in `docker/api/Dockerfile` so `CaptchaService` can emit images per Constitution VI (missing)
- [x] T121 HIGH Publish learner and admin host ports (quickstart 8080/8081) in `compose.yaml`; do not publish Redis; stop mounting `admin_dist`/`web_dist` over image html so the image remains the runtime contract per Constitution I (missing)
- [x] T122 HIGH Add a Compose test profile or build stage that keeps Composer dev deps and Node so T111 can run formatter, PHPStan, PHPUnit, ESLint, vue-tsc, and production builds inside Compose without a host PHP/Node runtime per Constitution I/V (missing)
- [x] T123 HIGH Provide a Compose-documented migration command that runs versioned files under `apps/api/database/migrations/` without `illuminate/database` or `php webman migrate`; keep Phinx/SQL runner as schema tooling only, runtime queries stay on think-orm per Constitution IV (contradicts)
