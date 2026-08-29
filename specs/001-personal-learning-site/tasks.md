---
description: "Task list for 001-personal-learning-site"
---

# Tasks: 个人课程学习网站

**Input**: 设计文档位于 specs/001-personal-learning-site/

**Prerequisites**: plan.md、spec.md、research.md、data-model.md、contracts/、quickstart.md

**Design baseline**: 宪章 v1.2.0；Webman 2.2 + PHP 8.4；业务运行时统一使用 webman/think-orm；Phinx 仅负责版本化迁移和种子；PHP、Node、nginx、MySQL、Redis 使用固定版本与 digest。

**Testing**: 关键 API、鉴权、迁移、核心学习流程和后台/学习端流程必须有自动化验证；所有验证命令通过 Docker Compose/OrbStack 执行。

**Organization**: 任务按 P1、P2、P3 分组，每个用户故事保留独立目标和验收标准。US19 明确为后续扩展，不进入首版发布门禁。

## Checklist Format

- 每条任务均使用复选框、连续任务 ID，并包含明确的仓库相对路径。
- [P] 仅表示该任务可与同阶段其他任务并行，且不依赖未完成的同阶段任务。
- [X] 仅表示该任务的实现和对应验证均已完成。若任务依赖某个前置任务，则前置任务必须已完成，除非依赖被明确改写为既有能力或测试种子。
- 用户故事阶段的任务必须包含对应的 [USn] 标签；Setup、Foundational 和 Polish 阶段不使用用户故事标签。
- 测试任务应覆盖该故事的独立测试标准；接口、数据模型或权限变更必须同步更新契约、schema、迁移、模型和测试。

---

## Phase 1: Setup（共享基础设施）

**Purpose**: 建立双前端、API、契约包、容器和锁文件的可复现工程骨架。

- [X] T001 按 specs/001-personal-learning-site/plan.md 创建 apps/api/、apps/admin/、apps/web/、packages/contracts/、docker/ 和测试目录结构
- [X] T002 [P] 初始化 apps/api/composer.json、apps/api/composer.lock、apps/api/phpunit.xml 和 apps/api/phpstan.neon，锁定 Webman 2.2、PHP 8.4 与 Composer 依赖
- [X] T003 [P] 初始化 apps/admin/package.json、apps/admin/vite.config.ts、apps/admin/tsconfig.json 和 apps/admin/eslint.config.js，启用 Vue 3、TypeScript strict、Element Plus、Pinia、Vue Router、Zod、Tailwind CSS、Axios
- [X] T004 [P] 初始化 apps/web/package.json、apps/web/vite.config.ts、apps/web/tsconfig.json 和 apps/web/eslint.config.js，启用与管理端一致且可独立构建的前端栈
- [X] T005 [P] 初始化 packages/contracts/package.json、packages/contracts/tsconfig.json、packages/contracts/src/index.ts 和 pnpm-workspace.yaml，限定共享内容为契约与类型
- [X] T006 [P] 编写 docker/api/Dockerfile，使用 plan.md 中锁定的 php:8.4-cli digest，并安装 Redis、GD、PDO/MySQL、pcntl 和 posix 扩展
- [X] T007 [P] 编写 docker/admin/Dockerfile、docker/web/Dockerfile、docker/admin/nginx.conf 和 docker/web/nginx.conf，使用锁定的 Node 22 与 nginx digest，采用多阶段构建且运行阶段仅保留静态产物
- [X] T008 编写 compose.yaml，编排 api、admin、web、mysql、redis，固定 MySQL/Redis digest，声明健康检查、依赖条件、重启策略、网络和命名卷，默认不暴露数据库与 Redis 端口
- [X] T009 [P] 编写 compose.debug.yaml 和 compose.test.yaml，debug 仅显式暴露 MySQL 调试端口，test 保留 Compose 内的 PHP/Node 工具链和测试命令
- [X] T010 [P] 编写 .env.example、Makefile、package.json、pnpm-lock.yaml 和 .dockerignore，固定 Corepack/pnpm 版本，声明不含秘密的端口、TTL、Fake 支付和存储配置

---

## Phase 2: Foundational（阻塞前置能力）

**Purpose**: 完成任何用户故事都依赖的运行时、鉴权、数据访问、迁移和运维安全边界。

**CRITICAL**: 本阶段完成前不得开始用户故事实现。

