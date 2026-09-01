# 管理端优惠券 API

前缀 `/api/admin/v1`。需管理端访问令牌；权限点 `coupon.manage`。

## 查询活动列表

| 方法 | 路径 | 说明 |
|------|------|------|
| GET | `/coupons` | 分页列表 |

**查询参数**: `page`, `limit`, `scope_type?`, `status?`（`active`/`disabled`/`scheduled`/`ended` 派生筛选）

**成功 `200`**: `{ items: AdminCouponCampaignDTO[], total, page, limit }`

**排序**: `created_at DESC`

---

## 查询活动详情

| 方法 | 路径 | 说明 |
|------|------|------|
| GET | `/coupons/{id}` | 含统计与 scope ID 列表 |

---

## 创建活动

| 方法 | 路径 | 说明 |
|------|------|------|
| POST | `/coupons` | 创建模板 |

**Body**:

```json
{
  "name": "开学季满99减20",
  "scope_type": "category",
  "scope_category_ids": [1, 5],
  "scope_course_ids": [],
  "min_amount": 99,
  "discount_amount": 20,
  "claim_mode": "public",
  "claim_starts_at": "2026-09-01T00:00:00+08:00",
  "claim_ends_at": "2026-09-30T23:59:59+08:00",
  "use_ends_at": null,
  "total_quota": 1000,
  "per_learner_claim_limit": 1,
  "per_learner_use_limit": 1
}
```

**成功 `201`**: `AdminCouponCampaignDTO`

**错误**:

- `VALIDATION_FAILED` — `COUPON_RULE_INVALID`、`COUPON_SCOPE_REQUIRED`、`COUPON_DATE_INVALID`
- `403 FORBIDDEN` — 无 `coupon.manage`

---

## 更新活动

| 方法 | 路径 | 说明 |
|------|------|------|
| PATCH | `/coupons/{id}` | 未开始可改全部；进行中仅可改 `name`、`claim_ends_at`、`use_ends_at`、`total_quota`（仅增） |

**Body**: 部分字段 + `expected_updated_at`（乐观锁，与 banner 模式一致）

**冲突 `409`**: `COUPON_VERSION_CONFLICT`

---

## 停用活动

| 方法 | 路径 | 说明 |
|------|------|------|
| POST | `/coupons/{id}/disable` | `status → disabled` |

**成功 `204`**

---

## 定向发放

| 方法 | 路径 | 说明 |
|------|------|------|
| POST | `/coupons/{id}/grants` | 向学员发放实例 |

**Body**:

```json
{
  "learner_ids": [101, 102]
}
```

**成功 `200`**: `{ granted: number, skipped: number, items: LearnerCouponDTO[] }`

**错误**:

- `VALIDATION_FAILED` — `COUPON_QUOTA_EXCEEDED`、`COUPON_CLAIM_LIMIT_EXCEEDED`
- `CONFLICT` — `COUPON_NOT_GRANTABLE`（非 admin_only 或已停用）

---

## 使用记录

| 方法 | 路径 | 说明 |
|------|------|------|
| GET | `/coupon-redemptions` | 分页 |

**查询参数**: `campaign_id?`, `learner_id?`, `from?`, `to?`, `page`, `limit`

**成功 `200`**: 列表项含 `learner_masked_phone`, `course_title`, `order_id`, `discount_amount`, `used_at`

---

## 审计

创建、更新、停用、发放写入 `audit_log`（`coupon.create` / `coupon.update` / `coupon.disable` / `coupon.grant`）。
