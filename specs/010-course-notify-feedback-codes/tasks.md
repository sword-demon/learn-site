---
description: "Task list for feature 010-course-notify-feedback-codes"
---

# Tasks: 课程发布通知, 意见反馈与激活码兑换

**Input**: Design documents from `/specs/010-course-notify-feedback-codes/`

**Prerequisites**: plan.md (required), spec.md (user stories), research.md (R1–R10), data-model.md, contracts/, quickstart.md

**Tests**: 本特性 **包含测试任务** — plan.md「Testing」段已明确列出 PHPUnit (`CoursePublishNotifyTest`, `ActivationCodeTest`, `CourseFeedbackTest`) 与 Vitest (学习端 3 个, 管理端 2 个, `packages/contracts`), 且宪章原则 V 要求测试与实现同 commit。每个用户故事阶段先写测试 (运行确认失败), 再实现。

**Organization**: 任务按用户故事分组, 每个故事可独立实现与测试。US2→US3 (兑换需先有码), US4→US5 (处理需先有提交) 为真依赖; 其余故事相互独立。

## Format: `[ID] [P?] [Story] Description`

- **[P]**: 可并行 (不同文件, 不依赖未完成任务)
- **[Story]**: 所属用户故事 (US1–US6)
- 描述中包含确切文件路径

## Path Conventions

- Monorepo: `apps/api` (PHP 8.4 / Webman 2.2 / think-orm / Phinx), `apps/admin` (管理端 Vue 3), `apps/web` (学习端 Vue 3), `packages/contracts` (Zod 共享契约)
- 契约测试: `packages/contracts/src/__tests__/`; 前端组件测试: `apps/*/tests/`; 后端测试: `apps/api/tests/`
- 时区统一 `Asia/Shanghai`; 时间对外输出走服务内 `toIso8601`

---

## Phase 1: Setup (Shared Infrastructure)

**Purpose**: 领域词表落地与基线确认, 不新增项目脚手架 (monorepo 已存在)

- [X] T001 [P] 更新领域词表 CONTEXT.md: 「课程访问权」获得方式由「免费加入或支付成功」扩展为含「激活码兑换」; 新增词条 **激活码**, **兑换**, **课程意见反馈**, **课程发布消息**; Avoid 段落补: 反馈≠评价, 激活码≠优惠券/优惠码, 课程发布消息≠公告/站内信 (research.md R10)
- [X] T002 确认基线: `docker compose up -d --build` 后 `phinx migrate` + `seed:run` 正常, 既有 `make test-api` 与 `make test-web` 全绿 (quickstart.md 前置; 若基线已红, 先报告不得带病开工)

**Checkpoint**: 词汇表与基线就绪, 可进入 Foundational

---

## Phase 2: Foundational (Blocking Prerequisites)

**Purpose**: 单张迁移 + 权限种子, 阻塞全部用户故事

**⚠️ CRITICAL**: 无任一用户故事可在此阶段完成前开工

- [X] T003 创建 Phinx 迁移 `apps/api/database/migrations/20260902000001_course_notify_feedback_codes.php`, 一次覆盖 data-model.md 全部变更: ① `notification_dispatches.type` ENUM 增加 `course_published`, 新增可空 `resource_type` VARCHAR(32) / `resource_id` BIGINT UNSIGNED 及索引 `(resource_type, resource_id)`; ② `course_entitlements.source` ENUM 增加 `activation_code`, 新增可空 `activation_code_id` FK→`activation_codes.id` RESTRICT; ③ 新表 `activation_code_batches` (course_id FK, quantity 1–1000, expires_at NULL, created_by_staff_id, 索引 `(course_id, created_at)`); ④ 新表 `activation_codes` (batch_id FK, course_id FK, `code_hash` CHAR(64) UNIQUE, `code_prefix`/`code_suffix` CHAR(4), status ENUM unused|redeemed|void, expires_at/redeemed_*/voided_* 可空, 索引 `(course_id,status)` `(batch_id)` `(redeemed_by_learner_id)`); ⑤ 新表 `course_feedbacks` (course_id FK, learner_id FK, body_html MEDIUMTEXT, status ENUM pending|processed 默认 pending, processed_*, 索引 `(course_id,created_at)` `(course_id,status)` `(learner_id,created_at)`)。ENUM 一律用显式 `changeColumn`/`MODIFY` 携带旧值+新值 (风险表: 已有库 ENUM 变更), `down()` 完整可回滚, 全部 InnoDB + `utf8mb4_unicode_ci` + 无符号 BIGINT, 迁移与回滚须在容器内实测通过
- [X] T004 [P] 扩展权限种子 `apps/api/database/seeds/PermissionSeeder.php`: 追加 `activation_code.manage` 与 `course_feedback.manage` (module 均为 `catalog`), 同步超级管理员角色拥有这两个权限点

