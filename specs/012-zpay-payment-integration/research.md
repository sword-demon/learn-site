# Research — 012 Z-Pay 接入

**Feature**: `012-zpay-payment-integration`
**Date**: 2026-09-04
**Status**: ✅ All clarifications resolved

---

## R001: 现有 PaymentAdapter 接缝与装配方式

**问题**: 适配器在容器里如何装配？新增 ZPayPaymentAdapter 时是否要改 OrderService？

**调研**: 阅读 `apps/api/config/dependence.php` 与 `OrderService.php` 构造器。

**Findings**:
- `dependence.php` 已绑定 `PaymentAdapter::class => new FakePaymentAdapter()`
- `OrderService` 通过 `__construct(... private readonly PaymentAdapter $payment ...)` 接收
- `OrderService::__construct` 有一段 `if ($payment instanceof FakePaymentAdapter) { $payment->setSuccessHandler(...) }` 自动接线 Fake 的成功回调
- 容器解析走 `App\support\AutowireContainer`（autowire by reflection），依赖即声明

**Decision**:
- `ZPayPaymentAdapter` 实现 `PaymentAdapter` 接口（与 Fake 同一接缝）
- 在 `OrderService::__construct` 增加 `if ($payment instanceof ZPayPaymentAdapter) { $payment->setSuccessHandler(...) }` 分支，或更通用：提取 `FakePaymentAdapter` 的 `setSuccessHandler` 到接口本身。**为最小 diff，沿用 `if instanceof` 分支模式**
- `dependence.php` 默认 `PaymentAdapter::class => new ZPayPaymentAdapter(...)`；保留 `FakePaymentAdapter` 通过 `APP_ENV=testing` 或 `PAYMENT_DRIVER=fake` 切换
- 切换不要求 `OrderService` 改业务逻辑

**Alternatives considered**:
- A) 引入工厂方法 `PaymentAdapterFactory::make($driver)` → 拒绝（多一个抽象，仅两实现，不值）
- B) 改 `PaymentAdapter` 接口为 abstract class → 拒绝（违反既有约定，扩面过大）

---

## R002: z-pay 协议签名细节

**问题**: `docs/epay/config.php` 给出的是 MD5 签名 + URL form 提交，是否有 RSA / 证书模式？

**调研**: 读取 `docs/epay/{config,api,index,notify,return,query,refund}.php` 与官方 `https://z-pay.cn/doc.html` 行为描述。

**Findings**:
- 协议签名：`ksort` → 过滤 `sign` / `sign_type` 与空值 → `k=v&k=v` 拼接 → 末尾追加 `&key=<merchant_key>` → `strtolower(md5(...))`
- 接口：`https://z-pay.cn/submit.php` 走跳转；`https://z-pay.cn/mapi.php` 走 API；回调既支持 GET（return_url）也支持 POST（notify_url），参数集相同
- 订单状态：`TRADE_SUCCESS` = 成功；`TRADE_CLOSED` / 其它 = 失败 / 关闭
- 退款：`https://z-pay.cn/api.php?act=refund`（不在本 spec 范围）

**Decision**:
- `ZPayPaymentAdapter::createCharge()` 走 `submit.php`（跳转模式），返回 `redirect_url`
- `ZPayPaymentAdapter::parseNotify()` 同时支持 GET 与 POST（同一签名逻辑）
- 签名 helper 私有方法 `private function sign(array $payload, string $merchantKey): string`
- 文档字段顺序：pid → type → notify_url → return_url → out_trade_no → name → money → param → sign_type → sign

**Alternatives considered**:
- mapi（API 模式返回 code_url 二维码）→ 学员侧扫码体验需自己生成二维码；跳转模式更简单，沿用 z-pay 默认收银台

---

## R003: `merchant_key` 落库加密方案

**问题**: 商户密钥属于敏感凭据，标准项目宪法禁止明文落库

**调研**: 阅读项目宪法 §V「质量、安全与可运维性内建」与 `apps/api/app/support/` 现有加密 helper

