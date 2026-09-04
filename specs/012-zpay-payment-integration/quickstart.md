# Quickstart — 012 Z-Pay 接入

**Feature**: `012-zpay-payment-integration`
**Goal**: 端到端验证 z-pay 商户接入、管理端配置 / 白名单、异步回调闭环。

---

## 前置条件

- ✅ `make rebuild-api` 通过，API 容器启动后健康检查 OK
- ✅ `make rebuild-admin` 通过
- ✅ 数据库迁移已执行：`make migrate`（运行 `20260904000001_payment_config_and_whitelist.php`）
- ✅ `PAYMENT_KEY_ENC_KEY` env 已注入（32 字节 base64 字符串）
- ✅ 已有学员账号（`accounts.kind='learner'`，`login` 是 11 位手机号）
- ✅ 已有管理端账号（`accounts.kind='staff'`，带 `site.manage` 权限）
- ✅ 一门已发布 / 付费课程（`courses.status='published'`, `price_mode='paid'`, `list_price>0`）

---

## 场景 1：管理端填写支付配置

**目标**: 验证配置表单提交、AES 加密、掩码回显、audit_log 落库。

### 步骤

1. 登录管理端 → 「系统设置 → 支付配置」(/admin/site/payment-config)
2. 期望：表单初始为「尚未配置」空态，所有字段为空
3. 填写：
   - `api_url`: `https://z-pay.cn/`
   - `pid`: `20220726190052`
   - `merchant_key`: `<your-merchant-key>`
   - `notify_url`: `https://<your-host>/api/internal/v1/payments/zpay/notify`
   - `return_url`: `https://<your-host>/orders/result`
   - `enabled_channels`: 勾选 `wxpay` + `alipay`
   - `whitelist_only`: 开启
4. 提交
5. 期望：
   - 页面刷新，「启用支付」开关为「开」
   - `merchant_key` 字段显示为 `********GIJ`（最后 4 位明文 + 8 个星号）
   - `audit_log` 新增一条 `action='payment_config.update'`, `target_type='payment_config'`, `target_id=1`
6. 刷新页面，期望：配置仍然存在（持久化生效）

### 验证

```bash
# 数据库直接验证
docker exec -it <api-container> mysql -uroot -p<pwd> learn \
  -e "SELECT id, api_url, pid, LEFT(merchant_key_cipher, 40), enabled, whitelist_only, version FROM payment_config WHERE id=1;"
# 期望：merchant_key_cipher 列以 "v1:" 开头，enabled=1，whitelist_only=1
```

---

## 场景 2：管理端维护测试白名单

**目标**: 验证白名单增删改、软删、audit_log。

### 步骤

1. 「系统设置 → 支付白名单」(/admin/site/payment-whitelist)
2. 点击「新增」
3. 填写 `phone=13800001234`, `note=运营测试`, `enabled=true`
4. 提交
5. 期望：列表出现新条目，手机号脱敏显示 `138****1234`
6. 重复添加同一手机号
7. 期望：`WHITELIST_DUPLICATE` 错误提示
8. 切换该条目 `enabled=false`
9. 期望：状态变为「停用」；写 `audit_log`（`action='payment_whitelist.update'`）
10. 点击「移除」
11. 期望：弹出 `ElMessageBox.confirm` 二次确认；确认后条目消失；`audit_log` 写 `action='payment_whitelist.delete'`；数据库 `deleted_at` 不为 NULL

### 验证

```bash
docker exec -it <api-container> mysql -uroot -p<pwd> learn \
  -e "SELECT id, phone, enabled, note, created_by, deleted_at FROM payment_whitelist WHERE phone='13800001234';"
```

---

## 场景 3：白名单生效 — 学员侧拦截

**目标**: 验证 `whitelist_only=true` 时未在白名单内的学员无法下单。

### 步骤

1. 学员 A（手机 `13900000000`，**不在白名单**）调用：
   ```bash
   curl -X POST http://<api>/api/learner/v1/courses/1/orders \
     -H "Authorization: Bearer <learner-a-access-token>" \
     -H "Content-Type: application/json" \
     -d '{"channel":"wxpay"}'
   ```
2. 期望：返回 `403 {"code":"FORBIDDEN","message":"NOT_IN_WHITELIST"}`
3. 把 `13900000000` 加入白名单并启用
4. 再次请求
5. 期望：返回 `200`，`data.payment.redirect_url` 为 z-pay 收银台地址

---

## 场景 4：异步回调闭环

**目标**: 验证 z-pay 回调 → 签名校验 → 订单改 succeeded → 发放权益。

### 步骤