**Checkpoint**: Foundation ready — US1 / US2 / US4 可并行开工; US3 等 US2, US5 等 US4

---

## Phase 3: User Story 1 - 课程发布后自动通知学员并可跳转详情 (Priority: P1) 🎯 MVP

**Goal**: 课程进入已发布后自动向全体在册学员 fan-out `course_published` 收件箱消息 (可跳转课程详情, 在线走 webman/push), 发布 HTTP 与全体写入解耦, 支持失败补投

**Independent Test**: 预置在册学员 (含在线一名, 离线一名), 管理员发布新课: 发布 API 数秒内返回; 在线学员未读增加并点进该课详情; 离线学员稍后仍能看到同一条未读; 仅编辑已发布课不产生新消息; 下架再发布产生新一条; 入队失败时课程仍为 published 且可 `POST /notifications/{id}/retry`

### Tests for User Story 1

- [X] T005 [P] [US1] 编写 `apps/api/tests/CoursePublishNotifyTest.php` 并确认失败: 覆盖 发布→建 `course_published` dispatch (recipient_mode=all, resource_type=course, sender=发布者) → fan-out 后每名在册学员 inbox 一条 `kind=course_published` 且 `resource_type/resource_id` 从 dispatch 复制、`idempotency_key={dispatch_id}:{learner_id}` 无重复; 幂等重发已 published 早返回不建 dispatch; 编辑简介/目录不触发; 下架→再发布新建 dispatch 并存; 停用/删除学员不在收件人; 队列入队异常时课程保持 published、dispatch `fan_out_status=failed`、HTTP 仍 200; `retryFanOut` 对 failed/pending 重新入队、对 completed/running 抛 `DISPATCH_NOT_RETRYABLE`; 学员间消息隔离 (跨学员读取拒绝) (FR-001/002/005/008/009)
- [X] T006 [P] [US1] 扩展 `packages/contracts/src/notification.ts` (`LearnerNotificationKind` 增加 `course_published`) 与 `packages/contracts/src/adminNotification.ts` (`NotificationType` 增加 `course_published`; `AdminNotificationListItemDTO` 增加可空 `resource_type`/`resource_id`/`fan_out_status`/`fan_out_done_count`), 并在 `packages/contracts/src/__tests__/` 补 Zod 解析测试 (含旧字段缺失容忍) 后确认失败

### Implementation for User Story 1

- [X] T007 [US1] 实现 `NotificationDispatchService::sendCoursePublished(course, staffId)` 于 `apps/api/app/service/NotificationDispatchService.php`: 校验课程存在, 写 dispatch (`type=course_published`, `resource_type=course`, `resource_id`, `recipient_mode=all`, `fan_out_status=pending`) 并经 `JobDispatcher` 入队 `notification.fan_out`; 捕获入队异常 → dispatch 置 `failed` + 结构化日志, 不向调用方抛队列错误 (R1); 同文件实现 `retryFanOut(dispatchId)`: 仅 `failed|pending` 可重试, 重新入队, 其余抛 `DISPATCH_NOT_RETRYABLE` (R3)
- [X] T008 [US1] 扩展 `apps/api/app/service/NotificationFanOutExecutor.php`: 写 inbox 行时从 dispatch 复制 `resource_type`/`resource_id` (不再写 NULL); `type=course_published` 的收件人 = 全体在册学员 (状态正常可登录的账户), 沿用 chunk 250 + `idempotency_key={dispatch_id}:{learner_id}` 幂等 + 每 chunk 后投递既有 `notification.push` job (R1/R2); 未读计数仍走 `UnreadCounterService`
- [X] T009 [US1] 编排 `apps/api/app/service/CourseService.php::publishCourse`: 状态实际从非 `published` 变为 `published` 后调用 `sendCoursePublished`; 已是 published 的幂等早返回不得再建 dispatch; publishCourse 只编排「改状态 + 触发投递」, 投递细节留在 NotificationDispatchService
- [X] T010 [US1] 扩展 `apps/api/app/controller/admin/NotificationController.php` 与 `apps/api/app/route.php`: 列表 `type` 支持 `course_published` 筛选, 列表/详情返回 `resource_type`/`resource_id`/`fan_out_status`/`fan_out_done_count`/`fan_out_error`, `recipient_summary` 对 course_published 显示「全体在册学员」; 新增 `POST /api/admin/v1/notifications/{id}/retry` (权限 `notification.manage`, 成功返回 dispatch 详情, 非 failed/pending → 409 `DISPATCH_NOT_RETRYABLE`)
- [X] T011 [P] [US1] 扩展 `apps/admin/src/api/notifications.ts`: 列表项类型/Zod 补 `resource_type`/`resource_id`/`fan_out_status`/`fan_out_done_count`/`fan_out_error`, 新增 `retryNotification(id)` 调用
- [X] T012 [US1] 扩展 `apps/admin/src/views/notifications/NotificationListView.vue`: 类型筛选增加「课程发布」; 列表展示关联课程与 fan-out 状态 (pending/running/completed/failed), failed 行提供「重试投递」按钮, 处理 409 `DISPATCH_NOT_RETRYABLE` 友好提示; 「发公告/站内信」表单不得出现 course_published 类型
- [X] T013 [P] [US1] 扩展 `apps/web/src/views/me/StudentCenterView.vue`: `kindLabel`/`kindTagType` 增加 `course_published` → 标签「新课」; push 事件 `kind=course_published` 与 REST 拉取同一展示路径 (R9: 不改 tab 派生, 跳转沿用既有 `resource_type=course` → `/courses/{id}` 与 `resource_available` 规则)
- [X] T014 [US1] 扩展 `apps/web/tests/StudentCenterView.test.ts`: 课程发布消息展示「新课」标签、未读样式与既有消息一致、点击进入 `/courses/{id}`、课程不可用时显示「关联内容已不可用」不展示受限正文 (spec 场景 6)

