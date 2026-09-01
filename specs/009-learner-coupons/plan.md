# Implementation Plan: 学员优惠券领取与下单抵扣

**Branch**: `009-learner-coupons` | **Date**: 2026-09-01 | **Spec**: [spec.md](./spec.md)

**Input**: Feature specification from `/specs/009-learner-coupons/spec.md`

**Note**: 全栈功能——`apps/api` 新增优惠券表与服务、扩展 `orders` 与 `OrderService`；`apps/admin` 优惠券管理；`apps/web` 领取中心 + 结账选券；`packages/contracts` 共享 DTO。

## Summary

为运营提供**优惠券活动**配置（分类 / 课程 / 无门槛、满减、有效期、名额上限），学员在学习端**领取或接收发放**的券实例，并在**购买订单确认**时选用一张适用券抵扣实付金额。抵扣基数为下单瞬间**当前价格**（已含课程限时优惠价）。待支付订单锁定券实例，支付成功核销，失败/取消释放。权限码 `coupon.manage`；不使用 Redis 计数，名额与锁定走 MySQL 事务。

## Technical Context

**Language/Version**: PHP 8.4（Webman 2.2）；TypeScript 5.x strict；Vue 3.5；Node.js 22 LTS

**Primary Dependencies**:
- 后端既有：`webman/think-orm`、Phinx、`OrderService`、`EntitlementService`
- 管理端/学习端：Vue 3、Element Plus、Pinia、Axios、Zod
- 无新 Composer/npm 主依赖

**Storage**: MySQL 8 — 新表 `coupon_campaigns`、`coupon_campaign_categories`、`coupon_campaign_courses`、`learner_coupons`；`orders` 扩展 `learner_coupon_id`、`coupon_discount_snapshot`；`audit_log`

**Testing**: PHPUnit（`CouponTest.php`、`OrderCouponTest.php`）；Vitest（`CouponsView.test.ts`、`CheckoutView.test.ts`、admin `CouponListView.test.ts`）；`packages/contracts` Vitest

**Target Platform**: Docker Compose 编排的 `api` + `admin` + `web` 容器

**Project Type**: Monorepo — `apps/api` + `apps/admin` + `apps/web` + `packages/contracts`

**Performance Goals**:
- SC-002：领取操作 90% 在 30 秒内完成
- SC-003：结账页可用券列表 95% 在 2 秒内返回（常规数据量）

**Constraints**:
- think-orm 唯一 ORM；令牌隔离；时区 `Asia/Shanghai`
- 订单价格快照不可变；单订单单券
- 不使用 Redis 做名额/锁定（宪章 Redis 边界）
- 替换学习端结账页「优惠码」占位，首版无任意字符串兑换码
- `OrderService::markSucceeded` / 失败路径须释放或核销券

**Scale/Scope**: 4 个用户故事、15 条功能需求；预计新增/修改 ~45 源文件；1 张迁移（多表）

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

| 门禁 | 结论 | 说明 |
|------|------|------|
| I. 容器即运行契约 | PASS | Phinx 在 api 容器执行 |
| II. 稳定兼容且可复现 | PASS | 无新运行时依赖 |
| III. 契约优先与端到端类型安全 | PASS | `packages/contracts/src/coupon.ts`；扩展 `OrderDTO` |
| IV. 数据变更安全可追溯 | PASS | 迁移 + `audit_log` |
| V. 质量、安全与可运维性内建 | PASS | PHPUnit/Vitest 同 commit；Compose 门禁 |
| VI. 令牌鉴权 | PASS | `coupon.manage` / 学员令牌隔离 |
| Redis 使用边界 | PASS | 名额与锁定仅 MySQL |
| 双前端独立构建 | PASS | admin/web 各自构建 |

**Phase 1 复查**: `CouponService` 集中校验、锁定与审计；`OrderService` 仅编排创建/支付与券交互；controller 薄层。

## Project Structure

### Documentation (this feature)

