---
description: "Task list for 016-course-distribution"
---

# Tasks: 课程分销方案

**Input**: Design documents from `/specs/016-course-distribution/`

**Prerequisites**: plan.md, spec.md, research.md, data-model.md, contracts/, quickstart.md

**Tests**: 依据 CLAUDE.md「新增视图/service/composable 必须同 commit 提交对应 vitest/phpunit 测试」要求, 每个用户故事强制带测试任务 (TDD).

**Organization**: 按 user story 分阶段. 顺序 P1 → P2 → P3 → P4 → P5 → P6. 每个故事可独立实现与验证.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: 可并行 (不同文件, 无依赖)
- **[Story]**: 所属 user story (US1~US6)
- 文件路径绝对路径或相对仓库根

---

## Phase 1: Setup (Shared Infrastructure)

**Purpose**: 创建新文件骨架, 不引入新依赖, 不改既有模块

- [ ] T001 [P] 在 `apps/api/database/migrations/` 下新建 `20260906000001_distribution.php` (仅占位类, 待 Foundational 阶段填充建表)
- [ ] T002 [P] 在 `apps/api/database/seeds/PermissionSeeder.php` 的 PERMISSIONS 数组末尾追加 4 行 distribution 权限点 (占位, 待 Foundational 阶段确认值)
- [ ] T003 [P] 在 `packages/contracts/src/` 下新建空 `distribution.ts` 与 `index.ts` 暂不动 (待 US1 起填 schema)

**Checkpoint**: 骨架文件就位, 无业务逻辑

---

## Phase 2: Foundational (Blocking Prerequisites)

**Purpose**: 任何用户故事开始前必须完成: 5 张新表 + 1 列扩展 + DB 触发器 + CHECK + 站点配置装载 + Zod schema 落地 + 公共 support/validator

**⚠️ CRITICAL**: 完成前不要开始任何 US

- [ ] T004 在 `apps/api/database/migrations/20260906000001_distribution.php` 落 5 张表 + `learners.referrer_learner_id` 列扩展 + 自引用 FK + UNIQUE 索引 + CHECK (level BETWEEN 1 AND 3) + UNIQUE (order_id, referrer_learner_id) + DB 触发器 (禁止 UPDATE referrer_learner_id) + `site_settings.value` CHECK (JSON_EXTRACT(value, '$.level_cap') <= 3)
- [ ] T005 在 `apps/api/database/seeds/PermissionSeeder.php` 写实 4 行: `distribution.config` / `distribution.reconcile` / `distribution.audit` (module=`distribution`)
- [ ] T006 [P] 在 `packages/contracts/src/distribution.ts` 落 7 类 Zod schema: DistributionConfigDTO / CourseOverride / ShareEntry / ShareEntryCreateOutput (含 masked_code 正则) / CommissionRecord / Downline / AdminReconcile / Audit / VoidInput, 金额一律 int cents, phone 一律 `^1[3-9]\*{8}\d{4}$`
- [ ] T007 在 `packages/contracts/src/index.ts` 追加 `export * from "./distribution";`
- [ ] T008 [P] 在 `apps/api/app/support/DistributionConfigValidator.php` 实现 `assertValidConfig(array $cfg)`: 拒绝 level_cap > 3 / 比例越界 / 总佣金超 per_order_cap_cents, 抛 BusinessException 含「合规硬约束」字样
- [ ] T009 [P] 在 `apps/api/app/functions.php` (若无则新建) 增 `maskPhone(string $phone): string` / `maskShortCode(string $code): string` / `todayDate()` / `nowDatetime()` / `toIso8601()` (沿用既有风格)
- [ ] T010 [P] 在 `apps/api/app/model/` 下新建 `ShareEntry.php` / `ShareVisit.php` / `DistributionCourseOverride.php` / `CommissionRecord.php` / `DistributionAuditLog.php` (think-orm Model), 仅含字段映射与表名, 不含业务
- [ ] T011 在 `apps/api/app/model/Learner.php` 增 `referrer_learner_id` 字段映射, 不破坏既有字段
- [ ] T012 [P] 在 `apps/api/tests/DistributionConfigValidatorTest.php` 写 validator 单测: level_cap=4 → 抛错; level1+level2+level3 突破单笔封顶 → 抛错; 合法配置 → 通过
- [ ] T013 [P] 在 `apps/api/tests/DistributionMigrationTest.php` 写 migration 集成测: 跑 up 后 5 张表存在, 列扩展存在, CHECK 拒绝 level_cap=4, 触发器拒绝 UPDATE referrer_learner_id
- [ ] T014 在 `apps/api/app/service/DistributionConfigService.php` 落 `getConfig()` / `updateConfig(input, actorId)` 私有 `writeAudit()`, 写 site_settings key=`distribution_config`
- [ ] T015 [P] 在 `apps/api/app/service/DistributionAuditService.php` 落 `record(action, subjectType, subjectId, before, after, reason, actorType, actorId)`, 唯一写 audit_log 的入口
- [ ] T016 在 `apps/api/tests/DistributionConfigServiceTest.php` 写 service 单测: 默认 enabled=false, 更新后从 site_settings 读回一致, 审计写入, level_cap>3 拒绝
- [ ] T017 跑 `make test-api DistributionConfigServiceTest DistributionConfigValidatorTest DistributionMigrationTest` 全过