**Checkpoint**: User Story 1 独立可用 — 发布一门课即可在两端走通「发布→消息→跳转详情」, 并验证补投; 此时 MVP 可交付

---

## Phase 4: User Story 2 - 管理员为课程生成激活码 (Priority: P1)

**Goal**: 管理员对已发布收费课程按批生成一次性激活码 (可选过期时间), 列表脱敏可筛选分页, 未兑换码可作废, 全程审计

**Independent Test**: 对一门收费已发布课生成 10 枚带过期时间的码, 作废其中 2 枚: 生成响应一次性返回明文, 列表只有 `XXXX****XXXX` 形态与状态统计, 审计含 batch_create 与 void, 已作废码不可兑换 (兑换拒绝在 US3 验证)

### Tests for User Story 2

- [X] T015 [P] [US2] 编写 `apps/api/tests/ActivationCodeTest.php` 生成/列表/作废部分并确认失败: 免费课或草稿/已下架课生成 → 422 `COURSE_NOT_PUBLISHED`/`COURSE_NOT_PAID`; quantity 0/1001 → `ACTIVATION_CODE_QUANTITY_INVALID`; expires_at 早于等于当前上海时间 → `ACTIVATION_CODE_EXPIRES_INVALID`; 生成的码彼此不同且只绑该课、初始 unused; 列表无明文字段、`display_code=prefix+'****'+suffix`、status 筛选含派生 expired (`unused AND expires_at<=now`) 与分页; 作废 unused 成功且写审计, 已兑换码作废 → 409 `ACTIVATION_CODE_NOT_VOIDABLE`; 无 `activation_code.manage` 或超出课程数据范围 → 403 且不泄露明文 (FR-010/012/019, spec US2 场景 1–7)

### Implementation for User Story 2

