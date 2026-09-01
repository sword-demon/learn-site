# Data Model: 009-learner-coupons

## 概览

```text
coupon_campaigns (活动模板)
  ├── coupon_campaign_categories (scope=category)
  ├── coupon_campaign_courses (scope=course)
  └── learner_coupons (学员实例)
        └── orders.learner_coupon_id (锁定/核销)
```

- **活动模板**：运营配置满减、范围、有效期、名额与领取方式。
- **学员实例**：每人每张券一条；状态机驱动领取、锁定、核销、过期。
- **订单扩展**：快照 `coupon_discount_snapshot`；实付 `paid_amount` 已含抵扣。

---

## coupon_campaigns

优惠券活动模板。

| 字段 | 类型 | 规则 |
|------|------|------|
| id | BIGINT UNSIGNED PK | 自增 |
| name | VARCHAR(120) | 非空；展示名称 |
| scope_type | ENUM | `category` \| `course` \| `all` |
| min_amount | DECIMAL(10,2) | 非空，默认 0；满减门槛，0=无门槛 |
| discount_amount | DECIMAL(10,2) | 非空，>0；减免金额 |
| claim_mode | ENUM | `public` \| `admin_only` |
| claim_starts_at | DATETIME | 非空；可领取/可发放开始 |
| claim_ends_at | DATETIME | 非空；领取/发放截止 |
| use_ends_at | DATETIME NULL | 可空；使用截止，NULL=与 claim_ends_at 相同 |
| total_quota | INT UNSIGNED NULL | 可空；全站可发放/领取总上限，NULL=不限 |
| claimed_count | INT UNSIGNED | 非空，默认 0 |
| used_count | INT UNSIGNED | 非空，默认 0 |
| per_learner_claim_limit | INT UNSIGNED | 非空，默认 1 |
| per_learner_use_limit | INT UNSIGNED | 非空，默认 1 |
| status | ENUM | `active` \| `disabled`；停用后不可新领，已领未用不可用 |
| created_by | BIGINT UNSIGNED | 创建人 staff account_id |
| created_at | TIMESTAMP | |
| updated_at | TIMESTAMP | |

**索引**:

- `(status, claim_starts_at, claim_ends_at)` — 学习端可领取列表
- `(created_at DESC)` — 管理端列表

**校验**（`CouponService`）:

- `discount_amount > 0`
- 若 `min_amount > 0` 则 `discount_amount <= min_amount`（防止减超过满额）
- `claim_ends_at > claim_starts_at`；`use_ends_at` 为空或 `>= claim_ends_at`
- `scope_type=category` 时 junction 至少 1 条分类
- `scope_type=course` 时 junction 至少 1 门已发布收费课
- `scope_type=all` 时 junction 为空

**活动状态（派生展示，可不落库）**:

- `scheduled` — now < claim_starts_at
- `active` — 在领取期内且 status=active
- `ended` — now > claim_ends_at
- `disabled` — status=disabled

---

## coupon_campaign_categories

| 字段 | 类型 | 规则 |
|------|------|------|
| campaign_id | BIGINT UNSIGNED FK | → coupon_campaigns.id CASCADE |
| category_id | BIGINT UNSIGNED FK | → categories.id RESTRICT |

**主键**: `(campaign_id, category_id)`

---

## coupon_campaign_courses

| 字段 | 类型 | 规则 |
|------|------|------|
| campaign_id | BIGINT UNSIGNED FK | → coupon_campaigns.id CASCADE |
| course_id | BIGINT UNSIGNED FK | → courses.id RESTRICT |

**主键**: `(campaign_id, course_id)`

---

## learner_coupons

学员持有的优惠券实例。

| 字段 | 类型 | 规则 |
|------|------|------|
| id | BIGINT UNSIGNED PK | 自增 |
| campaign_id | BIGINT UNSIGNED FK | → coupon_campaigns |
| learner_id | BIGINT UNSIGNED FK | → accounts (kind=learner) |
| status | ENUM | `unused` \| `locked` \| `used` \| `expired` \| `voided` |
| source | ENUM | `claim` \| `grant` |
| granted_by | BIGINT UNSIGNED NULL | 定向发放 staff account_id |
| locked_order_id | BIGINT UNSIGNED NULL | FK → orders |
| used_order_id | BIGINT UNSIGNED NULL | FK → orders |
| expires_at | DATETIME | 使用截止（来自 campaign.use_ends_at 或 claim_ends_at） |
| locked_at | DATETIME NULL | |
| used_at | DATETIME NULL | |
| created_at | TIMESTAMP | 领取/发放时间 |

**索引**:

- `(learner_id, status, expires_at)` — 我的优惠券 / 下单候选
- `(campaign_id, learner_id)` — 每人限领统计
- `(locked_order_id)` — 释放锁定
- `(used_order_id)` UNIQUE — 一券一单

**状态转换**:

```text
[claim/grant] → unused
unused → locked (创建 pending 订单)
locked → unused (订单 failed/cancelled/超时释放)
locked → used (支付成功)
unused → expired (定时或读取时判定 now > expires_at)
unused|locked → voided (活动 disabled)
```

---

## orders（扩展列）

在既有 `orders` 表新增：

| 字段 | 类型 | 规则 |
|------|------|------|
| learner_coupon_id | BIGINT UNSIGNED NULL | FK → learner_coupons.id SET NULL |
| coupon_discount_snapshot | DECIMAL(10,2) | 非空默认 0；创建时写入，不可变 |

**不变量**:

- `paid_amount = (sale_price_snapshot > 0 ? sale_price_snapshot : list_price_snapshot) - coupon_discount_snapshot`
- `coupon_discount_snapshot >= 0` 且 `<= base_price`
- 成功支付后 `learner_coupons.status=used` 且 `used_order_id` 指向本单

---

## 客户端 DTO 映射

### CouponPublicDTO（学习端领取中心）

| 字段 | 类型 | 说明 |
|------|------|------|
| id | number | campaign_id |
| name | string | |
| min_amount | number | |
| discount_amount | number | |
| scope_type | string | |
| scope_summary | string | 如「指定分类：理学」 |
| claim_ends_at | string | ISO8601 |
| use_ends_at | string | ISO8601 |
| remaining_quota | number \| null | total_quota - claimed_count |

### LearnerCouponDTO（我的优惠券）

| 字段 | 类型 | 说明 |
|------|------|------|
| id | number | learner_coupons.id |
| campaign_id | number | |
| name | string | |
| min_amount | number | |
| discount_amount | number | |
| scope_type | string | |
| scope_summary | string | |
| status | string | unused \| used \| expired \| voided |
| expires_at | string | |
| applicable_course_ids | number[]? | 可选；course scope 时列出 |

### CheckoutCouponOptionDTO（下单候选）

| 字段 | 类型 | 说明 |
|------|------|------|
| id | number | learner_coupons.id |
| name | string | |
| discount_amount | number | 本单可抵扣额 |
| min_amount | number | |
| eligible | boolean | |
| ineligible_reason | string \| null | 如 COUPON_MIN_AMOUNT_NOT_MET |

### AdminCouponCampaignDTO

含模板全字段 + `claimed_count`、`used_count`、`scope_category_ids`、`scope_course_ids`。

---

## 审计

| action | target_type | 时机 |
|--------|-------------|------|
| coupon.create | coupon_campaigns | 创建活动 |
| coupon.update | coupon_campaigns | 编辑 |
| coupon.disable | coupon_campaigns | 停用 |
| coupon.grant | learner_coupons | 定向发放 |
| coupon.claim | learner_coupons | 学员领取（可选记 learner 为 actor） |