- [X] T011 修正 apps/api/start.php、apps/api/app/process/Http.php 和 apps/api/config/process.php，使用官方 support\App::run() 启动 Webman HTTP worker，并使监听地址与 compose.yaml 一致
- [X] T012 在 apps/api/composer.json 和 apps/api/composer.lock 中安装并锁定 webman/think-orm，确认不存在 illuminate/database、Eloquent 或其他并行 ORM 依赖
- [X] T013 编写 apps/api/config/think-orm.php 集中绑定 DB_*、utf8mb4、连接池和断线重连参数；将 apps/api/config/database.php 仅保留为兼容占位文件
- [X] T014 配置 apps/api/phinx.php、apps/api/database/migrations/ 和 apps/api/database/seeds/，让 Phinx 只通过 Compose 内受控命令执行版本化迁移与种子，禁止应用启动、健康检查和请求路径隐式迁移
- [X] T015 编写 apps/api/database/migrations/20260823000001_create_accounts.php，建立 accounts、learners、staff_users 及唯一性、手机号形态、账户状态和初始改密约束
- [X] T016 编写 apps/api/database/migrations/20260823000002_create_rbac.php，建立 departments、posts、roles、permissions、关系表和用户级权限覆盖表，包含部门树、数据范围和最后超管所需约束
- [X] T017 编写 apps/api/app/model/Account.php、apps/api/app/model/Learner.php、apps/api/app/model/StaffUser.php、apps/api/app/model/Department.php、apps/api/app/model/Role.php、apps/api/app/model/Permission.php，全部继承 support\think\Model
- [X] T018 编写 apps/api/config/redis.php 和 apps/api/app/service/TokenService.php，使用官方 Redis 组件保存哈希令牌、登录族、TTL、轮换、重用吊销、踢单族和踢全部状态
- [X] T019 编写 apps/api/app/service/CaptchaService.php，生成一次性图形验证码，使用 Redis captcha:{id} 保存规范化答案 120 秒，成功、失败、过期和登录成功后均立即删除
- [X] T020 编写 apps/api/app/controller/learner/AuthController.php、apps/api/app/controller/admin/AuthController.php、apps/api/app/route.php 和 apps/api/config/route.php，实现两端独立登录、注册、刷新、退出、首次改密和验证码流程
- [X] T021 编写 apps/api/app/middleware/LearnerAuth.php、apps/api/app/middleware/AdminAuth.php、apps/api/app/middleware/OptionalLearnerAuth.php，逐次请求核验 Redis 访问令牌、受众、过期和吊销状态并隔离两端令牌
- [X] T022 编写 apps/api/app/support/ApiResponse.php、apps/api/app/service/BusinessException.php 和 apps/api/app/support/RequestId.php，统一 JSON envelope、分页、错误码、401/403/404/409 映射和登录入口提示
- [X] T023 编写 apps/api/app/middleware/RequestLogger.php、apps/api/app/support/Logger.php 和 apps/api/config/log.php，输出包含请求 ID、访问者类型、账户 ID、对象、动作和结果的结构化日志，并脱敏密码、令牌、验证码、支付凭据和手机号
- [X] T024 编写 apps/api/app/controller/HealthController.php、apps/api/config/process.php 和 docker/api/entrypoint.sh，实现 MySQL/Redis 健康检查、SIGTERM 优雅停机和稳定的容器退出行为
- [X] T025 编写 apps/api/database/seeds/PermissionSeeder.php 和 apps/api/database/seeds/SuperAdminSeeder.php，种子化 FR-075 最小权限点和首个超级管理员，禁止使用手机号形态后台账号
- [X] T026 编写 packages/contracts/src/auth.ts、packages/contracts/src/captcha.ts、packages/contracts/src/envelope.ts 和 packages/contracts/src/tokenEnvelope.ts，定义两端登录、验证码、分页、错误和令牌的 Zod schema
- [X] T027 编写 apps/web/src/api/http.ts、apps/web/src/api/login.ts、apps/admin/src/api/http.ts 和 apps/admin/src/api/login.ts，集中处理基础地址、超时、Bearer、静默刷新、错误映射和请求取消，不在组件复制鉴权逻辑
- [X] T028 编写 apps/api/tests/AuthTokenTest.php、apps/api/tests/ContainerTest.php 和 apps/api/tests/ThinkOrmStackTest.php，覆盖验证码一次一用/并发竞争/登录后重放、TTL、刷新轮换与重用吊销、踢下线、Redis 失败关闭、无账户锁定和 ORM 依赖边界
- [X] T029 完善 compose.test.yaml、apps/api/phpunit.xml、apps/admin/playwright.config.ts、apps/web/playwright.config.ts 和 Makefile，使 PHP、PHPStan、前端类型、Lint、单测和构建均可在 Compose 内运行
- [X] T030 编写 ops/backup/backup.sh、ops/backup/manifest.sh 和 Makefile 目标，在迁移前通过 Compose 同时备份 MySQL 与 uploads 命名卷，并记录迁移版本、镜像 digest、文件大小和 SHA-256
- [X] T031 编写 ops/backup/migrate.sh、apps/api/phinx.php 和 specs/001-personal-learning-site/quickstart.md，执行迁移前连接/版本/磁盘/备份检查，迁移非零退出时保留日志并停止发布，不自动重试未知半完成迁移
- [X] T032 编写 ops/backup/restore.sh、ops/backup/rehearse-restore.sh 和 compose.test.yaml，使用临时 Compose project 与临时命名卷恢复 MySQL 和 uploads，并验证引用文件、关键读写和健康检查
- [X] T033 编写 apps/api/tests/MigrationSafetyTest.php、apps/api/tests/RestoreRehearsalTest.php 和 ops/backup/README.md，验证干净库/恢复副本的 up/down、失败迁移留痕、manifest 可读性、恢复后前向迁移和补偿路径
- [X] T034 编写 scripts/verify-runtime-boundaries.sh、scripts/verify-migrations.sh 和 apps/api/tests/ContainerTest.php，检查业务代码只使用 support\think\Db 或 support\think\Model，禁止裸 PDO/mysqli、应用自动迁移和手工 schema 路径

**Checkpoint**: 两端可通过图形验证码取得隔离令牌；刷新令牌轮换；失效令牌和登录族可立即吊销；Redis 故障失败关闭；业务 ORM、迁移入口和恢复门禁已经固定。

---

## Phase 3: User Story 16 - 学员使用学习端入口（Priority: P1）

**Goal**: 提供以分类树为首页、学习地图为并列主导航、学习端账号与个人入口分离的学习端壳。

**Independent Test**: 访客打开首页看到分类树并能进入学习地图列表；未登录访问我的学习、收藏、订单或消息时进入学员登录而不是后台登录；登录学员能进入属于自己的个人入口。

- [X] T035 [P] [US16] 实现 apps/api/app/controller/learner/HomeController.php 和 apps/api/app/controller/learner/LearnerController.php，提供首页分类树、站点公开资料和学员个人资料读写接口
- [X] T036 [US16] 实现 apps/web/src/layouts/LearnerLayout.vue、apps/web/src/router/index.ts、apps/web/src/router/guards.ts 和 apps/web/src/App.vue，建立学习端主导航、路由边界、未登录重定向和管理端隔离
- [X] T037 [P] [US16] 实现 apps/web/src/views/auth/LoginView.vue、apps/web/src/views/auth/RegisterView.vue、apps/web/src/stores/auth.ts 和 apps/web/src/api/login.ts，完成手机号、密码、图形验证码登录注册和退出
- [X] T038 [P] [US16] 实现 apps/web/src/views/home/HomeView.vue、apps/web/src/components/CategoryBranch.vue 和 apps/web/src/components/CourseShelfCard.vue，默认展示分类浏览并提供学习地图、个人入口和站点资料入口
- [X] T039 [US16] 实现 apps/web/src/views/me/MyLearningView.vue、apps/web/src/views/me/FavoritesView.vue、apps/web/src/views/me/MyOrdersView.vue、apps/web/src/views/me/MessagesView.vue 和 apps/web/src/views/me/AccountView.vue 的路由壳与空态，消息中心不作为首版发布门禁
- [X] T040 [US16] 编写 apps/web/tests/guards.spec.ts、apps/web/tests/HomeStore.test.ts、apps/web/tests/AppFooter.test.ts 和 apps/api/tests/HomeServiceTest.php，验证首页默认分类树、地图入口、学习端重定向、个人数据隔离和登录接口契约

**Checkpoint**: 学习端入口可独立启动和验收，不依赖管理端路由，也不把消息中心扩展误列入首版核心闭环。

---

## Phase 4: User Story 2 - 管理员在后台组织并发布课程（Priority: P1）

**Goal**: 管理员维护三级分类、课程资料、章节课节和媒体资源，并在完整校验后预览发布。

**Independent Test**: 超级管理员从空白状态创建三级分类、课程、两个章节和 Markdown/PDF/视频课节，完成预览并发布；学习端目录和详情与后台一致。