- [X] T016 [P] [US2] 新建 `packages/contracts/src/activationCode.ts`: 管理端 `ActivationCodeBatchCreatedDTO` (含一次性 `codes[]` 明文), `AdminActivationCodeItemDTO` (`display_code`, status 含派生 expired, `redeemed_by` 可空脱敏对象), 生成请求 (quantity 1–1000, 可空 expires_at) 与列表查询/作废响应 Zod; 在 `packages/contracts/src/index.ts` 导出; `packages/contracts/src/__tests__/` 补解析测试后确认失败
- [X] T017 [US2] 新建 think-orm 模型 `apps/api/app/model/ActivationCodeBatch.php` 与 `apps/api/app/model/ActivationCode.php` (继承 `support\think\Model`, 字段类型/时间戳按 data-model.md, 状态常量与 `isExpired(now)` 派生判断放模型)
- [X] T018 [US2] 实现 `apps/api/app/service/ActivationCodeService.php` 的 `generateBatch(courseId, quantity, expiresAt, staffId)` / `listCodes(courseId, filters, page, limit)` / `voidCode(courseId, codeId, staffId)`: 生成前校验课程 `status=published` 且 `price_mode=paid` + `DataScopeService::assertCourseAccessibleFromScope`; 码文 16 位 Crockford Base32 (去 I/L/O/U), 展示 `XXXX-XXXX-XXXX-XXXX`, 规范化=去横线大写, `code_hash=SHA-256` 唯一约束冲突重试, prefix/suffix 各 4 位, 明文仅存于返回值; expires_at 有值必须晚于当前上海时间并复制到每枚码; 作废仅 unused (含已过期未兑换), 事务内改状态; 三个方法分别 `writeAudit('activation_code.batch_create'/'activation_code.void', ...)` 且审计详情不含明文 (R4, data-model 校验段)
- [X] T019 [US2] 新建 `apps/api/app/controller/admin/ActivationCodeController.php` 并在 `apps/api/app/route.php` 注册: `POST /courses/{courseId}/activation-code-batches` (201 返回批次+`codes[]` 明文), `GET /courses/{courseId}/activation-codes` (page/limit≤100/status 筛选), `POST /courses/{courseId}/activation-codes/{codeId}/void` (200 `{voided:true}`); 中间件挂 `Authorize`(`activation_code.manage`) + 管理端令牌; 错误映射按 contracts/admin-activation-codes.md (404 `COURSE_NOT_FOUND`, 403 `FORBIDDEN`, 422 校验码, 409 `ACTIVATION_CODE_NOT_VOIDABLE`)
- [X] T020 [P] [US2] 新建 `apps/admin/src/api/activationCodes.ts`: createBatch/listCodes/voidCode 三个调用 + 响应 Zod 校验, 复用 `apps/admin/src/api/http.ts` 客户端
- [X] T021 [P] [US2] 扩展 `apps/admin/src/router/index.ts` 与 `apps/admin/src/layouts/AdminMenu.ts`: 新路由 `/courses/:id/activation-codes` (与 `/courses/:id/students` 并列, 不进 CourseEditView, 不新增顶栏模块), 课程管理相关入口增加「激活码」链接, 路由守卫要求 `activation_code.manage`
- [X] T022 [US2] 新建 `apps/admin/src/views/catalog/CourseActivationCodesView.vue`: 生成对话框 (数量 1–1000 + 可选过期时间 datetime, 提交校验), 生成成功弹窗一次性展示并复制全部明文 (关闭后不再可见), 列表脱敏 `display_code` + 状态筛选 (unused/redeemed/void/expired) + 分页, 未使用行「作废」按钮带确认, 兑换学员公开身份脱敏展示
- [X] T023 [US2] 新建 `apps/admin/tests/CourseActivationCodesView.test.ts`: 生成表单校验、明文仅生成后展示一次、列表脱敏渲染、状态筛选发起对应查询、作废确认与 409 错误提示

**Checkpoint**: User Story 2 独立可用 — 生成/查看/作废/审计闭环成立 (兑换行为在 US3 验证)

---

## Phase 5: User Story 3 - 学员兑换激活码获得课程访问权 (Priority: P1)

**Goal**: 学员提交有效激活码立即获得与支付同等的课程访问权 (source=activation_code), 无订单; 一码一兑, 已有授权不耗码; 并发与限流受控

**Independent Test**: 学员 (无该收费课访问权) 经 `POST /api/learner/v1/activation-codes/redeem` 兑换一枚有效码 → 立即可学完整课节、无购买订单、学员名单获得方式=激活码兑换; 同码再兑被拒; 已购学员兑另一枚有效码被拒且码保持 unused; 并发同码恰好一人成功

**依赖**: 需要 Phase 4 的 `ActivationCodeService` 与两张码表 (T017/T018) 先完成

### Tests for User Story 3

- [X] T024 [P] [US3] 扩展 `apps/api/tests/ActivationCodeTest.php` 兑换部分并确认失败: 有效码兑换 → 200 `{granted:true,course_id,course_title,source:"activation_code"}` 且 entitlement `source=activation_code`、`order_id` 为 NULL、无成功订单、码变 redeemed 记录学员与时间; `ACTIVATION_CODE_INVALID`(不存在/格式非法)/`ACTIVATION_CODE_REDEEMED`/`ACTIVATION_CODE_VOID`/`ACTIVATION_CODE_EXPIRED`/`ACTIVATION_CODE_COURSE_UNAVAILABLE`(绑定课非已发布)/`ENTITLEMENT_ALREADY_ACTIVE`(已有授权且码保持 unused) 各分支; 大小写与横线不影响兑换; 两学员并发同一码仅一人成功 (FOR UPDATE 语义); 兑换写审计 `activation_code.redeem`; 未登录 401 (FR-011/013/015/016, spec US3 场景 1–8)

### Implementation for User Story 3

