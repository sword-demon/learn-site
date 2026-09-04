# Contracts — 012 Z-Pay 接入 (Webhook 协议)

**Feature**: `012-zpay-payment-integration`
**Date**: 2026-09-04

> 备注：本文件是 `contracts/payment-admin.md` 之外的补充，专门描述 z-pay 与我们之间的回调 / 通知协议（外部契约）。

---

## 1. 异步回调字段集（POST /api/internal/v1/payments/zpay/notify）

z-pay 推送的字段（与 `docs/epay/notify.php` 同源）：

| 字段 | 类型 | 必填 | 含义 |
|------|------|------|------|
| `pid` | string | 是 | 商户 ID |
| `type` | string | 是 | `alipay` / `wxpay` |
| `out_trade_no` | string | 是 | 商户订单号（与我们 `orders.id` 1:1） |
| `trade_no` | string | 是 | z-pay 流水号（写入 `orders.provider_ref`） |
| `name` | string | 是 | 商品名（透传） |
| `money` | string | 是 | 金额，保留 2 位小数（**需与本地订单 `paid_amount` 强校验**） |
| `param` | string | 否 | 透传参数（暂未使用） |
| `trade_status` | string | 是 | `TRADE_SUCCESS` / 其它 |
| `sign_type` | string | 是 | `MD5` |
| `sign` | string | 是 | MD5 签名（小写） |

**签名生成**（与 `docs/epay/config.php::get_sign` 一致）：

```
1. 取全部非空字段，过滤 sign / sign_type
2. ksort 按 key 升序
3. 拼接 k=v&k=v（无 urlencode）
4. 末尾追加 &key=<merchant_key>
5. strtolower(md5(...))
```

---

## 2. 同步回调字段集（GET /api/internal/v1/payments/zpay/return）

字段集与异步回调完全相同；区别：
- HTTP method = GET
- 学员浏览器主动跳，**仅做签名校验 + 写审计 + 302 重定向**
- 不调用 `markSucceeded` / `markFailed`（防伪造 URL 跳成功）

---

## 3. 我们对外响应

### 异步回调响应

| 场景 | HTTP | Body |
|------|------|------|
| 签名校验通过 + 已派发 OrderService | 200 | `{"code":"OK","data":{"received":true,"status":"<succeeded\|failed\|cancelled\|unknown>"}}` |
| 签名校验失败 | 400 | `{"code":"VALIDATION_FAILED","message":"PAYMENT_NOTIFY_INVALID"}` |
| 解析失败（缺字段） | 400 | `{"code":"VALIDATION_FAILED","message":"PAYMENT_NOTIFY_PARSE"}` |
| 金额不一致 / 未知订单 | 200 | `{"code":"OK","data":{"received":true,"status":"ignored"}}` + 写 audit_log |

### 同步回调响应

| 场景 | HTTP | Location |
|------|------|----------|
| 签名校验失败 | 302 | `${APP_BASE_URL}/orders?status=invalid` |
| 签名校验通过 | 302 | `${APP_BASE_URL}/orders/{orderId}?status=pending&trade_no=<trade_no>` |

---

## 4. 我们对外发起（主动调用 z-pay）

仅一处：`ZPayPaymentAdapter::createCharge()` 在学员下单时调用 z-pay `submit.php`（GET 跳转）：

| 字段 | 含义 |
|------|------|
| `pid` | 商户 ID |
| `type` | `wxpay` / `alipay` |
| `notify_url` | 异步回调（我们配置） |
| `return_url` | 同步回调（我们配置） |
| `out_trade_no` | `${orderId}`（数字串） |
| `name` | 课程标题（限制 64 字符） |
| `money` | `${paid_amount}`（保留 2 位） |
| `param` | 留空 |
| `sign_type` | `MD5` |
| `sign` | 按上述规则生成 |

返回的 `redirect_url` 透传给学员侧（302 跳转 / 学员浏览器打开）。
