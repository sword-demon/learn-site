# Contracts — 012 Z-Pay 接入

**Feature**: `012-zpay-payment-integration`
**Date**: 2026-09-04

本规格暴露四组契约：
1. `packages/contracts/src/paymentConfig.ts`（前后端共用 Zod schema）
2. `packages/contracts/src/paymentWhitelist.ts`（前后端共用 Zod schema）
3. 后端 REST 端点（管理端 + 内部回调 + 学员侧扩展）
4. `PaymentAdapter` PHP 接口（既有，本次仅扩展可选参数）

---

## 1. Zod 契约 — `paymentConfig.ts`

```ts
import { z } from 'zod';

export const PaymentChannel = z.enum(['wxpay', 'alipay']);
export type PaymentChannel = z.infer<typeof PaymentChannel>;

export const PaymentConfig = z.object({
  enabled: z.boolean(),
  api_url: z.string().url().endsWith('/'),
  pid: z.string().min(8).max(64),
  merchant_key_masked: z.string().regex(/^\*{8,}\w{0,4}$|^$/),  // 服务端永远不返回明文
  notify_url: z.string().url(),
  return_url: z.string().url(),
  enabled_channels: z.array(PaymentChannel).min(1),
  whitelist_only: z.boolean(),
  updated_at: z.string().datetime().nullable(),
});
export type PaymentConfig = z.infer<typeof PaymentConfig>;

export const PaymentConfigUpdateInput = z.object({
  enabled: z.boolean(),
  api_url: z.string().url().endsWith('/'),
  pid: z.string().min(8).max(64),
  merchant_key: z.string().max(64),  // 非空时至少 8 位；已有配置可传空字符串以保留旧密钥
  notify_url: z.string().url(),
  return_url: z.string().url(),
  enabled_channels: z.array(PaymentChannel).min(1),
  whitelist_only: z.boolean(),
});
export type PaymentConfigUpdateInput = z.infer<typeof PaymentConfigUpdateInput>;
```

---

## 2. Zod 契约 — `paymentWhitelist.ts`

```ts
import { z } from 'zod';

export const PaymentWhitelistEntry = z.object({
  id: z.number().int().positive(),
  phone_masked: z.string().regex(/^1\d{2}\*{4}\d{4}$|^1\d{10}$/),  // 列表展示可全量；详情可全量
  phone: z.string().regex(/^1\d{10}$/).optional(),  // 仅创建 / 编辑时使用
  enabled: z.boolean(),
  note: z.string().max(120).nullable(),
  created_at: z.string().datetime(),
  updated_at: z.string().datetime(),
});
export type PaymentWhitelistEntry = z.infer<typeof PaymentWhitelistEntry>;

export const PaymentWhitelistCreateInput = z.object({
  phone: z.string().regex(/^1\d{10}$/, 'INVALID_PHONE'),
  enabled: z.boolean().default(true),
  note: z.string().max(120).optional(),
});
export type PaymentWhitelistCreateInput = z.infer<typeof PaymentWhitelistCreateInput>;

export const PaymentWhitelistUpdateInput = z.object({
  enabled: z.boolean(),
  note: z.string().max(120).nullable(),
});
export type PaymentWhitelistUpdateInput = z.infer<typeof PaymentWhitelistUpdateInput>;

export const PaymentWhitelistListResponse = z.object({
  items: z.array(PaymentWhitelistEntry),
  total: z.number().int().nonnegative(),
  page: z.number().int().positive(),
  limit: z.number().int().positive(),
});
export type PaymentWhitelistListResponse = z.infer<typeof PaymentWhitelistListResponse>;
```

---

## 3. 后端 REST 端点

### 3.1 管理端 — 支付配置

| Method | Path | 鉴权 | 用途 |
|--------|------|------|------|
| `GET` | `/api/admin/v1/payment/config` | `AdminAuth` + `site.manage` | 拉取当前配置（不返回明文） |
| `PATCH` | `/api/admin/v1/payment/config` | `AdminAuth` + `site.manage` | 更新配置（写明文，落库前 AES 加密） |

**GET 响应**：
```json
{
  "code": "OK",
  "data": {
    "enabled": false,
    "api_url": "https://z-pay.cn/",
    "pid": "20220726190052",
    "merchant_key_masked": "********GIJ",
    "notify_url": "https://learn.example.com/api/internal/v1/payments/zpay/notify",
    "return_url": "https://learn.example.com/orders/result",
    "enabled_channels": ["wxpay", "alipay"],
    "whitelist_only": true,
    "updated_at": "2026-09-04T15:23:11+08:00"
  }
}
```