- [X] T025 [P] [US3] 扩展 `packages/contracts/src/activationCode.ts`: 学习端 `RedeemRequest`/`RedeemResultDTO` 与错误码枚举; 扩展 `packages/contracts/src/courseStudent.ts`: 学员名单 `source` 增加 `activation_code` (展示「激活码兑换」); `packages/contracts/src/__tests__/` 补测试后确认失败
- [X] T026 [US3] 扩展 `apps/api/app/service/EntitlementService.php` 与 `apps/api/app/model/CourseEntitlement.php`: `grant` 接受 `source=activation_code` 且不要求 `order_id` (source=activation_code 时 order_id 必须 NULL, source=purchase 仍要求), PHPDoc union 同步; `isRevocable()` 保持仅 `source=free` (FR-018: 兑换产生的访问权与支付同等, 不可按免费加入取消)
- [X] T027 [US3] 在 `apps/api/app/service/ActivationCodeService.php` 实现 `redeem(learner, codeInput)`: 规范化 (去空白/横线→大写→SHA-256) 查不到 → `ACTIVATION_CODE_INVALID`; 事务内 `SELECT ... FOR UPDATE` 锁行; 按序校验 void/redeemed/过期/课程非已发布/学员已 active 授权 (抛 `ENTITLEMENT_ALREADY_ACTIVE`, **不改码**), 全部通过后 `EntitlementService::grant(...,'activation_code')` + 行置 redeemed (redeemed_by/redeemed_at) + `writeAudit('activation_code.redeem')`, 单事务提交 (data-model 兑换事务顺序 1–6; grant 幂等路径不得用于耗码, R5)
- [X] T028 [US3] 扩展 `apps/api/app/service/CourseStudentService.php`: 学员名单支持按获得方式筛选且新增 `activation_code` 值, 脱敏规则不变 (FR-014)
- [X] T029 [US3] 新建 `apps/api/app/controller/learner/ActivationCodeController.php` 并在 `apps/api/app/route.php` 注册 `POST /api/learner/v1/activation-codes/redeem` (学员令牌); 在 `apps/api/app/middleware/RateLimit.php` 增加该路由规则: 每学员 (无 token 则 IP) 60 秒 8 次, 超限 429 `RATE_LIMITED`, Redis 不可用 fail-open (R6); 错误响应按 contracts/learner-activation-codes.md 可区分但不泄露额外信息
- [X] T030 [P] [US3] 新建 `apps/web/src/api/activationCodes.ts`: `redeemActivationCode(code)` 调用 + Zod 校验, 错误码映射为可理解中文提示 (已兑换/已作废/已过期/课程不可用/已有访问权/限流)

**Checkpoint**: User Story 3 独立可用 — quickstart 场景 2 的 curl 序列全部通过 (兑换/拒绝分支/并发/名单来源)

---

## Phase 6: User Story 4 - 学员提交课程意见反馈 (Priority: P2)

**Goal**: 持有效课程访问权的学员在课程详情提交富文本意见反馈, 服务端消毒, 仅运营可见, 与公开评价零共享

**Independent Test**: 有访问权学员提交含段落与列表的反馈成功; 公开评价区不出现该内容; 无访问权学员提交被拒 `COURSE_ACCESS_REQUIRED`; 含脚本标签的提交被消毒后安全渲染

### Tests for User Story 4

- [X] T031 [P] [US4] 编写 `apps/api/tests/CourseFeedbackTest.php` 提交部分并确认失败: 有 active 访问权学员提交非空富文本 → 201 `{id,course_id,status:"pending",created_at}`; 无访问权/未登录 → 403 `COURSE_ACCESS_REQUIRED` / 401; 课程不存在或学员不可见 → 404 `COURSE_NOT_FOUND`; 空/仅空白/消毒后可见文本为空 → 422 `FEEDBACK_BODY_REQUIRED`; 原始长度 >20000 → 422 `FEEDBACK_BODY_TOO_LONG`; `<script>` 等危险内容入库前被 `HtmlSanitizer` 白名单消毒; 同学员同课多条允许互不影响; `reviews` 表无任何关联写入 (FR-021/022/023/024/027, spec US4 场景 1–6)

### Implementation for User Story 4