```text
specs/009-learner-coupons/
├── plan.md
├── research.md
├── data-model.md
├── quickstart.md
├── contracts/
│   ├── admin-coupons.md
│   ├── learner-coupons.md
│   └── checkout-orders.md
├── checklists/
│   └── requirements.md
└── spec.md
```

### Source Code (repository root)

```text
apps/api/
├── database/
│   ├── migrations/20260901100001_coupons.php
│   └── seeds/PermissionSeeder.php          # + coupon.manage
├── app/
│   ├── controller/
│   │   ├── admin/CouponController.php
│   │   └── learner/
│   │       ├── CouponController.php
│   │       └── OrderController.php         # + learner_coupon_id body
│   ├── service/
│   │   ├── CouponService.php
│   │   └── OrderService.php                # 扩展 createPending / markSucceeded / fail
│   ├── middleware/Authorize.php            # + /coupons
│   └── route.php
└── tests/
    ├── CouponTest.php
    └── OrderCouponTest.php

apps/admin/
├── src/
│   ├── api/coupons.ts
│   ├── layouts/AdminMenu.ts                # + 优惠券管理
│   ├── router/index.ts                     # + /coupons
│   └── views/coupons/CouponListView.vue
└── tests/CouponListView.test.ts

apps/web/
├── src/
│   ├── api/coupons.ts
│   ├── router/index.ts                     # + /me/coupons
│   ├── views/me/CouponsView.vue
│   ├── views/me/StudentCenterView.vue      # 导航入口
│   └── views/checkout/CheckoutView.vue     # 选券 + 去占位 promo
└── tests/
    ├── CouponsView.test.ts
    └── CheckoutView.test.ts

packages/contracts/src/
├── coupon.ts
├── order.ts                                # + coupon fields
└── index.ts
```

**Structure Decision**: `CouponService` 负责活动 CRUD、领取/发放、适用范围匹配与实例状态机；`OrderService::createPending` 接受可选 `learnerCouponId` 并调用 `CouponService::lockForOrder`；支付回调路径调用 `redeemOnSuccess` / `releaseOnTerminal`。

## Complexity Tracking

无宪章违规项，本节留空。

## Phase 0 产出

见 [research.md](./research.md)（R1–R8：数据模型、满减计算、锁定、名额并发、分类匹配、权限、前端、pending 复用）。

## Phase 1 产出

| 产物 | 路径 |
|------|------|
| 数据模型 | [data-model.md](./data-model.md) |
| 管理端契约 | [contracts/admin-coupons.md](./contracts/admin-coupons.md) |
| 学习端契约 | [contracts/learner-coupons.md](./contracts/learner-coupons.md) |
| 下单扩展 | [contracts/checkout-orders.md](./contracts/checkout-orders.md) |
| 验证指南 | [quickstart.md](./quickstart.md) |

## 实现阶段建议（供 `/speckit-tasks`）

1. **Foundation**: 迁移、权限、`CouponService` 骨架、contracts
2. **US1 管理端**: Admin API + `CouponListView` 创建/发放/停用
3. **US2 学习端领取**: claimable + claim + `CouponsView`
4. **US3 下单抵扣**: `checkout-coupons` + `POST orders` 扩展 + `CheckoutView` 选券
5. **US4 记录查询**: redemptions 列表 + 订单/管理端展示抵扣字段
6. **Polish**: 审计、Authorize 泄漏测试、订单失败释放券、quickstart 走查

## 风险与缓解

| 风险 | 缓解 |
|------|------|
| pending 订单复用与换券冲突 | research R8：已有 pending 时不允许改券，提示先完成或等待超时 |
| 分类子树匹配错误 | 使用 `categories.path` 前缀匹配，集成测试覆盖父子分类 |
| 支付成功未核销 | `markSucceeded` 事务内更新 `learner_coupons` + `used_count` |
| 结账页占位与真实 API 不一致 | 删除本地 promo 计算，统一走 `checkout-coupons` |