**Checkpoint**: 5 张表已建, 站点配置可读写, schema 共享, 校验器就位 — 任何 US 可开始

---

## Phase 3: User Story 1 - 学员生成只属于自己的一次性分享链接 (Priority: P1) 🎯 MVP

**Goal**: 学员生成 / 列出 / 撤销分享入口; 访客 / 学员打开链接记访问痕迹并种 cookie; 注册事务内绑定推荐人

**Independent Test**: 学员 A 生成链接 → 无痕访客打开 → 未注册手机号注册 → A 的 share_entry.bound_count=1, 新学员 referrer_learner_id=A; 第二次注册 → referrer 为 NULL; 老学员 B 打开 → B 的 referrer 不变

### Tests for User Story 1 ⚠️ 写在前, 跑红

- [ ] T018 [P] [US1] 在 `apps/api/tests/ShareEntryServiceTest.php` 写单测: 生成 → 返回 plaintext 一次, 再查只有 masked; 撤销后访问不再记 visit; 一学员多入口互不干扰
- [ ] T019 [P] [US1] 在 `apps/api/tests/ReferralBindingServiceTest.php` 写单测: 同一 visitor_token 多次注册只第一个获得 referrer; 清 cookie 仍按持久化 visitor_token 恢复; 老学员经链接不写 referrer
- [ ] T020 [P] [US1] 在 `apps/api/tests/ShareLandingControllerTest.php` 写控制器测: GET `/s/{shortCode}` 写 share_visits + 种 cookie, 不暴露推荐人信息, scope=course 302 到课程详情; scope=site 302 到首页

### Implementation for User Story 1

- [ ] T021 [P] [US1] 在 `apps/api/app/service/ShareEntryService.php` 落 `create(learnerId, scope, courseId?)` 返回 plaintext_code + share_url (明文仅本次返回), `list(learnerId)` 仅返回 masked_code, `revoke(learnerId, entryId)` 写 revoked_at 与审计
- [ ] T022 [P] [US1] 在 `apps/api/app/service/ShareVisitService.php` 落 `recordVisit(shareEntryId, visitorToken)` 与 `bindVisitorToLearner(visitorToken, learnerId)` (后置由 ReferralBindingService 调)
- [ ] T023 [US1] 在 `apps/api/app/service/ReferralBindingService.php` 落 `bindFromVisitor(int $learnerId): void` — 注册事务第一行调, 按 visitor_token 查 share_visits 拿 share_entry.learner_id, 仅在 `referrer_learner_id IS NULL` 且 ≠ $learnerId 时 UPDATE; 写 share_visits.bound_learner_id + bound_at
- [ ] T024 [P] [US1] 在 `apps/api/app/controller/learner/DistributionController.php` 落路由 `GET /api/learner/distribution/share-entries` / `POST .../share-entries` / `DELETE .../share-entries/{id}`, 仅 JWT 学员身份
- [ ] T025 [P] [US1] 在 `apps/api/app/controller/public/ShareLandingController.php` 落 `GET /api/public/s/{shortCode}` 解析 short_code, 写 share_visits, 种 HttpOnly cookie `distribution_visitor_token` (180 天), 302 到目标页
- [ ] T026 [US1] 在 `apps/api/app/controller/learner/AuthController.php::register` 内, 注册事务第一行后调 `ReferralBindingService::bindFromVisitor($newLearnerId)`
- [ ] T027 [US1] 在 `apps/api/tests/e2e/ShareEntryFlowE2ETest.php` 写端到端: 学员 A 生成 → 无痕注册 → 验证 referrer + bound_count + 审计
- [ ] T028 跑 `make test-api ShareEntryServiceTest ReferralBindingServiceTest ShareLandingControllerTest` 全过