1. 学员 A（白名单内）下单 → 拿到 `order_id` 与 `out_trade_no`
2. 在 z-pay 商户后台「补单」触发回调，参数：
   - `pid=20220726190052`
   - `type=wxpay`
   - `out_trade_no=<orderId>`
   - `trade_no=20260904001234567890`
   - `name=<课程名>`
   - `money=<与下单金额一致>`
   - `trade_status=TRADE_SUCCESS`
   - `sign=<按规则生成的 MD5>`
3. z-pay 推送到 `/api/internal/v1/payments/zpay/notify`
4. 期望：
   - HTTP 200 + `{"received":true,"status":"succeeded"}`
   - `orders.status='succeeded'`, `provider_ref='20260904001234567890'`, `succeeded_at` 不为 NULL
   - `course_entitlements` 新增一条（learner=A, course=1, source='purchase', source_id=<orderId>）
   - `audit_log` 写 `action='zpay.notify.succeeded'`, `actor_id=0`, `target_id=<orderId>`
5. 学员侧刷新订单详情，状态变为「已支付 / 已成功」

### 重放

1. 用同一 `trade_no` 再次推送
2. 期望：HTTP 200；订单 `status` 仍为 `succeeded`；`course_entitlements` 不新增（幂等）；`audit_log` 不新增（仅 Logger 记录 `order.notify.skipped`）

### 失败路径

1. 推送 `trade_status=TRADE_CLOSED`
2. 期望：HTTP 200 + `{"status":"failed"}`；订单 `status='failed'`；不发权益；`coupon_discount_snapshot > 0` 时释放锁定优惠券

### 金额不一致

1. 推送 `money=0.01`（与下单金额 `0.02` 不一致）
2. 期望：HTTP 200 + `{"status":"ignored"}`；订单状态不变；`audit_log` 写 `action='zpay.notify.amount_mismatch'`

### 未知订单

1. 推送 `out_trade_no=9999999`（不存在的订单）
2. 期望：HTTP 200 + `{"status":"ignored"}`；`audit_log` 写 `action='zpay.notify.unknown_order'`

---

## 场景 5：同步回调

**目标**: 验证 `return_url` 验签 + 重定向，不改订单状态。

### 步骤

1. 学员侧构造 URL：`/api/internal/v1/payments/zpay/return?pid=...&type=...&out_trade_no=...&trade_no=...&name=...&money=...&trade_status=TRADE_SUCCESS&sign=<正确签名>`
2. 浏览器访问
3. 期望：302 跳转到 `${APP_BASE_URL}/orders/{orderId}?status=pending&trade_no=<trade_no>`
4. 此时订单状态**仍为 `pending`**（同步回调不调 markSucceeded）
5. 学员侧前端应轮询订单详情或显示「支付处理中」

### 签名失败

1. 修改 `sign` 字段
2. 浏览器访问
3. 期望：302 跳转到 `${APP_BASE_URL}/orders?status=invalid`；`audit_log` 写 `action='zpay.return.invalid_signature'`

---

## 场景 6：API 测试套件

**目标**: phpunit / vitest 全部通过。

```bash
# 后端
docker exec -it <api-container> ./vendor/bin/phpunit \
  apps/api/tests/ZPayPaymentAdapterTest.php \
  apps/api/tests/PaymentConfigServiceTest.php \
  apps/api/tests/PaymentNotifyControllerTest.php \
  apps/api/tests/OrderServiceWhitelistTest.php
# 期望：所有测试通过

# 前端
cd apps/admin && pnpm vitest run \
  tests/views/payment/PaymentConfigForm.spec.ts \
  tests/views/payment/PaymentWhitelistView.spec.ts
# 期望：所有测试通过

# 既有契约不退化
docker exec -it <api-container> ./vendor/bin/phpunit \
  apps/api/tests/PaymentContractTest.php
```

---

## 场景 7：channel 字段学员侧选择

**目标**: 验证学员能选 wxpay / alipay，未启用通道被拒。

### 步骤

1. 管理端把 `enabled_channels` 改为 `["alipay"]`（移除 wxpay）
2. 学员 A 调用 `POST /api/learner/v1/courses/1/orders` body `{"channel":"wxpay"}`
3. 期望：`409 {"code":"CONFLICT","message":"PAYMENT_CHANNEL_DISABLED"}`
4. 改 body `{"channel":"alipay"}`
5. 期望：下单成功

---

## 验收

- [ ] 配置提交后 30s 内学员侧可感知到（依赖 cache 失效）
- [ ] 学员在白名单 → 1 次请求 200
- [ ] 学员不在白名单 → 1 次请求 403
- [ ] 异步回调 5s 内完成（含队列派发）
- [ ] 重放 100 次不重复发权益
- [ ] 全链路日志 / 响应均无明文 `merchant_key`
- [ ] `audit_log` 可按 `target_type='payment_config'\|'payment_whitelist'` 检索
- [ ] phpunit + vitest 全部通过
- [ ] `make phpstan` 通过（新增类全部覆盖）