**Findings**:
- 项目无现成的字段级加密 helper（hash 用 `password_hash`，无对称加密工具类）
- 容器 env 中 `APP_BASE_URL` 等明文配置有约定，但 `merchant_key` 必须加密（与宪法一致）
- PHP 8.x 标准库 `openssl_encrypt` 支持 AES-256-GCM（`aes-256-gcm` cipher），返回 base64(iv) + base64(cipher) + base64(tag)
- 加密密钥从 env `PAYMENT_KEY_ENC_KEY`（32 字节 base64）注入

**Decision**:
- `apps/api/app/support/Crypto/AesGcmCipher.php` 新增薄包装（一个静态类：encrypt / decrypt）
- 数据库列 `merchant_key_cipher` 存 `v1:<base64(iv)>:<base64(cipher)>:<base64(tag)>`
- 加密密钥缺失时启动 fail-fast（`BusinessException('INTERNAL', 'PAYMENT_KEY_ENC_KEY_NOT_CONFIGURED')`）
- API 响应永远只回显 `merchant_key_masked = str_repeat('*', 8) . substr($key, -4)` 形式

**Alternatives considered**:
- A) `sodium_crypto_box_seal`（非对称）→ 拒绝（仅自身解密需求，对称足够；引入 libsodium 扩展增加部署面）
- B) 仅掩码存数据库（明文 → 用时再查）→ 拒绝（违反宪法「密钥不得进入代码、镜像、Compose 文件或版本控制」）
- C) 存到独立 Vault 服务 → 拒绝（超出本 spec 范围；本 spec 仅落库加密 + env 注入）

---

## R004: 白名单匹配数据源

**问题**: 学员手机号存在哪？`Learner` 模型 PK 是 `account_id`，但 `phone` 实际落在 `accounts.login` 上

**调研**: 阅读 `apps/api/database/migrations/20260823000001_create_accounts.php` 与 `apps/api/app/model/Learner.php`

**Findings**:
- `accounts(kind, login)` 唯一；学员 `kind='learner'`, `login` 是 11 位手机号
- `learners` 表只存 `account_id` 外键 + nickname/avatar 等扩展
- 既有 `OrderService::createPending` 通过 `learnerId`（即 `account_id`）拿到学员
- `accounts.login` 是学员手机号的唯一来源

**Decision**:
- `OrderService::createPending` 在拉起适配器前，先 `Db::name('accounts')->where('id', $learnerId)->value('login')` 拿到手机号
- 再 `PaymentConfigService::isWhitelisted($phone)` 命中校验
- 缓存：白名单条目高频读低频写，用 `RuntimeCache` 60s TTL（与 `HomeCache` 同款）；写操作清缓存
- 手机号匹配按精确等值（`accounts.login = whitelist.phone`），不做模糊

**Alternatives considered**:
- 把 `phone` 字段冗余到 `learners` 表 → 拒绝（重复存储，无收益；既已 `accounts.login`）
- 用 join 一次查 → 接受（写更简单，但每订单多一次 SQL；缓存 + 单次 `value()` 解决）

---

## R005: z-pay 回调路径与中间件

**问题**: 既有 `/api/internal/v1/payments/fake/notify` 怎么注册的？z-pay 回调是否要走相同中间件组？

**调研**: 阅读 `apps/api/app/route.php` 中 internal 路由块

**Findings**:
- 现有 fake 回调仅在 `APP_ENV === 'testing'` 时注册
- 没有走 `LearnerAuth` / `AdminAuth` 中间件（provider 走签名而不是 token）
- 没有走 `RateLimit`（避免因重试被限流）

**Decision**:
- z-pay 异步 / 同步回调**在所有环境都注册**（生产环境也必须注册，否则真钱不到账）
- 不挂 `LearnerAuth` / `AdminAuth`；不挂 `RateLimit`
- 路径：`/api/internal/v1/payments/zpay/notify`（POST，异步）+ `/api/internal/v1/payments/zpay/return`（GET，同步）
- 同步回调不挂中间件但只做签名 + 写审计 + 302 重定向（不调用任何业务方法）