**Checkpoint**: US1 独立可演示 — 学员可生成分享入口, 新注册学员获得推荐人, 老学员不污染

---

## Phase 4: User Story 2 - 管理员配置分销规则与级别上限 (Priority: P1)

**Goal**: 管理端配置页 + 课程级覆盖; 配置按订单快照生效; SC-003 级别上限 ≤ 3 三层防护全覆盖

**Independent Test**: 切换 enabled / 改比例 / 改封顶 / 改课程级覆盖, 旧订单金额不变; 超级管理员尝试 level_cap=4 被拒

### Tests for User Story 2 ⚠️ 写在前

- [ ] T029 [P] [US2] 在 `apps/api/tests/DistributionConfigServiceTest.php` 扩展: updateConfig 后旧订单回放金额不变 (config_snapshot_json 写入); level_cap=4 → BusinessException「合规硬约束」
- [ ] T030 [P] [US2] 在 `apps/api/tests/DistributionCourseOverrideServiceTest.php` 写单测: 单课开关 OFF 后新订单不产生佣金, ON 时按课程级比例算, 全局比例不叠加
- [ ] T031 [P] [US2] 在 `apps/api/tests/LevelCapHardLimitTest.php` 写集成测: 应用层 API 拒绝 / DB CHECK 拒绝 / 单测覆盖三种入口都拒绝 level_cap>3

### Implementation for User Story 2

- [ ] T032 [P] [US2] 在 `apps/api/app/service/DistributionCourseOverrideService.php` 落 `upsert(courseId, input, actorId)` / `list(page, limit)`, 写审计
- [ ] T033 [US2] 在 `apps/api/app/controller/admin/DistributionController.php` 落 `GET /api/admin/distribution/config` / `PUT .../config` / `GET .../course-overrides` / `PUT .../course-overrides/{courseId}` (需 `distribution.config` 权限)
- [ ] T034 [P] [US2] 在 `apps/admin/src/api/distribution.ts` 落 `fetchDistributionConfig` / `saveDistributionConfig` / `fetchCourseOverrides` / `saveCourseOverride`, 全部走 Zod 解析
- [ ] T035 [P] [US2] 在 `apps/admin/src/views/distribution/DistributionConfigView.vue` 落配置页 (Element Plus 表单 + el-alert「合规硬约束」红色提示在 level_cap 输入框旁)
- [ ] T036 [P] [US2] 在 `apps/admin/src/views/distribution/DistributionCourseOverridesView.vue` 落单课覆盖列表 + 行内编辑
- [ ] T037 [P] [US2] 在 `apps/admin/src/router/index.ts` 注册 2 条路由 + 菜单权限点绑定
- [ ] T038 [P] [US2] 在 `apps/admin/tests/DistributionConfigView.test.ts` 写组件测: level_cap 改为 4 提交 → 提示「合规硬约束」; 合法配置保存成功
- [ ] T039 [P] [US2] 在 `apps/admin/tests/DistributionCourseOverridesView.test.ts` 写组件测: 关闭单课开关, 列表显示「OFF」徽标
- [ ] T040 跑 `make test-admin DistributionConfigView DistributionCourseOverridesView` 与 `make test-api DistributionConfigServiceTest DistributionCourseOverrideServiceTest LevelCapHardLimitTest` 全过