- [X] T032 [P] [US4] 新建 `packages/contracts/src/courseFeedback.ts`: 学习端 `FeedbackSubmitRequest` (body_html 必填) 与 `FeedbackCreatedDTO` Zod, 在 `packages/contracts/src/index.ts` 导出, `packages/contracts/src/__tests__/` 补测试后确认失败
- [X] T033 [US4] 新建 think-orm 模型 `apps/api/app/model/CourseFeedback.php` (status 常量 pending|processed, 时间戳按 data-model.md)
- [X] T034 [US4] 新建 `apps/api/app/service/CourseFeedbackService.php` 的 `submit(courseId, learner, bodyHtml)`: 课程存在性与学员可见性按既有规则 (不可见 → `COURSE_NOT_FOUND` 不泄露草稿); 断言 `course_entitlements.status=active` 否则 `COURSE_ACCESS_REQUIRED`; 原始长度 >20000 先拒 (`FEEDBACK_BODY_TOO_LONG`), 再 `HtmlSanitizer::sanitize` (与课程简介同一白名单), 消毒后可见文本判空 (复用 `richHtml` 判空逻辑) → `FEEDBACK_BODY_REQUIRED`; 只存消毒结果, 不写 `reviews` (R7)
- [X] T035 [US4] 新建 `apps/api/app/controller/learner/CourseFeedbackController.php` 并在 `apps/api/app/route.php` 注册 `POST /api/learner/v1/courses/{courseId}/feedback` (学员令牌, 错误映射按 contracts/learner-course-feedback.md)
- [X] T036 [P] [US4] 新建 `apps/web/src/api/courseFeedback.ts`: `submitCourseFeedback(courseId, bodyHtml)` + Zod 校验, 复用 `apps/web/src/api/http.ts`
- [X] T037 [US4] 扩展 `apps/web/src/views/catalog/CourseDetailView.vue`: `el-tabs` 新增「意见反馈」pane, 仅 `viewer_authorized` 可见; 复用 `CheckinPlanEditor` (WangEditor 封装) 作为富文本输入; 提交前长度/非空前端校验, 提交期间禁用按钮防重复, 成功显示确认; 页面注明正文经服务端消毒
- [X] T038 [US4] 新建 `apps/web/tests/CourseFeedbackSubmit.test.ts`: 无访问权不渲染入口; 空内容前端拦截; 提交成功确认; 提交中防重复点击; 接口错误码展示可理解提示

**Checkpoint**: User Story 4 独立可用 — quickstart 场景 3 的提交与隔离断言全部通过

---

## Phase 7: User Story 5 - 管理员查看与处理意见反馈 (Priority: P2)

**Goal**: 具备 `course_feedback.manage` 的管理员按课查看反馈列表/详情 (安全渲染富文本), 按状态筛选分页, 在待处理/已处理间流转并审计

**Independent Test**: 预置同课 3 条待处理反馈: 列表倒序分页 + 摘要 80 字 + 提交者脱敏; 打开详情见完整消毒 HTML; 标 1 条已处理后刷新保持并写审计; 按状态筛选互斥; 无权限/超数据范围 403 不泄露正文

**依赖**: 需要 Phase 6 的 `CourseFeedbackService`/模型 (T033/T034) 先完成

### Tests for User Story 5

- [X] T039 [P] [US5] 扩展 `apps/api/tests/CourseFeedbackTest.php` 管理端部分并确认失败: 列表 `created_at DESC` 分页 + `status` 筛选 + `body_excerpt` (去标签 ≤80 字, 非原始 HTML) + 提交者公开身份脱敏; 详情返回完整 `body_html` (服务端已消毒) 与 processed_by_staff_id; PATCH `status=processed|pending` 双向更新成功返回详情 DTO 且写审计 `course_feedback.status_change` (含 processed_by/processed_at); 404 `FEEDBACK_NOT_FOUND`; 无权限或超课程数据范围 403 且不泄露正文 (FR-025/026/028, spec US5 场景 1–5)

### Implementation for User Story 5

- [X] T040 [P] [US5] 扩展 `packages/contracts/src/courseFeedback.ts`: 管理端 `AdminFeedbackListItemDTO` (learner/body_excerpt/status/created_at/processed_at) 与 `AdminFeedbackDetailDTO` (body_html/processed_by_staff_id)、PATCH 请求 Zod; `packages/contracts/src/__tests__/` 补测试后确认失败
- [X] T041 [US5] 扩展 `apps/api/app/service/CourseFeedbackService.php`: `listFeedbacks(courseId, status, page, limit)` / `getFeedback(courseId, feedbackId)` / `updateStatus(courseId, feedbackId, status, staffId)`; 三个方法均先过课程数据范围断言; `updateStatus` 维护 processed_by_staff_id/processed_at 并 `writeAudit('course_feedback.status_change')` (FR-026)
- [X] T042 [US5] 新建 `apps/api/app/controller/admin/CourseFeedbackController.php` 并在 `apps/api/app/route.php` 注册: `GET /courses/{courseId}/feedback`、`GET /courses/{courseId}/feedback/{feedbackId}`、`PATCH /courses/{courseId}/feedback/{feedbackId}`; 中间件挂 `Authorize`(`course_feedback.manage`) + 管理端令牌; 错误映射按 contracts/admin-course-feedback.md
- [X] T043 [P] [US5] 新建 `apps/admin/src/api/courseFeedback.ts`: listFeedback/getFeedback/updateFeedbackStatus + Zod 校验
- [X] T044 [P] [US5] 扩展 `apps/admin/src/router/index.ts` 与 `apps/admin/src/layouts/AdminMenu.ts`: 新路由 `/courses/:id/feedback` (与激活码页并列), 课程入口增加「意见反馈」链接, 路由守卫要求 `course_feedback.manage`
- [X] T045 [US5] 新建 `apps/admin/src/views/catalog/CourseFeedbackView.vue`: 列表 (状态筛选 pending/processed, 分页, 摘要, 提交者脱敏昵称, 提交/处理时间), 详情对话框用 `v-html` 渲染 `body_html` (服务端已消毒, R7), 「标记已处理/打回待处理」操作 + 成功刷新 + 错误提示
- [X] T046 [US5] 新建 `apps/admin/tests/CourseFeedbackView.test.ts`: 列表筛选发起对应查询、详情安全渲染 (不执行脚本)、状态切换调用与 403/404 提示、学员公开评价不受影响的前端断言不涉及 reviews

