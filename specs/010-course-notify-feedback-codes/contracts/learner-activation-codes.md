# 学习端激活码 API

前缀 `/api/learner/v1`. 需学员访问令牌.

## 兑换

| 方法 | 路径 | 限流 |
|------|------|------|
| POST | `/activation-codes/redeem` | 每学员 (否则 IP) 8 次 / 60 秒, 超限 `429 RATE_LIMITED` |

**Body**: `{ "code": "AB3D-EFGH-JKMN-PQRS" }`

服务端去空白与横线后大写再哈希. 大小写与横线不影响校验.

**成功 `200`**:

```json
{
  "granted": true,
  "course_id": 42,
  "course_title": "示例课",
  "source": "activation_code"
}
```

此后 `viewer_authorized=true`, 「我的学习」出现该课, 课程学员名单获得方式为 `activation_code`. 不创建购买订单.

**错误** (均不消耗码, 除「已兑换」表示该码早已被他人或自己成功用过):

| HTTP | code | 何时 |
|------|------|------|
| 401 | `UNAUTHENTICATED` | 未登录 |
| 422 | `ACTIVATION_CODE_INVALID` | 不存在或格式非法 |
| 409 | `ACTIVATION_CODE_REDEEMED` | 已被兑换 |
| 409 | `ACTIVATION_CODE_VOID` | 已作废 |
| 409 | `ACTIVATION_CODE_EXPIRED` | 已过期 |
| 409 | `ACTIVATION_CODE_COURSE_UNAVAILABLE` | 绑定课程当前非已发布 |
| 409 | `ENTITLEMENT_ALREADY_ACTIVE` | 学员已有该课有效访问权 (码保持 unused) |
| 429 | `RATE_LIMITED` | 触发限流 |

并发同一码: 恰好一名学员 `200`, 其余 `ACTIVATION_CODE_REDEEMED`.