**Checkpoint**: US2 独立可演示 — 管理员可在 UI 配置并立即看到生效; 合规硬约束三层防护均验证

---

## Phase 5: User Story 3 - 直系注册链路只向上返三级 (Priority: P1)

**Goal**: 订单 succeeded 钩子触发 CommissionService::settleForOrder; 沿 referee 链路取 ≤ 3 名推荐人; 单笔去重 ≤ 3; 退款时由 voidForOrder 撤销; 推荐人账户不可用时不升级不转赠

**Independent Test**: 5 级链路 (A→B→C→D→E) E 结算 → 仅 C/B/A 拿佣金; D 不拿; 退款 30s 内全部 voided; 推荐人 A 被封禁后, E 的佣金仍指向 A (status=pending_blocked), 不升级给 B

### Tests for User Story 3 ⚠️ 写在前

- [ ] T041 [P] [US3] 在 `apps/api/tests/CommissionSettleTest.php` 写单测: 5 级链路 E 结算 → 仅 3 条记录, 级别 1/2/3 对应 C/B/A; D 与更远无记录; 金额 = min(order_paid × level_pct, 单笔封顶对应份额)
- [ ] T042 [P] [US3] 在 `apps/api/tests/CommissionCapTruncationTest.php` 写单测: 三级比例之和若超单笔封顶 → 按 3→2→1 截断, 截断部分记 audit, 不补发
- [ ] T043 [P] [US3] 在 `apps/api/tests/OrderRefundVoidCommissionTest.php` 写集成测: 订单 succeeded → 退款 → 30 秒内 commission_records 全部 voided (source=system_refund_void), audit 写入 actor_type=system
- [ ] T044 [P] [US3] 在 `apps/api/tests/BlockedReferrerNoUpgradeTest.php` 写单测: 推荐人 A 封禁 → E 结算 → A 的记录 status=pending_blocked, B 不升级, 不转赠
- [ ] T045 [P] [US3] 在 `apps/api/tests/CommissionReceiverUniquenessTest.php` 写并发测: 同订单双结算请求 → UNIQUE 约束保证单一记录, 失败者写入 audit

### Implementation for User Story 3

- [ ] T046 [US3] 在 `apps/api/app/service/CommissionService.php` 落:
  - `settleForOrder(int $orderId)`: 行锁 orders → 取 referee 链路 ≤ 3 → 算金额 → 写 commission_records (pending 或 pending_blocked) → 写 config_snapshot_json 与 order_paid_cents_snapshot
  - `voidForOrder(int $orderId, string $reason)`: 行锁 orders → 全部相关 records 置 voided → 写审计
  - `voidByAdmin(int $commissionId, int $actorId, string $reason)`: 校验 reason ≥ 5 字符 → 写 audit + void
- [ ] T047 [US3] 在 `apps/api/app/service/OrderService.php::markSucceeded` 回调链尾追加 `$this->commission->settleForOrder($orderId)` (沿用现有 payment success handler 链)
- [ ] T048 [US3] 在 `apps/api/app/service/OrderService.php::markRefunded` 回调链尾追加 `$this->commission->voidForOrder($orderId, 'order_refund')`
- [ ] T049 [P] [US3] 在 `apps/api/tests/CommissionReplayTest.php` 写回放测: 取历史 order, 用 snapshot 重算 → 与 commission_records 实际写入金额逐一相等 (SC-011)
- [ ] T050 [US3] 在 `apps/api/app/service/CommissionReplayService.php` 落 `replay(int $orderId): array` 用于 SC-011 验收
- [ ] T051 [P] [US3] 在 `apps/api/tests/e2e/DistributionE2ETest.php` 写 Playwright+PHP 集成: A→B→C→D→E 链路构造 + E 下单 + 退款 + 断言
- [ ] T052 跑 `make test-api CommissionSettleTest CommissionCapTruncationTest OrderRefundVoidCommissionTest BlockedReferrerNoUpgradeTest CommissionReceiverUniquenessTest CommissionReplayTest` 与 `make test-e2e` 全过