**Checkpoint**: User Story 5 独立可用 — quickstart 场景 3 管理员部分走通; US4+US5 合并验证「提交→查看→处理→审计」

---

## Phase 8: User Story 6 - 学员从消息中心与兑换入口完成日常操作 (Priority: P3)

**Goal**: 学习端提供明确兑换入口 (学员中心 `/me/redeem` 独立 tab + 未授权收费课详情购买区), 未登录引导登录; 消息类型标签可区分且未读幂等

**Independent Test**: 学员消息列表同时有公告/站内信/课程发布消息且类型可区分; 从未拥有访问权的收费课详情能进入兑换并成功; `/me/redeem` 直接访问时 activeTab 正确; 未登录访问兑换引导登录

**依赖**: 需要 US1 (消息 kind 展示) 与 US3 (兑换 API) 先完成

### Implementation for User Story 6

- [X] T047 [US6] 扩展 `apps/web/src/router/index.ts`: 新路由 `/me/redeem` (学员中心子路径), 挂既有学员登录守卫, 未登录重定向登录并回跳 (spec US6 场景 3 + 边界)
- [X] T048 [US6] 扩展 `apps/web/src/views/me/StudentCenterView.vue`: `TAB_BY_PATH` 增加 `/me/redeem` 映射「兑换激活码」tab (H6: activeTab 由 `route.path` 派生, 禁止本地 ref 存 tab 状态); tab 内兑换表单调用 `redeemActivationCode`, 成功提示并给出「去学习」跳转, 失败显示 T030 映射的错误文案
- [X] T049 [US6] 新建 `apps/web/tests/RedeemTab.test.ts`: 访问 `/me/redeem` 时 `route.path` 派生 activeTab 正确 (H6 回归: 测试改 `route.path` 而非手动设 tab); 有效码成功流程; 未登录守卫; 错误码文案
- [X] T050 [P] [US6] 扩展 `apps/web/src/views/catalog/CourseDetailView.vue`: 学员对收费课无访问权时, 购买区域旁提供「使用激活码兑换」入口 (跳转 `/me/redeem` 或内联表单, 兑换成功后刷新授权状态无需再支付); 访客点击引导登录 (spec US6 场景 2)
- [X] T051 [P] [US6] 扩展 `apps/web/src/views/catalog/AccessGate.vue` (可选增强, plan 标注可选): 付费未授权 gate 态增加兑换引导入口, 与购买按钮并列, 文案遵循领域词 (不出现「优惠券」)

**Checkpoint**: 全部 6 个用户故事独立可用; 学习端三大入口 (消息/学员中心/课程详情) 闭环

---

## Phase 9: Polish & Cross-Cutting Concerns

**Purpose**: 审计完整性、限流与并发复核、端到端走查与门禁

- [X] T052 [P] 审计与脱敏复核 (apps/api/app/service/ActivationCodeService.php, CourseFeedbackService.php, NotificationDispatchService.php): `activation_code.batch_create`/`activation_code.void`/`activation_code.redeem`/`course_feedback.status_change` 四类审计齐备且详情不含激活码明文或反馈全文; 生成响应 `codes[]` 不进任何日志 (plan 风险表最后两行)
- [ ] T053 执行 `specs/010-course-notify-feedback-codes/quickstart.md` 场景 1–4 全量走查: 双端手工验证 + curl 断言; 场景 4 覆盖 dispatch `pending→running→completed`、无重复 `idempotency_key`、入队失败课程不回滚且 retry 可用 (SC-001–003 抽查)
- [ ] T054 全量门禁: `make test-api` 与 `make test-web` 全绿; 两前端 ESLint/Prettier/类型检查/生产构建通过; `apps/api` PHP 静态分析通过 (宪章原则 V / 开发流程门禁 3)
- [ ] T055 [P] 更新 README 与 docs: 补充课程发布通知、激活码兑换、意见反馈三能力的用户说明与权限点 (沿用仓库既有 docs 约定)