**PATCH 请求**：
```json
{
  "enabled": true,
  "api_url": "https://z-pay.cn/",
  "pid": "20220726190052",
  "merchant_key": "<your-merchant-key>",
  "notify_url": "https://learn.example.com/api/internal/v1/payments/zpay/notify",
  "return_url": "https://learn.example.com/orders/result",
  "enabled_channels": ["wxpay", "alipay"],
  "whitelist_only": true
}
```

**错误码**：
- `INVALID_FIELDS:<field>`（Zod 校验失败）
- `PAYMENT_KEY_ENC_KEY_NOT_CONFIGURED`（env 缺失）
- `PAYMENT_CONFIG_VERSION_CONFLICT`（乐观锁冲突）

### 3.2 管理端 — 支付白名单

| Method | Path | 鉴权 | 用途 |
|--------|------|------|------|
| `GET` | `/api/admin/v1/payment/whitelist?page=1&limit=20` | `AdminAuth` + `site.manage` | 列表 |
| `POST` | `/api/admin/v1/payment/whitelist` | `AdminAuth` + `site.manage` | 新增 |
| `PATCH` | `/api/admin/v1/payment/whitelist/{id}` | `AdminAuth` + `site.manage` | 启用 / 停用 / 改备注 |
| `DELETE` | `/api/admin/v1/payment/whitelist/{id}` | `AdminAuth` + `site.manage` | 软删 |

**POST 请求**：
```json
{ "phone": "13800001234", "enabled": true, "note": "运营测试账号" }
```

**错误码**：
- `INVALID_PHONE`
- `WHITELIST_DUPLICATE`
- `WHITELIST_NOT_FOUND`

### 3.3 内部回调 — z-pay

| Method | Path | 中间件 | 用途 |
|--------|------|--------|------|
| `POST` | `/api/internal/v1/payments/zpay/notify` | 无（所有环境都注册） | 异步回调（幂等改单） |
| `GET` | `/api/internal/v1/payments/zpay/return` | 无 | 同步回调（验签 + 重定向） |

**异步回调响应**：
- 成功解析 + 已派发：`200 {"code":"OK","data":{"received":true,"status":"succeeded"}}`
- 签名失败 / 解析失败：`400 {"code":"VALIDATION_FAILED","message":"PAYMENT_NOTIFY_INVALID"}`
- 金额不一致 / 未知订单：写 `Logger` + `audit_log` + `200 {"code":"OK","data":{"received":true,"status":"ignored"}}`（防商户无限重试）

**同步回调响应**：
- 签名失败：302 → `${APP_BASE_URL}/orders?status=invalid`
- 成功：302 → `${APP_BASE_URL}/orders/{orderId}?status=pending&trade_no=<trade_no>`

### 3.4 学员侧 — 下单扩展

| Method | Path | 鉴权 | 用途 |
|--------|------|------|------|
| `POST` | `/api/learner/v1/courses/{id}/orders` | `LearnerAuth` | 现有；新增 `channel` 字段 |

**body 新增**：
```json
{ "learner_coupon_id": 123, "channel": "wxpay" }
```

`channel` 缺省 → `wxpay`；后端校验值 ∈ `{wxpay, alipay}`；`enabled_channels` 不包含 → `409 PAYMENT_CHANNEL_DISABLED`。

**学员侧白名单拦截错误**：
- `403 NOT_IN_WHITELIST`（`whitelist_only=true` 且手机号未命中白名单）

---

## 4. PaymentAdapter PHP 接口扩展

```php
// apps/api/app/support/payment/PaymentAdapter.php
interface PaymentAdapter
{
    /**
     * @return array<string,mixed> 包含 type, redirect_url/code_url, out_trade_no, amount, currency, provider, channel
     */
    public function createCharge(
        int $orderId,
        float $amount,
        string $currency,
        ?string $channel = null,  // 新增：'wxpay' | 'alipay' | null（Fake 忽略）
    ): array;

    public function parseNotify(\support\Request $request): ?NotifyResult;

    /** ZPayPaymentAdapter 暴露给 OrderService 接 success handler（与 Fake 现有模式对齐） */
    public function setSuccessHandler(callable $handler): void;
}
```

> 说明：`setSuccessHandler` 提升到接口；`FakePaymentAdapter` 与 `ZPayPaymentAdapter` 均实现。`OrderService::__construct` 改为统一调用 `$payment->setSuccessHandler(...)`（去除 `instanceof FakePaymentAdapter` 分支）。

---

## 5. NotifyResult 字段扩展（可选）

保留既有字段不变；如 implementation 阶段发现需要在 NotifyResult 携带 `trade_no` 之外的 `channel`，追加 `public readonly ?string $channel` 字段，默认 null，构造器第三个可选参数。