**Checkpoint**: US3 独立可演示 — 合规主链 (≤ 3 级 / 单笔 ≤ 3 接收人 / 退款撤销 / 不升级) 全部验证

---

## Phase 6: User Story 4 - 学员查看我的佣金与分享效果 (Priority: P2)

**Goal**: 学习端「我的分销」页: 分享链接列表 / 佣金总览 / 佣金分页记录 / 下级视图 (一级/二级/三级), 全部手机号脱敏

**Independent Test**: A 登录看自己页面看到 B/C/D 脱敏, 看不到 E 之外; 后台把 `learner_can_view_detail` 改 false 后再访问整页隐藏

### Tests for User Story 4 ⚠️ 写在前

- [ ] T053 [P] [US4] 在 `apps/web/tests/DistributionView.test.ts` 写组件测: 列表/总览/记录分页/下级切换正常, 任何位置不出现明文手机号
- [ ] T054 [P] [US4] 在 `apps/web/tests/LearnerPlaintextLeak.test.ts` 写接口层测: 抓取所有 `/api/learner/distribution/*` 响应, 11 位连续手机号匹配数 = 0
- [ ] T055 [P] [US4] 在 `apps/api/tests/LearnerCommissionViewTest.php` 写 service 单测: 学员只能看到与自己 referrer_learner_id = self 的记录; status 过滤生效
- [ ] T056 [P] [US4] 在 `apps/api/tests/LearnerDownlineViewTest.php` 写 service 单测: 按 referrer_learner_id = self 的学员, 沿链路上溯一次 (一级) / 两次 (二级) / 三次 (三级), 第四级与更远不返回

### Implementation for User Story 4

- [ ] T057 [P] [US4] 在 `apps/api/app/controller/learner/DistributionController.php` 增路由 `GET .../commissions` (分页 + 状态过滤) / `GET .../downline` (按 level 过滤)
- [ ] T058 [P] [US4] 在 `apps/web/src/api/distribution.ts` 落 `fetchMyShareEntries` / `createShareEntry` / `revokeShareEntry` / `fetchMyCommissions` / `fetchMyDownline`
- [ ] T059 [US4] 在 `apps/web/src/composables/useDistribution.ts` 落会话状态 composable: 挂载建立 / 卸载撤销, 监听 route.path 变化重拉数据 (符合 CLAUDE.md 多 tab 视图规则)
- [ ] T060 [P] [US4] 在 `apps/web/src/views/DistributionView.vue` 落 4 个区块: 链接列表 / 总览 / 记录分页 / 下级视图, 手机号一律 `{{ mask(phone) }}` 不走原始字符串
- [ ] T061 [P] [US4] 在 `apps/web/src/router/index.ts` 注册 `/distribution` 路由, 守卫: 未登录跳登录; `distribution.enabled` 配置关站时隐藏入口
- [ ] T062 [P] [US4] 在 `apps/web/tests/DistributionApi.test.ts` 写 fetch* 单测: 入参出参严格走 Zod, 任何错误响应不抛白
- [ ] T063 跑 `make test-web DistributionView DistributionApi LearnerPlaintextLeak` 与 `make test-api LearnerCommissionViewTest LearnerDownlineViewTest` 全过

**Checkpoint**: US4 独立可演示 — 学员可看到自己的分销全貌, 全链路零明文

---

## Phase 7: User Story 5 - 管理员审计、撤销与对账 (Priority: P2)

**Goal**: 管理端对账页按订单查询, 审计页按时间/动作筛选, 管理员撤销单条佣金, 4 级接收人异常时被硬拒

**Independent Test**: 篡改单笔订单的接收人数到 4 → 对账页/撤销入口直接拒绝并写审计; 撤销原因 < 5 字符 → 拒绝

### Tests for User Story 5 ⚠️ 写在前