**Alternatives considered**:
- 把回调移到 `/api/webhooks/zpay/...` → 拒绝（既有 internal 命名一致；统一路径便于运维）

---

## R006: 管理端页面与导航

**问题**: 支付配置 / 白名单两个页放在哪个菜单？URL 是什么？

**调研**: 阅读 `apps/admin/src/layouts/AdminMenu.ts` 与 `apps/admin/src/router/index.ts`

**Findings**:
- 现有菜单 `站点资料` (`/site/profile`) 与 `审计日志` (`/site/audit`) 在「系统设置」分组
- 路由全部挂在 `/admin` 下（`AdminLayout` 的 `<router-view>`）；其它模块用 `path: 'site/profile'` 作为子路由

**Decision**:
- 菜单项：新增 `系统设置 → 支付配置`(`/admin/site/payment-config`, permission=`site.manage` 复用) 与 `系统设置 → 支付白名单`(`/admin/site/payment-whitelist`, 同 permission)
- 复用现有 `site.manage` 权限码（不新增权限码 → 减少权限矩阵扩面）
- 路由注册在 `router/index.ts` 的 `children: [...]` 块（与 `site/profile` 同级）

**Alternatives considered**:
- A) 单独建一级菜单「支付」 → 拒绝（与既有「系统设置」分组合并更一致）
- B) 新增权限码 `payment.manage` / `payment.whitelist.manage` → 拒绝（与宪法「无未请求抽象」一致；复用 `site.manage` 即可）

---

## R007: AuditLog 落点

**问题**: 配置变更 / 白名单变更 / 回调异常 → `audit_log` 表怎么写？

**调研**: 阅读 `apps/api/app/service/BannerService.php::writeAudit` 与 `apps/api/app/model/` 中 `audit_log` 表结构

**Findings**:
- 既有 `writeAudit(int $staffId, string $action, int $targetId, array $payload)` 私有方法模式
- 字段：`actor_id`, `action`, `target_type`, `target_id`, `payload_json`, `created_at`
- 回调异常场景（无 staff 上下文）→ 用 0 作为 actor_id（系统行为）

**Decision**:
- `PaymentConfigService::writeAudit($staffId, $action, $targetId, $payload)` 私有方法（与 Banner 同款）
- 白名单同理：`PaymentWhitelistService::writeAudit(...)`
- 回调入口（`PaymentNotifyController::zpayNotify`）在 `Logger` 里 `payload_json` 写 `'actor_id' => 0, 'action' => 'zpay.notify.<status>'`，不进 `audit_log` 表（避免与配置变更混淆）
- 重要：密钥变更不写明文到 payload，仅写「changed: true」+ 旧/新 pid

**Alternatives considered**:
- A) 用 `ModerationLogService` 替代 → 拒绝（既写的是审核 / 处分场景，与配置变更不同）
- B) 回调异常也写 `audit_log` → 拒绝（量大、易爆表；只写 `Logger` + 关键异常写 `audit_log`）

---

## R008: 测试覆盖策略

**问题**: 哪些路径必须测试？测试驱动 vs 实现先行的顺序？

**调研**: 阅读宪法 §V 与既有 `PaymentContractTest.php`

**Findings**:
- 既有 `PaymentContractTest` 校验 PaymentAdapter 契约
- 项目 vitest 前端 + phpunit 后端；本 spec 主要后端代码 + 少量前端表单

**Decision**:
- 后端 phpunit（必须）：
  - `ZPayPaymentAdapterTest` — 签名计算 / 验签 / 回调解析（成功 / 失败 / 重放 / 金额不一致 / 未知订单）
  - `PaymentConfigServiceTest` — AES round-trip / mask 生成 / whitelist 命中
  - `PaymentNotifyControllerTest` — 集成测试（mock Db / OrderService）
  - `OrderServiceTest` — 白名单拦截（命中 / 未命中 / 停用条目）
- 前端 vitest（必须）：
  - `PaymentConfigForm.spec.ts` — 字段校验、提交、密钥字段不显示明文
  - `PaymentWhitelistView.spec.ts` — 增删改查、状态切换、ElMessageBox 二次确认