- [X] T041 [US2] 编写 apps/api/database/migrations/20260823000003_create_catalog.php，建立 categories、courses、chapters、lessons、assets 及排序、状态、部门、价格和外键约束
- [X] T042 [P] [US2] 实现 apps/api/app/model/Category.php、apps/api/app/model/Course.php、apps/api/app/model/Chapter.php、apps/api/app/model/Lesson.php 和 apps/api/app/model/Asset.php，统一继承 support\think\Model
- [X] T043 [US2] 实现 apps/api/app/service/CourseService.php 和 apps/api/app/support/HtmlSanitizer.php，完成三级分类限制、富文本白名单消毒、章节课节排序归档、价格校验、发布前完整性校验和状态转换
- [X] T044 [P] [US2] 实现 apps/api/app/support/storage/LocalAssetStorage.php、apps/api/app/controller/admin/AssetController.php 和 apps/api/app/controller/media/CourseMediaController.php，处理 PDF/视频上传、大小限制、ready/processing/missing/broken 状态和资源替换
- [X] T045 [US2] 实现 apps/api/app/controller/admin/CategoryController.php、apps/api/app/controller/admin/CourseController.php、apps/api/app/route.php 和 specs/001-personal-learning-site/contracts/admin-api.md 的课程管理契约，完成 CRUD、排序、预览、发布、下架和部门范围校验
- [X] T046 [US2] 实现 apps/admin/src/layouts/AdminLayout.vue、apps/admin/src/layouts/AdminMenu.ts、apps/admin/src/views/catalog/CategoryListView.vue、apps/admin/src/views/catalog/CourseListView.vue 和 apps/admin/src/views/catalog/CourseEditView.vue
- [X] T047 [US2] 实现 apps/admin/src/views/catalog/CoursePreviewView.vue、apps/admin/src/views/catalog/CourseCoverUpload.vue 和 apps/admin/src/views/catalog/LessonEditor.vue，确保预览与发布后的学习端内容模型一致
- [X] T048 [US2] 编写 apps/api/tests/CatalogContractTest.php、apps/api/tests/CourseCoverControllerTest.php、apps/api/tests/ImageStorageTest.php、apps/admin/tests/CatalogApi.test.ts 和 apps/admin/tests/CourseCoverUpload.test.ts，覆盖第四级分类、危险 HTML、缺失课节/价格、发布和媒体异常

**Checkpoint**: 后台可发布一门完整课程，且分类、内容、价格、媒体和部门校验均由服务端强制执行。

---

## Phase 5: User Story 1 - 学员发现并学习课程（Priority: P1）

**Goal**: 访客按分类发现公开课程，获权学员阅读 Markdown、查看 PDF、播放视频；未授权用户只能看到摘要或试看。

**Independent Test**: 预置一门发布状态、三级分类和三种课节类型的免费课程，从分类进入详情并依次打开三种课节；另用未授权身份验证非试看内容不泄露。

- [X] T049 [US1] 实现 apps/api/app/service/PublicCatalogService.php、apps/api/app/controller/learner/CatalogController.php 和 apps/api/app/controller/learner/HomeController.php，提供分类逐级浏览、课程详情、价格、目录、评分摘要和仅聚合学习人数
- [X] T050 [US1] 实现 apps/api/app/service/PublicLessonService.php、apps/api/app/controller/learner/LessonController.php 和 apps/api/app/controller/media/CourseMediaController.php，按试看/授权/课程状态鉴权返回 Markdown、PDF 或视频内容
- [X] T051 [P] [US1] 实现 apps/web/src/views/catalog/CategoryView.vue、apps/web/src/views/catalog/CourseDetailView.vue、apps/web/src/views/catalog/AccessGate.vue 和 apps/web/src/views/catalog/CourseOutline.vue
- [X] T052 [US1] 实现 apps/web/src/views/learn/LessonView.vue、apps/web/src/components/MarkdownRenderer.vue、apps/web/src/components/PdfViewer.vue 和 apps/web/src/components/VideoPlayer.vue，显示当前课程/章节/课节并处理媒体异常状态
- [X] T053 [US1] 在 apps/api/app/service/PublicLessonService.php、apps/api/app/controller/learner/QuestionController.php 和 apps/web/src/views/catalog/CourseDetailView.vue 中落实非试看锁定、问答正文保护、公开身份偏好和下架旧链接说明
- [X] T054 [US1] 编写 apps/api/tests/LearnerControllerTest.php、apps/api/tests/HomeServiceTest.php、apps/web/tests/CourseDetailView.test.ts 和 apps/web/tests/LessonView.test.ts，覆盖分类详情、三种内容、试看、未授权 403、媒体缺失和隐私聚合

**Checkpoint**: 访客发现和获权学习的内容消费闭环可独立验收。

---

## Phase 6: User Story 3 - 获取课程并跟踪学习进度（Priority: P1）

**Goal**: 免费立即授权，收费只由支付成功授权；学员可断点继续，Markdown/PDF 主动完成，视频有效观看达到 90% 自动完成。

**Independent Test**: 免费课和收费课分别完成授权，验证 Fake 支付延迟、订单快照、权限、不同课节完成、重新登录后的进度和旧进度不回退。

- [X] T055 [US3] 编写 apps/api/database/migrations/20260823000004_create_learning.php 和 apps/api/database/migrations/20260824000001_add_opened_at_to_lesson_progresses.php，建立 orders、course_entitlements、course_enrollments、lesson_progresses 及并发/唯一约束
- [X] T056 [US3] 实现 apps/api/app/model/Order.php、apps/api/app/model/CourseEntitlement.php、apps/api/app/model/CourseEnrollment.php、apps/api/app/model/LessonProgress.php、apps/api/app/service/EntitlementService.php 和 apps/api/app/service/ProgressService.php
- [X] T057 [US3] 实现 apps/api/app/support/payment/PaymentAdapter.php、apps/api/app/support/payment/FakePaymentAdapter.php、apps/api/app/support/payment/NotifyResult.php 和 apps/api/app/service/OrderService.php，使用 FAKE_PAYMENT_DELAY_MS 默认 3000 毫秒自动产生 success，不提供生产“标记成功”或绕过建权接口
- [X] T058 [US3] 实现 apps/api/app/controller/learner/LearningController.php、apps/api/app/controller/learner/OrderController.php、apps/api/app/controller/internal/PaymentNotifyController.php 和 apps/api/app/route.php，完成开始免费、创建订单、fake 测试 seam、进度、心跳和我的订单接口
- [X] T059 [US3] 实现 apps/web/src/views/me/MyLearningView.vue、apps/web/src/views/me/MyOrdersView.vue、apps/web/src/views/checkout/CheckoutView.vue 和 apps/web/src/composables/useLearningProgress.ts，显示授权状态、价格确认、断点位置、支付状态和重试入口
- [X] T060 [US3] 编写 apps/api/tests/PaymentContractTest.php、apps/api/tests/LearnerControllerTest.php、apps/api/tests/ProgressServiceTest.php 和 apps/api/tests/OrderAdminTest.php，覆盖 success-only Fake、四态测试 seam、快照不可变、授权幂等、90% 视频阈值、完成单调性和调价
- [X] T061 [US3] 编写 apps/web/tests/LearningView.test.ts、apps/web/tests/OrderApi.test.ts 和 apps/web/tests/MyLearningView.test.ts，验证免费/收费入口、支付前锁定、订单状态、断点继续和价格窗口重新确认

**Checkpoint**: 授权、订单和进度可独立验收；真实微信回调不作为首版发布门禁。

---

## Phase 7: User Story 9 - 管理员使用后台工作台（Priority: P1）

**Goal**: 后台用户在权限和数据范围内查看待办，并从工作台进入问答、课程和订单；学员令牌不能访问管理 API。

**Independent Test**: 准备待回答问题、草稿课程和成功订单，后台登录后能从工作台进入对应模块；访客和学员不能看到任何待办或管理数据。