- [ ] T064 [P] [US5] 在 `apps/admin/tests/DistributionReconcileView.test.ts` 写组件测: 输入订单号 → 显示 receivers 列表; 4 个接收人异常 → 红字提示「数据完整性硬约束」且撤销按钮置灰
- [ ] T065 [P] [US5] 在 `apps/admin/tests/DistributionAuditView.test.ts` 写组件测: 按时间/动作筛选生效; 每条带 actor 与 reason
- [ ] T066 [P] [US5] 在 `apps/api/tests/AdminVoidRequiresReasonTest.php` 写单测: reason < 5 字符 → 抛 BusinessException; void 后状态不可再改 (终态)
- [ ] T067 [P] [US5] 在 `apps/api/tests/DistributionAuditCoverageTest.php` 写全链路测: 所有写路径 (config / override / settle / void / refund_void) 必须产生 audit 记录, 缺一即 fail

### Implementation for User Story 5

- [ ] T068 [US5] 在 `apps/api/app/controller/admin/DistributionController.php` 增路由 `GET .../reconcile/by-order/{orderId}` / `GET .../commissions` (分页 + 筛选) / `POST .../commissions/{id}/void` / `GET .../audit` / `GET .../commissions/export` (csv)
- [ ] T069 [P] [US5] 在 `apps/api/app/service/CommissionService.php` 增 `listForAdmin(filter, page, limit)` / `getByOrder(orderId)` / `exportCsv(filter): string` (csv 内手机号全部脱敏, SC-009)
- [ ] T070 [P] [US5] 在 `apps/admin/src/api/distribution.ts` 落 `fetchReconcileByOrder` / `fetchCommissions` / `voidCommission` / `fetchAudit` / `exportCommissionsCsv` (走 Zod)
- [ ] T071 [P] [US5] 在 `apps/admin/src/views/distribution/DistributionReconcileView.vue` 落对账页: 订单号输入 → receivers 表格 + 4 人异常硬提示 + 撤销按钮 (ElMessageBox.confirm type=warning 收集 reason ≥ 5 字符)
- [ ] T072 [P] [US5] 在 `apps/admin/src/views/distribution/DistributionAuditView.vue` 落审计页: 按时间/动作/对象筛选 + el-pagination 分页 + 表格展示 actor/before/after/reason
- [ ] T073 [P] [US5] 在 `apps/admin/src/router/index.ts` 注册 2 条路由
- [ ] T074 [P] [US5] 在 `apps/admin/tests/AdminDistributionApi.test.ts` 写 fetch* 单测
- [ ] T075 跑 `make test-admin DistributionReconcileView DistributionAuditView AdminDistributionApi` 与 `make test-api AdminVoidRequiresReasonTest DistributionAuditCoverageTest` 全过

**Checkpoint**: US5 独立可演示 — 对账 / 撤销 / 审计三件套闭环, 4 级异常硬拒

---

## Phase 8: User Story 6 - 关闭分销的回退与既有数据处理 (Priority: P2)

**Goal**: 关闭分销后学员端入口消失, 老链接按生成时配置处理, 既有佣金不被改写; 再次开启允许新生成

**Independent Test**: enabled=true 产生待结算 → 关站 → 待结算仍在 → 学员端入口消失 → 老链接再来新注册仍按当时规则绑定

### Tests for User Story 6 ⚠️ 写在前

- [ ] T076 [P] [US6] 在 `apps/api/tests/DistributionDisableFlowTest.php` 写集成测: enabled=true 产生 pending → enabled=false → 记录不动; 前端分享入口路由 404
- [ ] T077 [P] [US6] 在 `apps/api/tests/ShareEntryCreationGateTest.php` 写单测: enabled=false 时学员 POST /share-entries → 409, 不写 DB; enabled=true 后恢复
- [ ] T078 [P] [US6] 在 `apps/web/tests/DistributionRouteGuard.test.ts` 写组件测: distribution.enabled=false 时 /distribution 路由跳 404

### Implementation for User Story 6

