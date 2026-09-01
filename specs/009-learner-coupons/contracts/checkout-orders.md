# 下单与订单契约扩展（优惠券）

本文件扩展 [specs/001-personal-learning-site/contracts/payments.md](../../001-personal-learning-site/contracts/payments.md) 与 [learner-coupons.md](./learner-coupons.md)。

## 创建订单（扩展）

`POST /api/learner/v1/courses/{id}/orders`

### 请求 Body（新增，可选 JSON）

```json
{
  "learner_coupon_id": 501
}
```

- 省略或 `null`：不使用优惠券，行为与现网一致。
- 正整数：使用指定 `learner_coupons.id`，须归属当前学员且通过服务端校验。

### 成功 `200`（既有信封，字段扩展）

```json
{
  "ok": true,
  "data": {
    "order_id": 9001,
    "status": "pending",
    "list_price_snapshot": 129.0,
    "sale_price_snapshot": 99.0,
    "coupon_discount_snapshot": 20.0,
    "paid_amount": 79.0,
    "learner_coupon_id": 501,
    "payment": {
      "type": "wechat_native",
      "code_url": "..."
    }
  }
}
```

**规则**:

- `paid_amount = max(0, base_price - coupon_discount_snapshot)`，`base_price` 为限时优惠开启时的 `sale_price_snapshot`，否则为 `list_price_snapshot`。
- 创建成功后 `learner_coupons.status = locked`，`locked_order_id = order_id`。
- 若已存在同 `(learner, course)` 的 `pending` 订单，返回该订单（幂等）；若请求携带的 `learner_coupon_id` 与已锁定券不一致，返回 `CONFLICT` / `ORDER_PENDING_COUPON_MISMATCH`。

### 错误（新增）

| code | message | 场景 |
|------|---------|------|
| `VALIDATION_FAILED` | `COUPON_NOT_FOUND` | 券不存在或不属于学员 |
| `VALIDATION_FAILED` | `COUPON_NOT_APPLICABLE` | 适用范围不匹配 |
| `VALIDATION_FAILED` | `COUPON_MIN_AMOUNT_NOT_MET` | 未满减门槛 |
| `VALIDATION_FAILED` | `COUPON_EXPIRED` | 已过使用期 |
| `VALIDATION_FAILED` | `COUPON_VOIDED` | 活动已停用 |
| `CONFLICT` | `COUPON_ALREADY_USED` | 实例已使用 |
| `CONFLICT` | `COUPON_LOCKED` | 已被其他待支付单锁定 |
| `CONFLICT` | `ORDER_PENDING_COUPON_MISMATCH` | 已有 pending 订单且券不一致 |

---

## 查询订单（扩展字段）

`GET /api/learner/v1/orders/{id}` 与 `GET /api/learner/v1/orders` 列表项增加：

| 字段 | 类型 | 说明 |
|------|------|------|
| learner_coupon_id | number \| null | |
| coupon_discount_snapshot | number | 默认 0 |
| paid_amount | number | 已含抵扣 |

管理端 `GET /api/admin/v1/orders/{id}` 同步展示上述字段（只读）。

---

## 支付结果与券状态

| 订单终态 | 券实例 |
|----------|--------|
| `succeeded` | `used`，写入 `used_order_id`、`used_at`；`coupon_campaigns.used_count++` |
| `failed` / `cancelled` | `unused`（未过期），清空 `locked_order_id` |
| `pending` 超时（与现网策略一致） | 同 failed 释放 |

**幂等**: 支付回调重复投递时，`markSucceeded` 对已 `used` 同一 `order_id` 的券不重复计数。

---

## 前端结账流程

1. `GET /courses/{id}/checkout-coupons` 拉取候选与 `payable_preview`。
2. 学员在 `CheckoutView` 选择一张券或「不使用」。
3. `POST /courses/{id}/orders` 携带 `learner_coupon_id`（可选）。
4. 展示返回的 `paid_amount` 与支付二维码；移除本地「优惠码」输入占位逻辑。

---

## Zod / contracts

- `OrderCreateInput`: `{ learner_coupon_id?: number | null }`
- `OrderDTO`: 扩展 `coupon_discount_snapshot`, `learner_coupon_id`
- 解析时 `coupon_discount_snapshot` 默认 `0`
