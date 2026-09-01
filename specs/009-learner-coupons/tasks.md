---
description: "Tasks: 学员优惠券领取与下单抵扣"
---

# Tasks: 009-learner-coupons

**Input**: Design documents from `/specs/009-learner-coupons/`
**Prerequisites**: plan.md, spec.md, research.md, data-model.md, contracts/*.md, quickstart.md
**Tests**: 同 commit 提交 PHPUnit 与 Vitest(项目硬规则 H4)
**Organization**: 按用户故事分组,每条独立可测。

## Format: `[ID] [P?] [Story] Description`

- **[P]**: 不同文件、无前置依赖可并行
- **[Story]**: US1–US4,基础设施无标签
- 描述含精确文件路径

## Path Conventions

- 后端: `apps/api/app/{controller,service,middleware}`、`apps/api/database/{migrations,seeds}`、`apps/api/tests/`
- 管理端: `apps/admin/src/{api,views,layouts,router}`、`apps/admin/tests/`
- 学习端: `apps/web/src/{api,views,router}`、`apps/web/tests/`
- 契约: `packages/contracts/src/`、`packages/contracts/src/__tests__/`

---

## Phase 1: Setup (Shared Infrastructure)

**Purpose**: 数据库迁移、权限码、共享契约、路由白名单。

- [x] T001 [P] 新增迁移 `apps/api/database/migrations/20260901100001_coupons.php`(`coupon_campaigns` / `coupon_campaign_categories` / `coupon_campaign_courses` / `learner_coupons` + `orders.learner_coupon_id`、`orders.coupon_discount_snapshot`,索引按 `data-model.md`)
- [x] T002 [P] 在 `apps/api/database/seeds/PermissionSeeder.php` 增加 `coupon.manage`(`code=coupon.manage`、`module=promotion`、`description=Manage coupon campaigns`)
- [x] T003 新建 `packages/contracts/src/coupon.ts`(Zod:`CouponPublicDTO`、`LearnerCouponDTO`、`CheckoutCouponOptionDTO`、`AdminCouponCampaignDTO`、`AdminCouponRedemptionDTO`、`CreateCouponInput`、`PatchCouponInput`、`GrantCouponInput`;错误码字面量联合)
- [x] T004 [P] 扩展 `packages/contracts/src/order.ts`(`OrderCreateInput` 加 `learner_coupon_id?: number | null`;`OrderDTO`/`AdminOrderDTO` 加 `learner_coupon_id: number|null` 与 `coupon_discount_snapshot: number`)
- [x] T005 [P] 在 `packages/contracts/src/index.ts` 导出 `coupon.ts` 与扩展后的 `order` 类型
- [x] T006 在 `apps/api/app/middleware/Authorize.php` 把 `coupon.manage` 加入权限代码白名单的「促销」分支(若使用按模块查表则确认能命中 `promotion`)

---

## Phase 2: Foundational (Blocking Prerequisites)

**Purpose**: CouponService 骨架——CRUD、适用范围匹配、状态机、审计,所有用户故事都依赖。

**⚠️ CRITICAL**: 用户故事实施前必须完成本阶段。

- [x] T007 新建 `apps/api/app/service/CouponService.php`(私有常量:错误码、字段上限、时区 `Asia/Shanghai`;`todayDate()` / `nowDatetime()` 工具;私有 `writeAudit()`)
- [x] T008 [P] 在 `CouponService` 实现 `createCampaign()`、`patchCampaign()`(含乐观锁 `expected_updated_at`)、`disableCampaign()`(校验规则:`discount_amount>0`、若 `min_amount>0` 则 `discount_amount<=min_amount`、`claim_ends_at>claim_starts_at`、`use_ends_at` 空或 `>=claim_ends_at`、scope 与 junction 一致)
- [x] T009 [P] 在 `CouponService` 实现 `listCampaigns()`(管理端)、`showCampaign()`、`grantToLearners()`(`SELECT … FOR UPDATE` 校验 `total_quota`、跳过已达限领;批量插入 `learner_coupons`)
- [x] T010 [P] 在 `CouponService` 实现 `listClaimable()`、`claimByLearner()`(`SELECT … FOR UPDATE` 校验全站名额、每人限领、领取有效期;返回 `LearnerCouponDTO`)
- [x] T011 [P] 在 `CouponService` 实现 `listMine()`(按 `learner_id, status, expires_at` 分页)、`listCheckoutOptions($learnerId, $courseId)`(复用分类子树 `categories.path` 前缀匹配 `path LIKE CONCAT('%/', id, '/%') OR path = CONCAT('/', id) OR category_id = id`)
- [x] T012 [P] 在 `CouponService` 实现 `lockForOrder()`、`redeemOnSuccess()`、`releaseOnTerminal()`(事务内更新 `learner_coupons` 与 `coupon_campaigns.claimed_count`/`used_count`;支付成功幂等:已 `used` 同一 `used_order_id` 不重复 `used_count++`)
- [x] T013 [P] 在 `CouponService` 实现 `listRedemptions()`(管理端,JOIN `orders` + `courses` + `accounts`,按 `learner_id` 脱敏手机号)
- [x] T014 在 `apps/api/app/route.php` 注册 `/api/admin/v1/coupons` `/api/admin/v1/coupons/{id}` `/api/admin/v1/coupons/{id}/disable` `/api/admin/v1/coupons/{id}/grants` `/api/admin/v1/coupon-redemptions`(均 `AdminAuth` + `Authorize: coupon.manage`)与 `/api/learner/v1/coupons/claimable` `/api/learner/v1/coupons/{campaignId}/claim` `/api/learner/v1/my/coupons` `/api/learner/v1/courses/{courseId}/checkout-coupons`(均 `LearnerAuth`)

**Checkpoint**: CouponService 公共方法编译通过,Composer 自动加载更新。

---

## Phase 3: User Story 1 - 管理员创建并发放优惠券 (Priority: P1) 🎯 MVP

**Goal**: 管理端优惠券 CRUD + 定向发放,关键变更入审计。

**Independent Test**: 管理员创建「满 99 减 20、仅限某分类、每人限领 1、总量 100」并设为可领取;另创建「无门槛减 10、仅指定课程、admin_only」并定向发放给指定学员;验证列表、统计、审计。

### Implementation for User Story 1

- [x] T015 [US1] 新建 `apps/api/app/controller/admin/CouponController.php`(注入 `CouponService`,实现 `index/show/store/update/disable/grants/redemptions` 七个 endpoint,参数经 Zod 或手写校验,异常映射 `BusinessException`)
- [x] T016 [P] [US1] 新建 `apps/admin/src/api/coupons.ts`(基于 `packages/contracts` DTO 的 `fetchAdminCoupons` / `fetchAdminCoupon` / `createCoupon` / `updateCoupon` / `disableCoupon` / `grantCoupon` / `fetchRedemptions`)
- [x] T017 [US1] 新建 `apps/admin/src/views/coupons/CouponListView.vue`(列表 + 创建/编辑对话框 + 停用按钮 + 定向发放对话框,按 `coupon.manage` 路由守卫依赖 `AdminMenu` 过滤)
- [x] T018 [P] [US1] 在 `apps/admin/src/layouts/AdminMenu.ts` 增加 `{ path: '/coupons', label: '优惠券管理', permission: 'coupon.manage' }`
- [x] T019 [P] [US1] 在 `apps/admin/src/router/index.ts` 注册 `/coupons` 与 `/coupons/:id`,`meta.permission = 'coupon.manage'`
- [x] T020 [P] [US1] 新建 `apps/admin/tests/CouponListView.test.ts`(Vitest + Element Plus 局部注册,断言列表渲染、创建校验、停用确认、发放弹窗)

**Checkpoint**: US1 可独立演示——管理端列表、创建、停用、发放全流程。

---

## Phase 4: User Story 2 - 学员领取与查看优惠券 (Priority: P1)

**Goal**: 学习端领取中心 + 我的优惠券分页。

**Independent Test**: 预置 3 张可领取 + 2 张已领取券,学员打开领取中心与我的优惠券页,验证展示、领取成功、重复领取拒绝、过期/领完提示。

### Implementation for User Story 2

- [x] T021 [US2] 新建 `apps/api/app/controller/learner/CouponController.php`(`claimable`、`claim`、`mine`,校验 `learnerId` 与 token 一致,异常映射 `BusinessException`)
- [x] T022 [P] [US2] 新建 `apps/web/src/api/coupons.ts`(`fetchClaimableCoupons` / `claimCoupon` / `fetchMyCoupons`)
- [x] T023 [US2] 新建 `apps/web/src/views/me/CouponsView.vue`(双 Tab:领取中心 / 我的优惠券;状态筛选;Element Plus `el-tag` 标 `unused`/`used`/`expired`;`payable_preview` 仅在学习端 `my` 页内提示)
- [x] T024 [P] [US2] 在 `apps/web/src/router/index.ts` 注册 `/me/coupons`,`meta.title = '优惠券'`
- [x] T025 [P] [US2] 在 `apps/web/src/views/me/StudentCenterView.vue` 增加「优惠券」导航入口(若导航源自 `me` 路由文件)
- [x] T026 [P] [US2] 新建 `apps/web/tests/CouponsView.test.ts`(Vitest + happy-dom,断言领取成功/重复拒绝/过期提示 UI 切换)

**Checkpoint**: US2 独立可演示——领取、我的券、状态切换、过期提示。

---

## Phase 5: User Story 3 - 下单选用优惠券 (Priority: P1)

**Goal**: 结账页真实 API + OrderService 锁定/核销/释放,移除本地 promo 占位。

**Independent Test**: 学员持「满 50 减 15」券,确认页选用后应付金额正确;切换不适用课程不可选;支付成功后券变已使用,重复下单不可再用。

### Implementation for User Story 3

- [x] T027 [US3] 在 `apps/api/app/controller/learner/CouponController.php`(或新文件 `CheckoutCouponController.php`)增加 `checkoutOptions($courseId)`,内部调 `CouponService::listCheckoutOptions`
- [x] T028 [US3] 扩展 `apps/api/app/service/OrderService.php`:`createPending()` 增加可选 `?int $learnerCouponId` 入参,事务内调 `CouponService::lockForOrder`,失败抛 `COUPON_NOT_FOUND`/`COUPON_NOT_APPLICABLE`/`COUPON_MIN_AMOUNT_NOT_MET`/`COUPON_EXPIRED`/`COUPON_VOIDED`/`COUPON_ALREADY_USED`/`COUPON_LOCKED`/`ORDER_PENDING_COUPON_MISMATCH`;快照写入 `coupon_discount_snapshot`
- [x] T029 [US3] 在 `OrderService::markSucceeded()` 事务末尾调 `CouponService::redeemOnSuccess()`(若订单携带 `learner_coupon_id`);在 `markFailed()` 调 `CouponService::releaseOnTerminal()`(若订单仍 `locked`)
- [x] T030 [US3] 扩展 `apps/api/app/controller/learner/OrderController.php`:`POST /courses/{id}/orders` 接受 JSON body `{ learner_coupon_id?: number|null }` 并透传到 `OrderService::createPending`;`shapeOrder` / `shapeAdminOrder` 新增 `learner_coupon_id` 与 `coupon_discount_snapshot` 字段
- [x] T031 [US3] 改写 `apps/web/src/views/checkout/CheckoutView.vue`:删除本地 `promoCode`/`promoDiscount`/`applyPromo` 占位;新增 `fetchCheckoutCoupons(courseId)` 拉候选,渲染可选券列表(失效态标 `COUPON_MIN_AMOUNT_NOT_MET` 等原因),价格明细新增「优惠券抵扣」行;`submitOrder` 携带所选 `learner_coupon_id`
- [x] T032 [P] [US3] 扩展 `apps/web/src/api/learner.ts`:`createCourseOrder` 接受 `learner_coupon_id`;`fetchCheckoutCoupons(courseId)` 新增
- [x] T033 [P] [US3] 扩展 `apps/web/tests/CheckoutView.test.ts`(断言候选列表渲染、应付金额 = `max(0, base - discount)`、提交携带券 id、负例:不满足门槛 → 拒绝文案)

**Checkpoint**: US3 独立可演示——选券 → 创建订单 → 支付 → 券核销 → 失败释放。

---

## Phase 6: User Story 4 - 管理员查询使用记录 (Priority: P2)

**Goal**: 管理端使用记录列表 + 订单/详情展示抵扣字段。

**Independent Test**: 一张券被 10 名学员领取、3 名使用,管理员列表与详情数字一致;停用后新领取失败、未支付订单重新校验失败。

### Implementation for User Story 4

- [x] T034 [P] [US4] 在 `apps/admin/src/views/orders/OrderListView.vue` 表格新增「优惠券抵扣」列(`coupon_discount_snapshot`,0 显示为「—」)
- [x] T035 [P] [US4] 在 `apps/admin/src/views/orders/OrderDetailView.vue`(若不存在则新建最小详情页)展示 `coupon_discount_snapshot`、`learner_coupon_id`、关联 `CouponCampaignID`
- [x] T036 [US4] 扩展 `apps/admin/src/views/coupons/CouponListView.vue` 详情 Tab:领取数 / 已用数 / 剩余配额;新增「使用记录」内嵌表格(复用 `fetchRedemptions` + `campaign_id` 过滤)
- [x] T037 [P] [US4] 扩展 `apps/admin/tests/CouponListView.test.ts` 增加使用记录渲染断言

**Checkpoint**: US4 独立可演示——券详情统计与订单维度一致。

---

## Phase 7: Polish & Cross-Cutting Concerns

**Purpose**: 审计、鉴权隔离、E2E 回归、quickstart 走查。

- [x] T038 [P] 新建 `apps/api/tests/CouponTest.php`(PHPUnit 集成:创建校验、领取名额、每限领、过期拒绝、定向发放批量、状态机转换)
- [x] T039 [P] 新建 `apps/api/tests/OrderCouponTest.php`(PHPUnit 集成:创建带券订单、快照、支付成功核销、失败释放、pending 复用与换券冲突)
- [x] T040 [P] 新建 `apps/api/tests/AuthorizeLeakTest.php` 补充用例:无 `coupon.manage` 命中 403、学员 token 访问 `/api/admin/v1/coupons` 命中 403
- [x] T041 [P] 新建 `packages/contracts/src/__tests__/coupon.test.ts`(Zod schema 正/反例)
- [x] T042 [P] 审计日志核对:`apps/api/app/service/CouponService::writeAudit()` 触发 `coupon.create` / `coupon.update` / `coupon.disable` / `coupon.grant` 四类写入,管理端 `AuditLogView` 过滤可见(若需要新增过滤项,在 `AuditController` 追加 code 白名单)
- [x] T043 跑通 `quickstart.md` 全部场景并附 `make test-api` 与 `make test-web` 全绿(可由 `bash .specify/scripts/bash/check-prerequisites.sh --json --include-tasks` 复核)

---

## Dependencies & Execution Order

### Phase Dependencies

- **Setup (Phase 1)**: 无依赖,可立即开始。
- **Foundational (Phase 2)**: 依赖 Setup —— 阻塞所有用户故事。
- **US1 (Phase 3)**: 依赖 Phase 2 —— 与 US2/US3/US4 可并行(若分人),但共享 `CouponService` 时串行。
- **US2 (Phase 4)**: 依赖 Phase 2;`learner/CouponController` 仅 `claimable/claim/mine`。
- **US3 (Phase 5)**: 依赖 Phase 2;扩展 `OrderService` 与 `learner/CouponController::checkoutOptions`。
- **US4 (Phase 6)**: 依赖 Phase 2 + US1 的列表详情页(复用)。
- **Polish (Phase 7)**: 依赖前三故事交付。

### Within Each Story

- 测试先于实现(H4 硬规则:同 commit 提交 PHPUnit + Vitest)。
- 模型/迁移 → Service → Controller → 前端 API → View。
- Story 完成后才进入下一优先级。

### Parallel Opportunities

- Phase 1: T001/T002/T003/T005/T006 可并行(不同文件)。
- Phase 2: T008–T013 可并行(同一文件不同私有方法,但仍串行为佳——同文件 PR diff 更清晰)。
- Phase 3: T016/T018/T019/T020 可并行;T015 串行(同 controller)。
- Phase 4: T022/T024/T025/T026 可并行;T021/T023 串行。
- Phase 5: T032/T033 可并行;T027–T031 串行(同文件)。
- Phase 6: T034/T035/T037 可并行;T036 串行。
- Phase 7: 测试与审计核对可并行。

---

## Implementation Strategy

### MVP First (US1 + US2 + US3)

1. 完成 Phase 1 + Phase 2
2. 完成 Phase 3(US1 管理端 CRUD)
3. 完成 Phase 4(US2 学习端领取)
4. 完成 Phase 5(US3 结账抵扣)——核心闭环
5. **STOP and VALIDATE**: 三故事一起跑通 quickstart 场景 1–3

### Incremental Delivery

1. Setup + Foundational → 基础设施就绪
2. +US1 → 管理端可创建/发放(US1 单独可演示)
3. +US2 → 学员可领取(US1+US2 联调)
4. +US3 → 下单抵扣闭环(MVP!)
6. +US4 → 使用记录与统计(运营验收)
7. Polish:审计 + 鉴权 + 回归测试

### Parallel Team Strategy

- 开发者 A:Phase 1 + Phase 2 + US3(核心闭环)
- 开发者 B:US1 + US4(管理端)
- 开发者 C:US2(学习端)

---

## Notes

- [P] 任务 = 不同文件、无前置依赖;同文件多任务串行。
- [Story] 标签建立任务到用户故事的可追溯性。
- 每个故事独立可测;MVP = US1+US2+US3 三个 P1。
- 提交前确认 `make test-api` 与 `make test-web` 全绿。
- 数据库迁移后必须 `make migrate`(见 README),`audit_log` 写入由 `CouponService::writeAudit()` 私有方法统一收口。