- [ ] T079 [US6] 在 `apps/api/app/service/ShareEntryService.php::create` 加业务闸门: `DistributionConfigService::getConfig()->enabled === false` 时抛 BusinessException, 不写 DB
- [ ] T080 [US6] 在 `apps/api/app/service/CommissionService.php::settleForOrder` 加业务闸门: 全局 enabled=false 且课程级 override 不存在时直接 return, 不写 commission_records; 课程级 override.enabled=false 时同样跳过
- [ ] T081 [US6] 在 `apps/web/src/router/index.ts` / `apps/admin/src/router/index.ts` 增加 enabled 守卫: 站点 enabled=false 时 /distribution 路由 → 404 页面
- [ ] T082 [P] [US6] 在 `apps/api/app/service/ShareVisitService.php::recordVisit` 保持「按 share_entry 当时的 distribution_enabled_at_creation 处理」, 即生成时 enabled=true 则继续记, 生成时 enabled=false 则不记 (与 spec Edge Cases 一致)
- [ ] T083 跑 `make test-api DistributionDisableFlowTest ShareEntryCreationGateTest` 与 `make test-web DistributionRouteGuard` 全过

**Checkpoint**: US6 独立可演示 — 关闭 / 重启 / 课程级开关三种状态切换都不破坏历史

---

## Phase 9: Polish & Cross-Cutting Concerns

**Purpose**: 跨多个用户故事的收尾

- [ ] T084 [P] 在 `apps/api/app/service/DistributionConfigService.php` 顶部增 private const (`const LEVEL_CAP_HARD_LIMIT = 3`, `const COMMISSION_VOID_MIN_REASON_LEN = 5`, `const TIMEZONE = 'Asia/Shanghai'`)
- [ ] T085 [P] 在 `apps/api/app/service/CommissionService.php` 顶部增 private const (状态枚举, 金额分单位声明)
- [ ] T086 [P] 在 `packages/contracts/src/distribution.ts` 顶部加注释: 「本文件受 SC-003 合规硬约束保护, level_cap 不得修改为 4」
- [ ] T087 在 `CONTEXT.md` 末尾追加「分销」段: 推荐人 / 分享入口 / 访问痕迹 / 佣金记录 / 分销审计 / 课程分销开关 / 级别 等术语与 Avoid
- [ ] T088 在 `tasks/lessons.md` 追加: 「MySQL 软删表唯一约束 → 同理用生成列保证 commission_records 单订单单接收人唯一」「订单 succeeded 与退款是两个事件源, 钩子必须订阅同一接口, 不能 cron 兜底」
- [ ] T089 跑 `make test` (api+web+admin) 全过; 跑 `make test-e2e` 端到端套件全过
- [ ] T090 跑 `make lint` `make typecheck` `make phpstan` 全过
- [ ] T091 跑 quickstart.md 全 12 条 SC 场景, 手工记录结果; SC-003 / SC-007 / SC-009 / SC-010 / SC-011 必须全过
- [ ] T092 [P] 在 `apps/api/tests/ExportCsvMaskingTest.php` (从 quickstart 矩阵提前为正式测试) 落 csv 导出脱敏单测, 覆盖所有导出字段
- [ ] T093 跑 `make rebuild-api` (因 packages/contracts 改了), 验证生产容器可启动

**Checkpoint**: 全量验收完成, 可合并

---

## Dependencies & Execution Order

### Phase Dependencies

- **Setup (Phase 1)**: 无依赖, 立即开始
- **Foundational (Phase 2)**: 依赖 Setup — **BLOCKS 所有 user story**
- **User Stories (Phase 3~8)**: 依赖 Foundational 完成
  - 按 P1 → P2 顺序串行 (US1 → US2 → US3 → US4 → US5 → US6)
  - 同故事内 [P] 任务可并行
- **Polish (Phase 9)**: 依赖所有 user story 完成

### User Story Dependencies

- **US1 (P1)**: 依赖 Foundational, 无其他故事依赖 — **MVP**
- **US2 (P1)**: 依赖 US1 (DistributionConfigService 是 US2 与 US3 的共用底座, 但 US2 可与 US1 并行, 仅共享 DistributionConfigService)
- **US3 (P1)**: 依赖 US1 + US2 (settlement 订阅 order 钩子 + 读 distribution_config)
- **US4 (P2)**: 依赖 US1 + US3 (学员端展示依赖 commission_records)
- **US5 (P2)**: 依赖 US2 + US3 (对账/审计读 commission_records, 撤销改 status)
- **US6 (P2)**: 依赖 US1 + US2 + US3 (关闭闸门覆盖 US1/2/3 的入口)

