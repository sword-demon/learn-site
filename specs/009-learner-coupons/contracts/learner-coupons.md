# 学习端优惠券 API

前缀 `/api/learner/v1`。除注明公开外均需学员访问令牌。

## 可领取列表

| 方法 | 路径 | 说明 |
|------|------|------|
| GET | `/coupons/claimable` | 当前可公开领取的活动 |

**成功 `200`**: `{ items: CouponPublicDTO[] }`

**规则**: `claim_mode=public`、在领取期内、`status=active`、未达 total_quota、学员未达 per_learner_claim_limit。

---

## 领取

| 方法 | 路径 | 说明 |
|------|------|------|
| POST | `/coupons/{campaignId}/claim` | 一键领取 |

**成功 `201`**: `LearnerCouponDTO`

**错误**:

- `VALIDATION_FAILED` — `COUPON_NOT_CLAIMABLE`、`COUPON_QUOTA_EXCEEDED`、`COUPON_CLAIM_LIMIT_EXCEEDED`
- `CONFLICT` — `COUPON_ALREADY_CLAIMED`
- `401 UNAUTHENTICATED`

---

## 我的优惠券

| 方法 | 路径 | 说明 |
|------|------|------|
| GET | `/my/coupons` | 分页 |

**查询参数**: `status?`（`unused`/`used`/`expired`），`page`, `limit`

**成功 `200`**: `{ items: LearnerCouponDTO[], total, page, limit }`

**排序**: `expires_at ASC, id DESC`（未使用优先临近过期）

---

## 下单可用券（结账预览）

| 方法 | 路径 | 说明 |
|------|------|------|
| GET | `/courses/{courseId}/checkout-coupons` | 某课程可用实例 |

**成功 `200`**:

```json
{
  "base_price": 99.0,
  "list_price": 129.0,
  "sale_price": 99.0,
  "items": [
    {
      "id": 501,
      "name": "开学季满99减20",
      "min_amount": 99,
      "discount_amount": 20,
      "eligible": true,
      "ineligible_reason": null,
      "payable_preview": 79.0
    }
  ]
}
```

**说明**: `base_price` 为确认瞬间当前价格（含限时优惠）；`payable_preview = max(0, base_price - discount_amount)` 当 eligible。

---

## 鉴权隔离

- 管理端 `/api/admin/v1/coupons` 不得接受学员令牌。
- 学习端不得调用管理端写接口。

## 相关契约

- 下单携带券与订单字段扩展见 [checkout-orders.md](./checkout-orders.md)。