---

## Dependencies & Execution Order

### Phase Dependencies

- **Setup (Phase 1)**: 无依赖, 立即开始
- **Foundational (Phase 2)**: 依赖 Setup; **阻塞全部用户故事**
- **US1 (Phase 3)**: 仅依赖 Foundational
- **US2 (Phase 4)**: 仅依赖 Foundational
- **US3 (Phase 5)**: 依赖 Foundational + **US2** (T017/T018 的码表模型与 service)
- **US4 (Phase 6)**: 仅依赖 Foundational
- **US5 (Phase 7)**: 依赖 Foundational + **US4** (T033/T034 的反馈模型与 service)
- **US6 (Phase 8)**: 依赖 **US1** (消息 kind) + **US3** (兑换 API)
- **Polish (Phase 9)**: 依赖全部所需故事完成

### User Story Dependencies

```text
Phase 1 → Phase 2 ─┬─→ US1 (P1) ──────────────┐
                   ├─→ US2 (P1) → US3 (P1) ───┼─→ US6 (P3) → Phase 9
                   └─→ US4 (P2) → US5 (P2) ───┘
```

- US1/US2/US4 三条线在 Foundational 后 **完全并行**; US3/US5/US6 各自只有单前驱

### Within Each User Story

- 测试任务 (PHPUnit / contracts Vitest) 先写并确认失败, 再实现
- 模型 → service → controller/route → 前端 api → 视图 → 视图测试
- 契约 (packages/contracts) 与后端可并行, 但前端 api 模块消费契约

### Parallel Opportunities

- Phase 1: T001∥T002 无冲突 (T002 是环境验证)
- Phase 2: T004∥T003 (不同文件)
- 跨故事: US1∥US2∥US4 全程三线并行 (分别只碰 notifications/codes/feedbacks 各自文件)
- 故事内 [P]: 各故事的测试任务、contracts 任务、前端 api 模块与后端 service 互不依赖可并行
- 单人串行建议顺序: T001→T002→T003→T004→US1→US2→US3→US4→US5→US6→Polish (P1 全部完成即商业闭环, P2 反馈独立追加, P3 入口收尾)

---

## Parallel Example: US1 与 US2/US4 并行

```bash
# Foundational 完成后, 三条故事线可同时启动:
Agent A (US1): T005 → T006 → T007 → T008 → T009 → T010 → T011 → T012 → T013 → T014
Agent B (US2): T015 → T016 → T017 → T018 → T019 → T020 → T021 → T022 → T023
Agent C (US4): T031 → T032 → T033 → T034 → T035 → T036 → T037 → T038
# 各线只改自己的测试/service/controller/视图文件; T003 迁移与 T004 种子是共同前置, 不得重复改动
```

---

## Implementation Strategy

### MVP First (User Story 1 Only)

1. 完成 Phase 1: Setup (T001–T002)
2. 完成 Phase 2: Foundational (T003–T004) — 阻塞全部故事
3. 完成 Phase 3: US1 (T005–T014)
4. **STOP and VALIDATE**: quickstart 场景 1 独立走查 (发布→消息→跳转→补投)
5. 可部署/演示 (发布通知闭环)

### Incremental Delivery

1. Setup + Foundational → 基线就绪
2. US1 → 独立验证 → **MVP** (课程发布智能推送)
3. +US2 → 生成/作废/审计可用
4. +US3 → **P1 商业闭环完整**: 免支付获得课程的新渠道上线
5. +US4+US5 → 运营反馈工作流
6. +US6 → 学员入口抛光
7. Polish → 审计复核 + quickstart 全量 + 门禁 → 交付

### Single Developer (Sequential) Order

T001 → T002 → T003 → T004 → (T005…T014) → (T015…T023) → (T024…T030) → (T031…T038) → (T039…T046) → (T047…T051) → T052 → T053 → T054 → T055

---

## Notes

- [P] 任务 = 不同文件、无未完成依赖
- [Story] 标签保证可追溯回 spec.md 的验收场景与 FR 编号
- 每个故事应可独立完成与测试; US3/US5/US6 的单前驱依赖已显式标注
- 实现前确认对应测试失败; 每个任务或逻辑组完成后 commit
- 任意 Checkpoint 可停下独立验证该故事
- 禁止: 把意见反馈写入 `reviews`; 把激活码叫优惠券; 兑换走 `grant()` 幂等路径耗码; 发布失败回滚课程状态; 激活码明文入库或进审计/日志; 新增第二套推送/队列/ORM