### Within Each User Story

- Tests 写在前, 跑红, 再实现
- Models → Services → Controllers → 前端 API → 前端 View → 前端 Router → 前端 Tests
- 服务端单测与前端组件测可分别并行
- 同文件多任务串行 (避免冲突)

### Parallel Opportunities

- T001 / T002 / T003 (Setup): 可并行
- T006 / T008 / T009 / T010 (Foundational 不同文件): 可并行
- T012 / T013 (Foundational tests): 可并行
- 每个 US 内的 tests 之间 (T018 / T019 / T020 等): 可并行
- 每个 US 内的 controllers / views / api / tests (不同文件): 可并行
- US2 配置页与 US3 settlement 测试: 跨故事并行 (同 Phase 后段)
- US4 view 与 US5 reconcile view: 跨故事并行 (同 Phase 后段)

---

## Parallel Example: User Story 1

```bash
# Tests for US1 - 全部并行 (不同文件, 无相互依赖):
Task T018: "apps/api/tests/ShareEntryServiceTest.php"
Task T019: "apps/api/tests/ReferralBindingServiceTest.php"
Task T020: "apps/api/tests/ShareLandingControllerTest.php"

# Implementation - controller / service / 落地页分别并行:
Task T024: "apps/api/app/controller/learner/DistributionController.php"
Task T025: "apps/api/app/controller/public/ShareLandingController.php"
Task T021: "apps/api/app/service/ShareEntryService.php"
Task T022: "apps/api/app/service/ShareVisitService.php"
```

---

## Implementation Strategy

### MVP First (US1 Only)

1. Phase 1 Setup (T001~T003)
2. Phase 2 Foundational (T004~T017) — 必走完
3. Phase 3 US1 (T018~T028) — MVP 验证
4. **STOP**: 跑 quickstart SC-005 / SC-006 验证「学员生成链接 + 新注册绑定 + 老学员不污染」
5. 可演示 / 可发版 (即便尚未发佣金, 已具备分销入口与推荐关系)

### Incremental Delivery

1. Setup + Foundational → Foundation ready
2. + US1 → 学员分享 + 推荐关系 (MVP!)
3. + US2 → 管理端配置
4. + US3 → 结算 + 退款撤销 + 合规主线
5. + US4 → 学员端可见
6. + US5 → 管理端对账 + 审计
7. + US6 → 关闭分销回退
8. + Polish → 收尾

每加一个故事跑一遍对应 SC, 不退化前序故事.

### Parallel Team Strategy

- Dev A: Phase 2 + US1 + US3 (后端主线)
- Dev B: US2 + US5 (管理端 + 配置)
- Dev C: US4 + US6 (学习端 + 关闭回退)
- 三人在 Foundational 完成后并行推进, US5 需等 US3 完成 commission_records 后启动

---

## Notes

- [P] = 不同文件, 无依赖
- [Story] = 所属用户故事 (US1~US6)
- 任务粒度 = 一个可独立回滚的 commit (符合 CLAUDE.md 「commit 默认粒度」)
- 测试与实现同 commit (符合 CLAUDE.md 「新增视图同 commit 提交对应测试」)
- 不引入新依赖, 不修改既有模块语义, 不挂入既有通知 / 钱包
- 合规硬约束 (level_cap=3, 单笔 ≤ 3 接收人) 三层防护必须在 Phase 2 与 US3 双重覆盖, SC-003 验收
- 任何写操作经 `DistributionAuditService::record()`, 严禁散写
- 金额一律 int cents, 时间一律 int Unix 秒, 手机号一律 `^1[3-9]\*{8}\d{4}$` 脱敏

---

## Summary Stats (待 tasks 完成后回填)

- 总任务数: 93
- 各故事任务数: US1=11, US2=12, US3=12, US4=11, US5=12, US6=8
- Setup: 3, Foundational: 14, Polish: 10
- 可并行任务: 约 50%
- MVP 范围: Setup + Foundational + US1 (T001~T028)
