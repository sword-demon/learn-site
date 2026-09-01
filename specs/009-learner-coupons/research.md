# Research: 009-learner-coupons

## R1: 数据模型 — 活动模板 + 学员实例

**Decision**: 两张主表 `coupon_campaigns`（模板/活动）与 `learner_coupons`（学员持有的券实例）；适用范围用 junction 表 `coupon_campaign_categories`、`coupon_campaign_courses`；订单扩展 `orders.learner_coupon_id` + `coupon_discount_snapshot`。

**Rationale**: 模板集中配置满减与上限；实例承载「未使用 / 锁定 / 已使用 / 已过期 / 已失效」状态，满足每人限领、待支付锁定与支付后核销。与规格中 Coupon Campaign / Learner Coupon 实体一一对应。

**Alternatives considered**:

| 方案 | 放弃原因 |
|------|----------|
| 仅一张表 + JSON 适用范围 | 难以索引、统计与审计 |
| 兑换码字符串表 | 规格首版明确不做任意码兑换 |
| 抵扣记录独立第三张表 | 成功支付后 `learner_coupons.used_order_id` + 订单快照已足够；管理端列表 JOIN 订单即可 |

---

## R2: 满减计算与限时优惠价关系

**Decision**: 下单确认瞬间 `base_price = sale_open ? sale_price : list_price`（与现有 `OrderService::createPending` 一致）；`coupon_discount = min(discount_amount, base_price)` 且须 `base_price >= min_amount`；`paid_amount = max(0, base_price - coupon_discount)`。不与限时优惠叠加二次折让。

**Rationale**: 对齐规格假设与 `CONTEXT.md` 中「当前价格」定义；订单快照继续写入 `list_price_snapshot`、`sale_price_snapshot`，新增 `coupon_discount_snapshot` 与 `learner_coupon_id`。

**Alternatives considered**:

| 方案 | 放弃原因 |
|------|----------|
| 优惠券基于标准价计算 | 学员实付可能高于未用券的限时价，体验差 |
| 券后仍可叠限时价 | 规格明确不二次折让 |

---

## R3: 待支付订单锁定券实例

**Decision**: 创建 pending 订单时，若携带 `learner_coupon_id`，在同事务内将实例 `status` 置为 `locked` 并写入 `locked_order_id`；支付成功 → `used`；`failed`/`cancelled`/超时释放 → 回 `unused`（若未过使用期）。

**Rationale**: 防止同一券并发用于多笔待支付单；复用现有「每学员每课仅一笔 pending 订单」幂等语义，在返回已有 pending 订单时须校验券锁定一致性。

**Alternatives considered**:

| 方案 | 放弃原因 |
|------|----------|
| 仅支付成功时扣减 | 并发双单可重复使用 |
| Redis 分布式锁 | 宪章要求无必要不扩 Redis；MySQL 行锁足够 |

---

## R4: 领取/发放名额并发

**Decision**: `coupon_campaigns.claimed_count` / `used_count` 在事务内 `SELECT ... FOR UPDATE` 后递增；`claimed_count < total_quota`（`total_quota` NULL 表示不限）方可领取/发放。

**Rationale**: 名额规模远低于十万 QPS，行锁即可；无需 Redis 计数器。

**Alternatives considered**:

| 方案 | 放弃原因 |
|------|----------|
| Redis INCR | 本特性不授权新 Redis 用途 |
| 仅应用层 COUNT | 并发超发 |

---

## R5: 分类适用范围匹配

**Decision**: 课程 `categories.path` 为物化路径（如 `/1/5/7`）。券配置分类 ID 集合 `C`；课程 `category_id` 可用当且仅当 `path` 中包含 `/{$id}/` 或等于某 `id` 的根路径段（实现：`path LIKE CONCAT('%/', id, '/%') OR path = CONCAT('/', id) OR category_id = id`）。

**Rationale**: 与现有 `CategoryController` 维护的 `path` 字段一致，避免运行时递归树查询。

**Alternatives considered**:

| 方案 | 放弃原因 |
|------|----------|
| 仅精确 category_id | 不符合规格「含子分类」 |
| 闭包表 | 首版无闭包表 |

---

## R6: 权限与审计

**Decision**: 新增权限码 `coupon.manage`（创建、编辑、停用、定向发放、使用记录查询）；学习端领取/我的券/下单选券仅需学员令牌。写操作经 `CouponService` 私有 `writeAudit()`。

**Rationale**: 与 `banner.manage`、`checkin.manage` 模式一致；符合 H3 硬规则。

---

## R7: 前端与契约

**Decision**: `packages/contracts/src/coupon.ts` 定义管理端/学习端 DTO；扩展 `OrderCreateInput` 可选 `learner_coupon_id`；学习端 `CheckoutView.vue` 用真实 API 替换现有「优惠码」占位；学员中心新增 `CouponsView.vue`；管理端 `CouponListView.vue`。

**Rationale**: 结账页已有 promo UI 占位（`ponytail` 注释），本特性正式落地；契约优先满足宪章 III。

---

## R8: 订单 pending 复用与券变更

**Decision**: 若已有 pending 订单且请求携带不同 `learner_coupon_id`，拒绝并提示「存在待支付订单」；若相同券则复用订单；无券 pending 不得静默改价绑券（需取消后重建——首版 pending 不可 PATCH，学员刷新确认页重新下单）。

**Rationale**: 订单快照不可变；避免在支付通道已创建后改 `paid_amount`。

**Alternatives considered**:

| 方案 | 放弃原因 |
|------|----------|
| PATCH pending 订单换券 | 支付渠道金额可能已固定 |