- 既有 `PaymentContractTest` 必须继续通过（Fake 路径不退化）

**Order**: TDD 严格按 RED → GREEN → IMPROVE；先写测试再写实现

---

## R009: 学员侧 `channel` 字段传递路径

**问题**: 学员需要选「微信 / 支付宝」，`channel` 字段怎么从前端传到适配器？

**调研**: 阅读 `apps/api/app/controller/learner/OrderController.php` 与 `OrderService::createPending` 签名

**Findings**:
- 现有 `OrderController::create` 接 `learner_coupon_id` 字段
- `OrderService::createPending($learnerId, $courseId, $learnerCouponId)` 不接 channel

**Decision**:
- 扩展 `OrderService::createPending` 第四个参数 `?string $channel = null`
- `OrderController::create` 解析 body `channel` → Zod 校验 enum（`wxpay`/`alipay`）
- `ZPayPaymentAdapter::createCharge()` 接 `string $channel` 参数（通过 PaymentAdapter 接口扩展？—— 保留接口兼容性，最小方案：给 `createCharge` 加默认 `?string $channel = null`，Fake 忽略）
- PaymentAdapter 接口签名变更为 `createCharge(int $orderId, float $amount, string $currency, ?string $channel = null)`
- 既有 Fake 适配器保持不传，行为不变

**Alternatives considered**:
- A) 新增第二个接口方法 `createChargeWithChannel` → 拒绝（接口分裂，违反既有约定）
- B) 把 channel 塞进 order 表的 `provider` 字段 → 拒绝（语义不清；`provider='zpay'` + `channel='wxpay'` 拆开更清晰）

---

## R010: 同步回调 `return_url` 行为

**问题**: 同步回调是学员浏览器主动跳的 URL；学员可能伪造这个 URL 跳到「支付成功」页面骗自己

**调研**: 阅读 `docs/epay/return.php` 与 z-pay 官方说明

**Findings**:
- 同步回调是 GET 请求，参数集与异步相同
- 仅做签名校验，不改订单状态（行业惯例）
- 服务端应将学员重定向到前端 `/orders/{id}?status=...`，由前端按订单真实状态展示

**Decision**:
- `PaymentNotifyController::zpayReturn`：
  - 验签失败 → 302 到 `${APP_BASE_URL}/orders?status=invalid`
  - 验签成功 → 302 到 `${APP_BASE_URL}/orders/{orderId}?status=pending&trade_no=<trade_no>`（status=pending 因为订单真实状态可能还在 pending；前端会通过轮询 / 拉详情确认）
- 同步回调**不调用 `markSucceeded` / `markFailed`**

**Alternatives considered**:
- A) 同步回调里强制查一次 z-pay `query.php` 拿最新状态 → 拒绝（query 在 Out of Scope；且不应让学员侧延迟决定状态变更）
- B) 同步回调直接置 succeeded → 拒绝（学员伪造 URL 风险）

---

## Summary

| ID | Topic | Decision | Risk |
|----|-------|----------|------|
| R001 | PaymentAdapter 装配 | 沿用 `dependence.php` 绑定，instanceof 分支接 success handler | 低 |
| R002 | 协议签名 | MD5，按 `docs/epay/config.php::get_sign` 实现 | 低 |
| R003 | 密钥落库 | AES-256-GCM + env 注入 key；mask 回显 | 低 |
| R004 | 白名单匹配 | `accounts.login` 精确匹配 + 60s cache | 低 |
| R005 | 回调路径 | `/api/internal/v1/payments/zpay/{notify,return}` 无中间件 | 低 |
| R006 | 管理端菜单 | 复用 `site.manage` 权限，挂在「系统设置」分组 | 低 |
| R007 | AuditLog | 私有 `writeAudit` helper；回调异常只写 Logger | 低 |
| R008 | 测试覆盖 | phpunit 后端 + vitest 前端；既有契约不退化 | 中 |
| R009 | channel 字段 | PaymentAdapter 接口加 `?string $channel` 默认参数 | 低 |
| R010 | 同步回调 | 仅验签 + 重定向，不改订单 | 低 |