- [X] T062 [US9] 实现 apps/api/app/service/DashboardService.php 和 apps/api/app/controller/admin/DashboardController.php，按功能权限和课程当前部门数据范围返回五类待办，无权限时返回 null 而不是全站数据或伪造的 0
- [X] T063 [US9] 实现 apps/admin/src/views/dashboard/DashboardView.vue、apps/admin/src/api/dashboard.ts 和 apps/admin/src/router/index.ts，展示待办汇总、scope 标识和到对应处理页的入口
- [X] T064 [US9] 在 apps/api/app/middleware/AdminAuth.php、apps/api/app/middleware/LearnerAuth.php 和 apps/api/tests/AuthorizeLeakTest.php 中验证两端受众隔离、未登录后台重定向、学员令牌访问 /api/admin/* 返回无泄露错误
- [X] T065 [US9] 编写 apps/api/tests/DashboardTest.php 和 apps/admin/tests/AdminLayout.test.ts，覆盖无权限模块、部门范围、最近订单、待办链接和超管全量视图

**Checkpoint**: 工作台可用且不会旁路鉴权或泄露待办数据。

---

## Phase 8: User Story 12 - 配置部门、岗位、角色和后台员工（Priority: P1）

**Goal**: 超管配置五级部门、岗位默认角色、直接角色和后台员工，维护首次改密、踢线和最后超管安全边界。

**Independent Test**: 创建两级部门、两个角色、绑定岗位并创建员工；员工登录后同时获得岗位角色和直接角色；验证部门、最后超管和登录族边界。

- [X] T066 [US12] 实现 apps/api/app/service/DepartmentService.php、apps/api/app/service/PostService.php、apps/api/app/service/RoleService.php、apps/api/app/service/StaffService.php 和 apps/api/app/service/OrgPolicy.php，完成树深度、停用、岗位归属、角色启停和账户状态规则
- [X] T067 [US12] 实现 apps/api/app/controller/admin/DepartmentController.php、apps/api/app/controller/admin/PostController.php、apps/api/app/controller/admin/RoleController.php、apps/api/app/controller/admin/StaffController.php 和 apps/api/app/route.php
- [X] T068 [US12] 实现 apps/admin/src/views/org/DepartmentListView.vue、apps/admin/src/views/org/PostListView.vue、apps/admin/src/views/org/RoleListView.vue、apps/admin/src/views/org/StaffListView.vue 和 apps/admin/src/views/auth/FirstPasswordView.vue
- [X] T069 [US12] 在 apps/api/app/controller/admin/AuthController.php、apps/api/app/controller/admin/StaffController.php 和 apps/api/app/service/StaffService.php 中落实首次改密、后台账号非手机号、自己不可删/停用/改权限、最后启用超管保护和踢全部/单个登录族
- [X] T070 [P] [US12] 更新 packages/contracts/src/org.ts、packages/contracts/src/firstPassword.ts、specs/001-personal-learning-site/contracts/admin-api.md 和 apps/admin/src/api/org.ts，定义组织树、岗位角色并集、员工和首次改密契约
- [X] T071 [US12] 编写 apps/api/tests/StaffGuardTest.php、apps/api/tests/RoleControllerTest.php、apps/admin/tests/OrgApi.test.ts、apps/admin/tests/RoleListView.test.ts 和 apps/admin/tests/FirstPasswordView.test.ts，覆盖五级/循环、停用不级联、最后超管、非手机号和踢线

**Checkpoint**: 组织和员工基础就绪，US13/US14 才能基于真实角色和部门范围验收。

---

## Phase 9: User Story 13 - 按功能权限使用后台（Priority: P1）

**Goal**: 后台菜单、路由和 API 同时执行有效功能权限，权限变化对下一次受保护请求生效。

**Independent Test**: 员工只有问答查看/回答权限时能处理问答但看不到课程维护、订单、站点和授权入口；直链和写操作均返回不泄露的 403。

- [X] T072 [US13] 完善 apps/api/app/service/PermissionService.php、apps/api/app/middleware/Authorize.php 和 apps/api/app/service/PermissionOverrideService.php，实现岗位角色与直接角色并集、用户级 grant/deny、deny 优先和超管旁路
- [X] T073 [US13] 完善 apps/admin/src/layouts/AdminMenu.ts、apps/admin/src/router/index.ts、apps/admin/src/router/access.ts 和 apps/admin/src/stores/permission.ts，按有效权限过滤菜单、路由和操作按钮
- [X] T074 [US13] 在 apps/api/app/middleware/Authorize.php、apps/api/app/support/ApiResponse.php 和 apps/api/app/controller/admin/ 中统一实现无权限/不存在资源的安全错误，不返回受限正文或列表行
- [X] T075 [US13] 编写 apps/api/tests/AuthorizeLeakTest.php、apps/api/tests/RbacScopeTest.php、apps/admin/tests/AdminMenu.test.ts、apps/admin/tests/AdminRouteAccess.test.ts 和 apps/admin/tests/StaffOverrideView.test.ts，验证权限变更无需重新登录且服务端仍是最终门禁

**Checkpoint**: 功能权限同时约束前端可见性和后端可执行性。

---

## Phase 10: User Story 14 - 按部门数据范围访问业务数据（Priority: P1）

**Goal**: 实现全部、本部门、本部门及下级、仅本人、指定部门五种范围及其最宽集合合并规则。

**Independent Test**: 准备父部门、子部门、旁支部门和不同创建者课程，使用四种范围及混合角色验证课程、订单、问答、评价和学员列表集合 100% 一致。

- [X] T076 [US14] 实现 apps/api/app/service/DataScopeService.php，按物化路径解析部门、指定部门不含下级、仅本人按 created_by_staff_id、角色范围取并集和用户级授予不扩范围
- [X] T077 [US14] 将数据范围应用到 apps/api/app/service/CourseService.php、apps/api/app/service/LearningMapService.php、apps/api/app/service/QuestionService.php、apps/api/app/service/ReviewService.php、apps/api/app/service/OrderService.php 和 apps/api/app/service/CourseStudentService.php
- [X] T078 [US14] 在 apps/api/app/service/CourseService.php 和 apps/api/app/service/DataScopeService.php 中实现课程改部门后的派生数据跟随新部门、写操作二次校验和仅本人创建者语义
- [X] T079 [US14] 编写 apps/api/tests/DataScopeTest.php、apps/api/tests/CourseScopeIntegrationTest.php、apps/api/tests/RbacScopeTest.php 和 apps/api/tests/AuthorizeLeakTest.php，覆盖五种范围、混合并集、父子/旁支、最后编辑者不可见和越权写入

**Checkpoint**: 功能权限与数据范围均通过后，后台业务数据才可被返回或写入。

---

## Phase 11: User Story 4 - 针对具体课节提问并获得回答（Priority: P2）

**Goal**: 学员绑定课节提问，管理员回答/补充/关闭，授权学员共享问答上下文。

**Independent Test**: 学员提交问题，管理员按状态找到并回答，提问者追问，另一授权学员看到完整上下文，未授权用户无法读取。

- [X] T080 [US4] 编写 apps/api/database/migrations/20260823000005_create_qa.php 和 apps/api/database/migrations/20260825000001_harden_qa_schema.php，建立 questions、question_messages、状态和课程课节外键
- [X] T081 [US4] 实现 apps/api/app/service/QuestionService.php、apps/api/app/controller/learner/QuestionController.php 和 apps/api/app/controller/admin/QuestionController.php，完成课节绑定、状态机、授权学员可见、管理员数据范围和消息事件 seam
- [X] T082 [P] [US4] 实现 apps/web/src/views/learn/QuestionPanel.vue、apps/admin/src/views/qa/QuestionListView.vue、apps/admin/src/api/qa.ts 和 apps/web/src/api/learner.ts，支持提问、回答、追问、补充、筛选和关闭
- [X] T083 [US4] 编写 apps/api/tests/QaSchemaIntegrationTest.php、apps/api/tests/QuestionServiceIntegrationTest.php、apps/admin/tests/QaApi.test.ts、apps/admin/tests/QuestionListView.test.ts 和 apps/web/tests/QuestionPanel.test.ts，覆盖四级关联、未授权 403、完整父子对话和状态变化

**Checkpoint**: 课节问答闭环可独立验收；消息列表的完整交付仍按 US19 的后续扩展边界处理。

---

## Phase 12: User Story 5 - 评价课程并参与树形讨论（Priority: P2）

**Goal**: 已完成课节的学员评价课程，用户和管理员进行最多三级回复，管理员可隐藏/恢复并留下审核记录。

**Independent Test**: 学员评价并编辑，多人形成三级回复，隐藏父评价或回复后公开页面不显示失去上下文的后代，评分摘要同步更新。

- [X] T084 [US5] 编写 apps/api/database/migrations/20260823000006_create_reviews.php 和 apps/api/database/migrations/20260825000002_harden_review_schema.php，建立 reviews、review_replies、visibility、edited 和审核关系
- [X] T085 [US5] 实现 apps/api/app/service/ReviewService.php、apps/api/app/controller/learner/ReviewController.php 和 apps/api/app/controller/admin/ReviewController.php，完成一课一条有效评价、完成条件、树深度、公开身份、评分聚合和隐藏恢复
- [X] T086 [P] [US5] 实现 apps/web/src/views/catalog/ReviewTree.vue、apps/web/src/views/catalog/ReviewReplyBranch.vue、apps/admin/src/views/reviews/ReviewModerateView.vue 和 apps/admin/src/views/reviews/ReviewReplyNode.vue
- [X] T087 [US5] 编写 apps/api/tests/ReviewSchemaIntegrationTest.php、apps/api/tests/ReviewServiceIntegrationTest.php、apps/api/tests/ReviewBugsTest.php、apps/admin/tests/ReviewApi.test.ts、apps/admin/tests/ReviewModerateView.test.ts 和 apps/web/tests/review-tree.spec.ts

**Checkpoint**: 评价、树形回复、编辑、隐藏、恢复和评分聚合可独立验收。

---

## Phase 13: User Story 6 - 按学习地图完成系列课程（Priority: P2）

**Goal**: 管理员编排阶段和课程，发布有效地图；学员按推荐顺序查看状态和进度，但不自动获得收费课程授权。

**Independent Test**: 创建三个阶段的地图并发布，学员加入并完成课程，验证下一步、进度、收费课锁定和下架异常提示。

- [X] T088 [US6] 编写 apps/api/database/migrations/20260823000007_create_maps.php 和 apps/api/database/migrations/20260825000003_harden_map_schema.php，建立 learning_maps、map_stages、map_stage_courses、map_enrollments 及排序/唯一约束
- [X] T089 [US6] 实现 apps/api/app/service/LearningMapService.php、apps/api/app/controller/admin/LearningMapController.php 和 apps/api/app/controller/learner/LearningMapController.php，完成发布校验、非锁步进度、收费课程授权边界和异常步骤
- [X] T090 [P] [US6] 实现 apps/admin/src/views/maps/MapEditorView.vue、apps/web/src/views/maps/MapListView.vue、apps/web/src/views/maps/MapDetailView.vue、apps/admin/src/api/learningMaps.ts 和 apps/web/src/api/learningMaps.ts
- [X] T091 [US6] 编写 apps/api/tests/LearningMapServiceIntegrationTest.php、apps/api/tests/LearningMapRouteTest.php、apps/api/tests/MapSchemaIntegrationTest.php、apps/admin/tests/LearningMapsApi.test.ts、apps/admin/tests/MapEditorView.test.ts、apps/web/tests/MapListView.test.ts 和 apps/web/tests/MapDetailView.test.ts

**Checkpoint**: 地图编排、学习视图、进度计算和课程下架异常可独立验收。

---

## Phase 14: User Story 10 - 管理员核验购买订单（Priority: P2）

**Goal**: 管理端只读核验订单快照和支付状态，确认只有成功订单对应课程访问权。

**Independent Test**: 通过 Fake 测试 seam 准备 succeeded、failed、cancelled、unknown 四种订单，管理员可筛选查看，且只有 succeeded 具备授权；不存在手动成功操作。

- [X] T092 [US10] 实现 apps/api/app/controller/admin/OrderController.php、apps/api/app/service/OrderService.php 和 apps/api/app/route.php，提供按状态筛选、快照详情、课程当前部门范围和只读错误边界
- [X] T093 [US10] 实现 apps/admin/src/views/orders/OrderListView.vue、apps/admin/src/api/orders.ts 和 apps/admin/src/views/orders/OrderDetailView.vue，显示课程、学员公开身份、价格快照、支付状态、时间和重试提示
- [X] T094 [US10] 编写 apps/api/tests/OrderAdminTest.php、apps/api/tests/OrderReadOnlyRouteTest.php、apps/admin/tests/OrderApi.test.ts、apps/admin/tests/OrderListView.test.ts 和 specs/001-personal-learning-site/contracts/payments.md 对照测试，确保无 mark-as-paid 路由且状态与授权一致

**Checkpoint**: 订单核验只读，失败/取消/未知不会被展示为已开通。

---

## Phase 15: User Story 15 - 为单个员工做用户级权限覆盖（Priority: P2）

**Goal**: 超级管理员或具备授权能力者为员工设置 grant/deny，deny 优先且不能自我提权或扩大数据范围。

**Independent Test**: 员工角色包含订单查看时禁用该权限，同时额外授予问答回答；员工能回答范围内问题但不能看订单，评价权限保持有效。

- [X] T095 [US15] 实现 apps/api/app/service/PermissionOverrideService.php 和 apps/api/app/model/StaffPermissionOverride.php，记录对象员工、权限点、effect、操作者和时间并按下一请求生效
- [X] T096 [US15] 在 apps/api/app/controller/admin/StaffController.php、apps/api/app/middleware/Authorize.php 和 apps/api/app/service/PermissionService.php 中实现覆盖 API、自身权限上限、禁止自授权、deny 优先和数据范围不扩张
- [X] T097 [US15] 实现 apps/admin/src/views/org/StaffOverrideView.vue、apps/admin/src/api/org.ts、apps/api/tests/PermissionOverrideServiceIntegrationTest.php 和 apps/api/tests/StaffOverrideApiTest.php，覆盖越权授予、自我提权、立即生效和日志字段

**Checkpoint**: 用户级授权覆盖可独立验收，且不能绕过角色、部门或令牌边界。

---

## Phase 16: User Story 19 - 学员接收站内消息（Priority: P2，后续扩展，非首版发布门禁）

**Goal**: 在首版发布门禁之外，补齐问答变化、进度重置和免费访问取消的学习端消息列表与已读状态；不发送邮件或短信。

**Independent Test**: 开启该后续扩展后，对同一学员产生三类事件，列表出现三条关联消息，打开后未读消失并能返回对应课节或课程；首版只验证事件契约 seam，不以完整消息中心阻塞发布。

- [X] T098 [US19] 编写 apps/api/database/migrations/20260824000004_create_learner_notifications.php 和 packages/contracts/src/notification.ts，定义通知类型、关联对象、未读状态、幂等键和未来消息中心扩展契约
- [X] T099 [US19] 扩展 apps/api/app/service/MessageService.php、apps/api/app/service/QuestionService.php、apps/api/app/service/ProgressService.php 和 apps/api/app/service/EntitlementService.php，在问答状态、进度重置和免费访问取消处发出可追踪的通知事件
- [X] T100 [US19] 实现 apps/api/app/controller/learner/NotificationController.php、apps/api/app/route.php 和 apps/web/src/api/notifications.ts，提供仅本人消息列表、分页、关联资源检查和标记已读接口
- [X] T101 [US19] 实现 apps/web/src/views/me/MessagesView.vue、apps/api/tests/NotificationContractTest.php 和 apps/web/tests/MessagesView.test.ts，覆盖三类通知、未登录重定向、已读状态、关联资源不可用和不向他人泄露

**Checkpoint**: 该阶段是后续增量；在首版策略下不把完整消息中心作为 MVP 核心交付或发布阻塞条件。

---

## Phase 17: User Story 7 - 收藏并分享课程海报（Priority: P3）

**Goal**: 学员收藏/取消收藏课程，访客或学员复制稳定链接，公开课程可生成海报，海报失败仍能分享链接。

**Independent Test**: 登录学员收藏并取消课程，生成海报并打开入口；模拟海报失败时仍能复制稳定课程链接，且下架后不泄露内容。

- [X] T102 [US7] 编写 apps/api/database/migrations/20260823000009_create_share.php，建立 favorites、share_posters、快照字段、唯一收藏和入口状态
- [X] T103 [US7] 实现 apps/api/app/controller/learner/FavoriteController.php、apps/api/app/controller/learner/ShareController.php、apps/api/app/service/FavoriteService.php 和 apps/api/app/service/SharePosterService.php
- [X] T104 [P] [US7] 实现 apps/web/src/views/me/FavoritesView.vue、apps/web/src/views/catalog/ShareBar.vue、apps/web/src/components/SharePosterDialog.vue 和 apps/web/src/api/share.ts
- [X] T105 [US7] 编写 apps/api/tests/FavoriteShareTest.php、apps/web/tests/FavoritesView.test.ts 和 apps/web/tests/ShareBar.test.ts，覆盖幂等收藏、价格/封面快照、失败 fallback、稳定链接和下架保护

**Checkpoint**: 收藏和分享传播能力可独立验收，不能绕过课程访问控制。

---

## Phase 18: User Story 8 - 管理员查看课程学员（Priority: P3）

**Goal**: 管理员按课程查看学员人数、来源、进度和完成状态；公开页面默认只展示聚合人数及主动公开身份。

**Independent Test**: 准备免费加入、付费购买、学习中和已完成学员，管理员筛选准确，普通访客看不到购买信息和精确进度。

- [X] T106 [US8] 实现 apps/api/app/service/CourseStudentService.php、apps/api/app/controller/admin/CourseStudentController.php 和 apps/api/app/controller/admin/LearnerController.php，提供课程学员筛选、账户摘要、注册状态、重置进度和范围校验
- [X] T107 [US8] 实现 apps/admin/src/views/students/CourseStudentView.vue、apps/admin/src/views/students/LearnerListView.vue、apps/admin/src/api/courseStudents.ts 和 apps/admin/src/api/learners.ts，展示来源/进度/完成状态并提供权限隔离的管理操作
- [X] T108 [US8] 编写 apps/api/tests/CourseStudentTest.php、apps/admin/tests/CourseStudentView.test.ts、apps/admin/tests/LearnerApi.test.ts 和 apps/web/tests/CourseDetailPrivacy.test.ts，验证筛选、公开同意、聚合人数、重置记录和学员数据隔离

**Checkpoint**: 学员运营视图可用且不向公开课程页泄露私人数据。

---

## Phase 19: User Story 11 - 管理员维护站点公开资料（Priority: P3）

**Goal**: 管理员维护站点名称/简介/展示字段，查询并恢复内容审核记录。

**Independent Test**: 后台更新公开名称和简介，访客刷新后看到新值；隐藏评价后在审核记录中看到原因/操作者/时间，再恢复公开。

- [X] T109 [US11] 编写 apps/api/database/migrations/20260823000010_create_site.php，建立单行 site_profile 和 moderation_logs 结构及对象/动作索引
- [X] T110 [US11] 实现 apps/api/app/controller/admin/SiteController.php、apps/api/app/controller/admin/AuditController.php、apps/api/app/service/SiteService.php 和 apps/api/app/service/ModerationLogService.php
- [X] T111 [P] [US11] 实现 apps/admin/src/views/site/SiteProfileView.vue、apps/admin/src/views/site/AuditLogView.vue、apps/admin/src/api/site.ts 和 apps/admin/src/api/audit.ts，并将公开资料接入 apps/web/src/views/home/HomeView.vue
- [X] T112 [US11] 编写 apps/api/tests/SiteAuditTest.php、apps/admin/tests/SiteProfileView.test.ts、apps/admin/tests/AuditLogView.test.ts 和 apps/web/tests/HomeStore.test.ts，覆盖权限、审核记录、恢复和公开页面更新

**Checkpoint**: 站点资料和审核追溯可独立验收。

---

## Phase 20: User Story 17 - 管理员删除无业务数据的草稿课程（Priority: P3）

**Goal**: 无学习记录和订单的草稿/已下架课程可删除；已发布课程须先下架；有业务数据的课程不可删除。

**Independent Test**: 空白草稿删除成功；已发布课程直接删除被拒；有学习记录或订单的已下架课程删除被拒但仍可继续下架。

- [X] T113 [US17] 在 apps/api/app/service/CourseService.php 和 apps/api/app/model/Course.php 中实现删除前订单/学习记录/授权引用检查、状态门禁和媒体清理策略，并复核 apps/api/database/migrations/20260823000003_create_catalog.php 的外键删除策略
- [X] T114 [US17] 在 apps/api/app/controller/admin/CourseController.php、apps/api/app/support/ApiResponse.php 和 apps/admin/src/views/catalog/CourseListView.vue 中实现 DELETE 路由、明确错误码和二次确认，禁止前端绕过服务层
- [X] T115 [US17] 编写 apps/api/tests/CourseDeletionTest.php、apps/admin/tests/CourseListView.test.ts 和 apps/web/tests/CourseDetailView.test.ts，覆盖空白草稿、已发布先下架、订单/学习记录阻止删除和公开入口不可访问

**Checkpoint**: 删除规则保护学习和交易证据。

---

## Phase 21: User Story 18 - 管理员取消免费课程访问权（Priority: P3）

**Goal**: 管理员可因原因取消免费授权并保留学习记录；付费授权不可取消；学员可再次免费加入并沿用进度。

**Independent Test**: 取消免费授权后非试看立即拒绝，付费授权取消被拒，下架课程的付费访问仍可用，再次免费加入后进度保持。

- [X] T116 [US18] 扩展 apps/api/app/service/EntitlementService.php、apps/api/app/model/CourseEntitlement.php 和 apps/api/app/service/ProgressService.php，实现仅 free 来源可撤销、原因/操作者记录、课程记录保留和再次加入幂等恢复
- [X] T117 [US18] 实现 apps/api/app/controller/admin/CourseStudentController.php、apps/api/app/controller/learner/LearningController.php、apps/api/app/controller/learner/LessonController.php 和 apps/api/app/support/ApiResponse.php，提供撤销、再次加入、原因说明和非试看授权检查
- [X] T118 [P] [US18] 实现 apps/admin/src/views/students/CourseStudentView.vue、apps/admin/src/api/courseStudents.ts、apps/web/src/views/catalog/AccessGate.vue 和 apps/web/src/views/me/MyLearningView.vue 的撤销/重入状态交互
- [X] T119 [US18] 编写 apps/api/tests/EntitlementRevokeTest.php、apps/api/tests/ProgressServiceTest.php、apps/admin/tests/CourseStudentView.test.ts 和 apps/web/tests/MyLearningView.test.ts，覆盖免费/付费来源、下架保留访问、进度沿用、原因和通知事件 seam

**Checkpoint**: 免费访问权取消不会破坏学习记录或误伤付费学员。

---

## Phase 22: Polish & Cross-Cutting Concerns

**Purpose**: 完成契约一致性、性能、安全、镜像、迁移恢复和 OrbStack 发布门禁。

- [X] T120 [P] 对照 specs/001-personal-learning-site/contracts/learner-api.md、specs/001-personal-learning-site/contracts/admin-api.md、specs/001-personal-learning-site/contracts/conventions.md 和 packages/contracts/src/ 全量同步请求/响应、Zod schema、错误码和路由
- [X] T121 [P] 编写 apps/web/tests/e2e/smoke.spec.ts 和 apps/admin/tests/e2e/smoke.spec.ts，使用 Compose 网络跑验证码登录、发布课程、免费学习、Fake 支付、RBAC、数据范围和个人订单核心旅程
- [X] T122 [P] 编写 apps/api/tests/perf/timing.sh，测量浏览、目录、收藏和进度操作的 2 秒响应样本，验证 95% 单用户冒烟达标，并记录 200 并发作为发布后观测项
- [X] T123 [P] 编写 scripts/verify-images.sh、specs/001-personal-learning-site/quickstart.md、docker/api/Dockerfile、docker/admin/Dockerfile 和 docker/web/Dockerfile 的镜像检查，使用 docker buildx imagetools inspect 复核 PHP、Node、nginx、MySQL、Redis 多架构 digest，拒绝 latest、浮动版本和未锁 digest
- [X] T124 编写 apps/api/tests/MigrationReleaseGateTest.php、ops/backup/migrate.sh、ops/backup/rehearse-restore.sh 和 specs/001-personal-learning-site/quickstart.md，完成干净库迁移、失败迁移、备份 manifest、恢复副本、关键约束、phinx status、health 和最小读写验收
- [X] T125 编写 Makefile、compose.yaml、compose.test.yaml、docker/api/entrypoint.sh 和 README.md 的一键命令，验证所有质量检查、构建、启动、迁移、备份、恢复、日志和优雅停机均在 Compose/OrbStack 内运行
- [X] T126 [P] 运行 scripts/verify-runtime-boundaries.sh、scripts/verify-migrations.sh、git diff --check 和密钥扫描，确认无裸 PDO/mysqli、并行 ORM、生产秘密、Session 登录、默认数据库/Redis 暴露和调试旁路
- [X] T127 [P] 在 apps/api/config/log.php、apps/api/app/middleware/RequestLogger.php、apps/api/config/process.php、docker/api/Dockerfile 和 compose.yaml 中完成结构化日志、脱敏、SIGTERM、健康检查、非 root/最小权限和重启策略复核
- [X] T128 执行 specs/001-personal-learning-site/quickstart.md 的 OrbStack 从零验收，汇总 PHPStan、PHPUnit、前端 ESLint、Prettier、vue-tsc、Vitest、契约构建、生产构建、Compose 健康和迁移恢复证据

---

## Dependencies & Execution Order

### Phase Dependencies

- Phase 1 Setup 无依赖，可先并行完成独立脚手架和镜像定义。
- Phase 2 Foundational 依赖 Phase 1，阻塞全部用户故事；ORM、令牌、验证码、迁移入口、备份恢复和 Compose 测试契约必须先完成。
- P1 用户故事依赖 Phase 2；US16 与 US2 可并行，US1 依赖 US2 或等价的课程种子；US3 依赖 Phase 2，以及课程和课节数据模型或等价测试种子，不要求 US1 的前端页面先完成。
- US9 依赖鉴权和至少一组运营数据；US12 依赖组织基础迁移，US13 依赖 US12 的角色/权限数据，US14 依赖 US12 与 US13 的授权执行链。
- P2 用户故事依赖 Phase 2；US4/US5 依赖已发布且可授权的课程，US6 依赖已发布课程，US10 依赖 US3 订单，US15 依赖 US12/US13，US19 依赖问答/进度/授权事件。
- P3 用户故事依赖对应的 P1/P2 能力：US7 依赖 US1，US8 依赖 US3/US14，US11 可在 Phase 2 后开始但审核记录需与 US5 对接，US17 依赖 US2，US18 依赖 US3。
- Phase 22 Polish 依赖所有计划纳入首版的故事；US19 完整消息中心可延后，不阻塞首版发布门禁。

### User Story Dependencies

| 用户故事 | 优先级 | 直接依赖 | 首版状态 |
|---|---|---|---|
| US16 学习端入口 | P1 | Foundational | MVP |
| US2 内容生产 | P1 | Foundational | MVP |
| US1 发现与学习 | P1 | US2 或课程种子 | MVP |
| US3 授权与进度 | P1 | Phase 2 + 课程和课节数据模型或等价测试种子 | MVP |
| US9 后台工作台 | P1 | Foundational + 运营数据 | 首版增量 |
| US12 组织 RBAC | P1 | Foundational | 首版增量 |
| US13 功能权限 | P1 | US12 | 首版增量 |
| US14 数据范围 | P1 | US12 + US13 | 首版增量 |
| US4 课节问答 | P2 | US1 + US13/14 | 后续增量 |
| US5 评价讨论 | P2 | US1 + US13/14 | 后续增量 |
| US6 学习地图 | P2 | US2 + US3 | 后续增量 |
| US10 订单核验 | P2 | US3 + US13/14 | 后续增量 |
| US15 用户级覆盖 | P2 | US12 + US13 | 后续增量 |
| US19 站内消息 | P2 | US4/US3/US18 事件 | 后续扩展，非首版门禁 |
| US7 收藏分享 | P3 | US1 | 后续增量 |
| US8 课程学员 | P3 | US3 + US14 | 后续增量 |
| US11 站点资料 | P3 | Foundational，审核与 US5 对接 | 后续增量 |
| US17 安全删除 | P3 | US2 + US3 | 后续增量 |
| US18 取消免费授权 | P3 | US3 | 后续增量 |

### Parallel Opportunities

- Setup: T002/T003/T004/T005、T006/T007、T009/T010 可并行。
- Foundational: T015/T016/T017、T018/T019、T026/T027、T030/T032 可在依赖满足后并行；迁移执行任务 T031 需等待 T030。
- US16: T035、T037、T038 可并行，T036 负责整合路由和布局。
- US2: T042、T044 可并行；T046/T047 依赖 API 契约完成。
- US1: T051 可与 T049/T050 并行，T052 依赖内容接口。
- US3: T056、T057 可并行；T058 依赖服务与支付端口，T059 可与测试准备并行。
- US9: T062/T063 可并行；T064 是跨端安全校验。
- US12: T066、T067、T068 可按文件边界并行；T069 需统一员工写操作规则。
- US13: T072、T073、T074 需共享权限语义，完成设计后可按后端/前端分工并行。
- US14: T076 与 T077 可先分工，T078 依赖范围解析完成。
- US4/US5/US6: 各自 migration、service/API、前端和测试可按层次分工；US4 的 T082、US5 的 T086、US6 的 T090 可并行。
- US10/US15: 后端 API 和管理端视图可并行，合同测试收口各自阶段。
- US19: T098 的迁移/契约、T099 的事件 seam 可并行；T100/T101 依赖 seam。
- US7/US8/US11/US17/US18: 各故事内部按模型、服务、接口、界面和测试分工，故事之间在依赖满足后可并行。
- Polish: T120/T121/T122/T123/T126/T127 可并行；T124 依赖迁移和备份脚本，T128 等全部证据齐备后执行。

### Per-Story Parallel Examples

- US16: 同时处理 apps/web/src/views/auth/ 和 apps/web/src/views/home/，再由路由任务接入。
- US2: 同时处理 apps/api/app/model/ 与 apps/admin/src/views/catalog/，服务端契约完成后联调。
- US1: 同时处理公开目录 API 与 apps/web/src/views/catalog/，再接入课节播放器。
- US3: 同时处理 apps/api/app/service/ProgressService.php 与 apps/api/app/support/payment/FakePaymentAdapter.php，订单控制器最后整合。
- US9: 同时处理 apps/api/app/service/DashboardService.php 与 apps/admin/src/views/dashboard/。
- US12: 同时处理部门/岗位/角色服务与组织管理端页面，员工安全策略统一在 StaffService.php 收口。
- US13: 同时处理后台菜单权限过滤与 API Authorize 中间件，使用同一权限点 schema。
- US14: 同时处理 DataScopeService.php 与列表服务调用点，最后执行混合范围集成测试。
- US4: 同时处理问答迁移/服务与 QuestionPanel.vue，管理员列表在 API 状态稳定后接入。
- US5: 同时处理评价树 API 与 ReviewTree.vue，审核页面独立接入隐藏/恢复接口。
- US6: 同时处理地图后端模型/服务与 MapEditorView.vue，学员地图页消费稳定契约。
- US10: 同时处理只读订单 API 与 OrderListView.vue，支付状态契约测试单独运行。
- US15: 同时处理 PermissionOverrideService.php 与 StaffOverrideView.vue，最后运行越权边界测试。
- US19: 同时处理通知 schema 与事件 seam；列表/已读接口完成后再接消息页面。
- US7: 同时处理收藏 API 与 FavoritesView.vue，海报失败 fallback 单独验证。
- US8: 同时处理 CourseStudentService.php 与 CourseStudentView.vue，公开隐私测试独立运行。
- US11: 同时处理站点资料 API 与 SiteProfileView.vue，审核日志页面消费 Review/Moderation 数据。
- US17: 同时处理删除规则测试与管理端删除确认 UI，服务端规则完成后联调。
- US18: 同时处理授权撤销服务与后台学员页，学习端 AccessGate.vue 接入原因/重入状态。

---

## Implementation Strategy

### MVP First

1. 完成 Phase 1 Setup 和 Phase 2 Foundational，先验证 Webman 启动、think-orm、令牌/验证码、Compose、迁移备份和恢复路径。
2. 完成 US16 学习端入口、US2 内容生产、US1 发现与课节学习。
3. 完成 US3 免费/收费授权、Fake success 支付和断点进度，形成可演示的学习闭环。
4. 在 T121/T124/T128 的 Compose、迁移恢复和质量门禁通过后停止并验收 MVP。

### Incremental Delivery

1. P1 增量：US9 工作台、US12 组织、US13 功能权限、US14 数据范围。
2. P2 增量：US4 问答、US5 评价、US6 地图、US10 订单核验、US15 用户级覆盖。
3. P3 增量：US7 收藏分享、US8 学员运营、US11 站点资料、US17 安全删除、US18 免费授权撤销。
4. US19 完整消息中心作为后续扩展；首版仅保留可追踪事件契约，不把消息中心实现作为首版发布门禁。
5. 每个故事在自身 Checkpoint 完成独立测试，再合并到全量 Compose 验证。

### Completion Gates

- 运行时业务查询只通过 support\think\Db 或 support\think\Model，配置只来自 apps/api/config/think-orm.php。
- 迁移只通过 Compose 内 Phinx 受控命令执行；迁移前 MySQL 与 uploads 同点备份，失败停止并保留证据，恢复演练通过后才允许破坏性变更。
- 关键镜像均为固定版本与 digest，docker buildx imagetools inspect 结果与发布输入一致。
- 鉴权、契约、静态分析、格式、单测、前端构建、生产镜像、健康检查、优雅停机和 OrbStack quickstart 全部有可复核证据。

---

## Notes

- 不使用 Session、Session Cookie、邮箱登录、学员进入后台或后台账号通过学员注册创建。
- Redis 仅用于令牌、TTL、吊销和图形验证码，不引入消息队列或其他业务缓存。
- FakePaymentAdapter 首版只自动产生 success；失败/取消/未知由非公开测试 seam 验证状态机，真实微信支付适配器不进入首版门禁。
- MySQL DDL 可能隐式提交；生产不执行未经恢复副本验证的 down()，破坏性变化使用 expand/contract、恢复再前向迁移或补偿迁移。
- 本轮已完成并勾选的任务仅限有实现与验证证据的基础设施、ORM、迁移、恢复和镜像门禁；其余用户故事任务保持未完成。
