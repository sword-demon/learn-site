# 012 Z-Pay 接入 — 微信支付 / 支付宝支付与管理端白名单

## Executive Summary

把当前的 `FakePaymentAdapter` 替换为基于 z-pay.cn（兼容易支付协议）的真实收单通道，覆盖微信支付（`wxpay`）和支付宝（`alipay`）两个通道。后台管理端提供支付参数配置页（商户 ID、密钥、回调地址、启用通道）以及测试白名单（仅允许指定学员手机号在生产环境发起测试订单）。支付成功 / 失败 / 取消的异步回调沿用既有 `NotifyResult` 模型与 `PaymentNotifyController` 分发，幂等由 `OrderService::markSucceeded/markFailed` 保证。

---

## User Scenarios & Testing

### Primary User Flow

1. **作为运营 / 管理员**，我想在管理端配置 Z-Pay 商户信息
   - 填入商户 ID、商户密钥、API 地址、异步回调地址
   - 选择启用微信支付 / 支付宝（可单选、可多选）
   - 切换启用 / 停用；停用后学员侧无法再创建该通道的订单
   - 配置落库（数据库持久化，非环境变量），重启容器后保留

2. **作为运营 / 管理员**，我想在管理端维护测试白名单
   - 添加一个或多个学员手机号；白名单条目可启用 / 停用
   - 查看白名单当前生效名单（手机号 + 状态 + 创建时间）
   - 移除白名单条目（失效不删除，便于审计）

3. **作为学员**，我在管理端已配置 Z-Pay + 当前账号在白名单内时下单
   - `POST /api/learner/v1/courses/{id}/orders` 返回的 `payment` 包含 `code_url`（扫码支付）或 `redirect_url`（跳转支付）
   - 商户侧完成付款后，Z-Pay 异步通知推送到我配置的 `notify_url`
   - 后端验证签名、更新订单为 `succeeded`、发放 `CourseEntitlement`

4. **作为学员**，我扫码后未支付
   - 商户侧超时关闭订单，Z-Pay 回调 `trade_status=TRADE_CLOSED` 或 `TRADE_FAIL`
   - 后端把订单置为 `failed`，原因记录在 `provider_ref` / 审计日志
   - 学员看到订单状态为「已关闭 / 失败」

5. **作为运营 / 管理员**，我在管理端查看订单与配置变更审计
   - `audit_log` 记录「谁在何时修改了支付配置」「谁在何时增删了白名单」
   - 订单详情页可见 `provider='zpay'`, `channel='wxpay'|'alipay'`, `provider_ref=trade_no`

### Edge Cases

- 商户密钥变更后，旧订单的回调因签名校验失败被拒（写入 `audit_log`，不静默丢）
- 异步回调与同步回调都到达时，两条路径都进入同一个幂等收敛（`markSucceeded` 重入不重复发权益）
- 学员不在白名单但管理端开启了白名单 → 学员创建订单时 `ForbiddenException('NOT_IN_WHITELIST')`
- 管理端未配置任一支付通道 → `createPending` 直接返回 `PAYMENT_DISABLED`
- 异步回调中 `out_trade_no` 在我们这边查不到对应订单 → 写 `audit_log` + 返回 200 避免商户无限重试
- 金额与系统订单金额不一致 → 回调校验拦截，不置为成功
- 重复收到同一 `trade_no` 的成功通知 → 幂等命中，无重复发权益
- 测试白名单条目处于停用状态 → 等同于未启用，学员侧表现为「不在白名单」

---

## Functional Requirements

### FR-001: Z-PayAdapter 实现（替换 FakePaymentAdapter 之外的第二个真实实现）

新增 `ZPayPaymentAdapter` 实现 `App\support\payment\PaymentAdapter`，覆盖微信支付和支付宝两个通道。

**Acceptance Criteria:**

- AC1: `createCharge()` 根据传入的 `channel='wxpay'|'alipay'` 调用 z-pay 提交接口 `https://z-pay.cn/submit.php`，参数按 `docs/epay/index.php` 的顺序：pid、type、notify_url、return_url、out_trade_no、name、money、param、sign_type、sign
- AC2: `createCharge()` 优先使用「跳转支付」流程（学员侧直接打开 z-pay 聚合收银台），返回 `{ type, redirect_url, out_trade_no, amount, currency, provider, channel }`
- AC3: `parseNotify()` 对 GET 同步回调（`return.php`）和 POST 异步回调（`notify.php`）使用同一签名校验逻辑（`docs/epay/notify.php` 的字段集）
- AC4: 签名算法按 `docs/epay/config.php::get_sign` 实现：剔除 `sign` / `sign_type`，按 key 升序拼接 `k=v&k=v` 后追加商户密钥 `&key=<merchant_key>`，做 `md5` 后转小写
- AC5: 异步回调中 `trade_status=TRADE_SUCCESS` → `NotifyResult::succeeded`；`TRADE_CLOSED` / `TRADE_FAIL` / 其它 → `NotifyResult::failed`
- AC6: 解析出的 `out_trade_no` 必须能在 `orders` 表查到对应订单；查不到返回 `null`，由 `PaymentNotifyController` 写审计后返回 200
- AC7: 异步回调中 `money` 字段与本地订单金额不一致 → 拒收，抛 `NotifyResult::failed` 但不更新订单（写 `audit_log`），返回 200 防重试
- AC8: 适配器所需的所有参数（api_url、pid、key、notify_url、return_url、enabled_channels、whitelist）从 `PaymentConfigService` 拉取，不在适配器内硬编码

### FR-002: 管理端支付配置页

新增管理端页面 `/admin/payment/config`（菜单「系统设置 → 支付配置」），单条记录承载整个 Z-Pay 配置（同一商户接入微信 + 支付宝时共享 pid/key）。

**Acceptance Criteria:**

- AC1: 表单字段：`api_url`（默认 `https://z-pay.cn/`）、`pid`（商户 ID）、`merchant_key`（密钥，写入时只回显掩码 `****` + 最后 4 位）、`notify_url`（异步回调地址，默认 `${APP_BASE_URL}/api/internal/v1/payments/zpay/notify`）、`return_url`（同步回调地址，默认前端支付完成页）、`enabled_channels`（多选 `wxpay` / `alipay`，至少 1 个）
- AC2: 提交时 Zod 校验：`api_url` 必须以 `https://` 开头且以 `/` 结尾；`pid` 长度 ≥ 8；新配置的 `merchant_key` 长度 ≥ 8，已有配置可传空字符串表示保留旧密钥；`enabled_channels` 非空
- AC3: 提交后调用 `PATCH /api/admin/v1/payment/config`，返回最新配置（密钥仍按掩码回显）
- AC4: 顶部「启用 / 停用支付」总开关，关闭后学员侧全部订单路径返回 `PAYMENT_DISABLED`
- AC5: 任何配置变更必须写 `audit_log`（`resource_type='payment_config'`, `action='update'`），记录变更前后差值（旧 pid / 新 pid 之类）
- AC6: 页面初始加载显示「尚未配置」空态时，引导管理员填写

### FR-003: Z-Pay 配置的 Zod 契约

`packages/contracts/src/paymentConfig.ts` 暴露读写契约，前后端共用。

**Acceptance Criteria:**

- AC1: `PaymentConfig` 输出 schema 包含 `enabled`（bool）、`api_url`（string）、`pid`（string）、`merchant_key_masked`（string，仅形如 `********xxxx`，不含明文）、`notify_url`（string）、`return_url`（string）、`enabled_channels`（array of `wxpay`/`alipay`）、`updated_at`（ISO string 或 null）、`whitelist_only`（bool）
- AC2: `PaymentConfigUpdateInput` schema 包含同名字段；`merchant_key` 接收明文；服务端落库前做 `sodium_crypto_box_seal` 风格加密或 AES-256-GCM（密钥从 env `PAYMENT_KEY_ENC_KEY` 读取，32 字节 base64）
- AC3: 服务端 `GET /api/admin/v1/payment/config` 永远不返回明文 `merchant_key`，永远返回 `merchant_key_masked`
- AC4: vitest 单测覆盖：mask 格式校验、明文回填拒绝

### FR-004: 测试白名单管理

新增管理端页面 `/admin/payment/whitelist`，管理 Z-Pay 测试白名单（仅允许这些学员手机号在生产环境创建支付订单）。

**Acceptance Criteria:**

- AC1: 列表显示：手机号（脱敏 `138****1234`）、启用状态、备注、创建时间、创建人；分页（每页 20 条）
- AC2: 新增条目：手机号校验中国大陆 11 位（`/^1\d{10}$/`），已存在则提示「该手机号已在白名单」
- AC3: 启用 / 停用切换：停用后该手机号立即不再具备测试支付资格，无需重新登录学员账号
- AC4: 删除：点击「移除」需 `ElMessageBox.confirm` 二次确认；删除走软删（`deleted_at` 字段），保留审计
- AC5: 列表顶部「当前是否启用白名单模式」总开关（与 `PaymentConfig.whitelist_only` 同步）
- AC6: 新增 / 启用 / 停用 / 删除全部写 `audit_log`

### FR-005: 学员侧白名单拦截

学员在管理端开启 `whitelist_only` 后，必须命中白名单才能发起任何支付订单。

**Acceptance Criteria:**

- AC1: `OrderService::createPending` 在拉起适配器前，先查 `payment_whitelist`（启用 + 未删除）中是否存在 `learner.phone == whitelist.phone` 匹配项
- AC2: 未命中 → 抛 `BusinessException(ApiResponse::FORBIDDEN, 'NOT_IN_WHITELIST')`
- AC3: 命中 → 继续走原有下单流程
- AC4: `whitelist_only=false` → 跳过该检查（与现状一致）
- AC5: 同一学员手机号在白名单内但停用 → 等同未命中，错误信息一致
- AC6: 行为变更通过 `PaymentConfigService` 缓存失效立即生效（不依赖学员重新登录）

### FR-006: 异步 / 同步回调路由

新增 `PaymentNotifyController::zpayNotify` 与 `PaymentNotifyController::zpayReturn`，对外暴露在 `route.php`。

**Acceptance Criteria:**

- AC1: `POST /api/internal/v1/payments/zpay/notify` 走异步回调（商户主动重试，必须幂等且返回 200）
- AC2: `GET /api/internal/v1/payments/zpay/return` 走同步回调（学员浏览器跳转），仅做签名校验 + 写审计 + 重定向到前端「支付完成」页
- AC3: 两个端点都不走学员鉴权中间件；白名单也不限制回调入口
- AC4: 异步回调中 `out_trade_no` 查不到订单 → 写 `audit_log('zpay_notify_unknown_order')` 后返回 200
- AC5: 同步回调在签名失败时 → 重定向到前端「支付失败」页 + 写审计
- AC6: 同步回调不会主动置订单为 `succeeded`（避免学员主动伪造 URL 跳完成），仅异步回调可改状态

### FR-007: DI 替换 FakePaymentAdapter

容器装配从 `FakePaymentAdapter` 切换到 `ZPayPaymentAdapter`，保留回滚路径。

**Acceptance Criteria:**

- AC1: `config/container.php`（或当前等价装配位置）默认绑定 `ZPayPaymentAdapter`；保留 `FakePaymentAdapter` 装配通过环境变量 `APP_ENV=testing` 或 `PAYMENT_DRIVER=fake` 启用
- AC2: 切换后 `OrderService` 一行不改（依赖 `PaymentAdapter` 接口）
- AC3: 适配器在 `createCharge()` 阶段从 `PaymentConfigService::getActiveConfig()` 读取配置；如果配置缺失或 `enabled=false` → 抛 `BusinessException(ApiResponse::CONFLICT, 'PAYMENT_DISABLED')`
- AC4: `phpunit` 既有 `PaymentContractTest` 继续通过（Fake 仍能作为测试驱动装配）

### FR-008: 数据持久化（迁移）

新增两张表（phinx 迁移）：

**`payment_config`**
- `id` (PK)
- `api_url` (varchar 255, NOT NULL)
- `pid` (varchar 64, NOT NULL)
- `merchant_key_cipher` (text, NOT NULL; AES-256-GCM 密文，格式 `v1:<base64_iv>:<base64_cipher>:<base64_tag>`)
- `notify_url` (varchar 255, NOT NULL)
- `return_url` (varchar 255, NOT NULL)
- `enabled_channels` (json, NOT NULL)
- `enabled` (tinyint 1, NOT NULL DEFAULT 0)
- `whitelist_only` (tinyint 1, NOT NULL DEFAULT 0)
- `created_at` / `updated_at` / `version` (int, optimistic lock)

约束：`id = 1` 唯一（全局只允许一条配置，应用层 + 唯一索引双重保护）

**`payment_whitelist`**
- `id` (PK)
- `phone` (varchar 11, NOT NULL)
- `enabled` (tinyint 1, NOT NULL DEFAULT 1)
- `note` (varchar 120, NULL)
- `created_by` (int, FK → staff_user.id)
- `created_at` / `updated_at` / `deleted_at` (soft delete)

索引：`(phone, deleted_at)` 唯一；`(enabled, deleted_at)` 普通索引

**Acceptance Criteria:**

- AC1: 迁移文件可前向、可回滚；提供 `down()` 删除两张表
- AC2: 迁移后既有数据零影响（不动 `orders` 表结构）
- AC3: 唯一约束阻止同一手机号重复入库（即使软删也按 phone 唯一，删除走真删可重新加）

### FR-009: 审计与可观测

所有支付配置 / 白名单的写操作 + 所有 z-pay 回调（成功、失败、未识别）都必须有审计与日志。

**Acceptance Criteria:**

- AC1: `audit_log` 增加条目，遵循既有 schema（resource_type / resource_id / action / before / after / actor）
- AC2: z-pay 回调入口用 `support\Logger` 记录：trade_no、out_trade_no、trade_status、money、type、签名校验结果
- AC3: 签名失败、金额不一致、`out_trade_no` 不存在等异常路径必须写 `audit_log`，便于事后追责
- AC4: 不在日志中输出明文 `merchant_key`（掩码即可）

### FR-010: 测试覆盖

**Acceptance Criteria:**

- AC1: 适配器 `ZPayPaymentAdapter` 单测覆盖：签名计算、签名校验、回调解析三种状态
- AC2: `PaymentConfigService` 单测覆盖：mask 生成、AES 加解密 round-trip、`whitelist_only` 命中与未命中
- AC3: `PaymentNotifyController::zpayNotify` 集成测试：成功 / 失败 / 重复 / 未知订单四种场景
- AC4: 前端 vitest：`PaymentConfigForm` 表单校验、提交、密钥字段不显示明文
- AC5: 既有 `PaymentContractTest` 不被破坏

---

## Success Criteria

1. **配置可达性**: 运营登录管理端，3 分钟内完成 Z-Pay 商户参数配置并保存成功，无需重启服务
2. **白名单生效**: 开启白名单后，未在白名单内的学员在 1 次请求内收到 `403 NOT_IN_WHITELIST`；白名单内学员下单无感知
3. **回调闭环**: 异步回调在 5 秒内完成签名校验 + 订单状态翻转 + 权益发放（`CourseEntitlement` 已写入）
4. **幂等性**: 同一 `trade_no` 的成功回调连续推送 100 次，订单 `status` 仍为 `succeeded` 且 `CourseEntitlement` 仅 1 条
5. **审计完整**: 任意一次配置变更 / 白名单变更 / 回调校验失败都可在 `audit_log` 中按 `resource_type` 检索到
6. **密钥不泄漏**: 全链路（API 响应、日志、审计）任何位置都不出现明文 `merchant_key`

---

## Key Entities

| Entity | Description | Responsibility |
|--------|-------------|----------------|
| PaymentConfig | 支付通道配置单例 | 存 z-pay 商户参数、启用状态、启用通道、白名单模式 |
| PaymentWhitelist | 测试白名单条目 | 按手机号维度开放测试支付资格 |
| ZPayPaymentAdapter | z-pay 真实实现 | 拼参数、签发、回执校验 |
| PaymentConfigService | 配置读写服务 | AES 加解密、mask 生成、缓存失效、白名单查询 |
| PaymentNotifyController.zpayNotify | 异步回调入口 | 验签 + 派发 OrderService 状态变更 |
| PaymentNotifyController.zpayReturn | 同步回调入口 | 验签 + 重定向前端 |
| PaymentConfigForm | 管理端配置表单 | Zod 校验 + 提交 |
| PaymentWhitelistView | 管理端白名单页 | 增删改查、状态切换 |

---

## Assumptions

- A1: 商户在 z-pay.cn 完成商户注册、获取 `pid` 与 `merchant_key`，并完成微信支付 / 支付宝的商户号绑定
- A2: 同步回调 `return_url` 落在学习端支付完成页（`apps/web`）的某个路由（如 `/orders/{id}?status=...`），管理端不需要关注
- A3: z-pay 的接口路径与 `docs/epay/*` 一致（API 文档 https://z-pay.cn/doc.html 与 docs/epay/ 同源）；未来若 z-pay 升级接口路径，配置 `api_url` 仍可让适配器指向其它兼容易支付协议的服务方
- A4: 同一商户接入微信 + 支付宝共享 `pid` 与 `merchant_key`（易支付协议约定），`enabled_channels` 仅控制学员侧能选哪个
- A5: 数据库敏感字段加密用 `aes-256-gcm`，密钥 `PAYMENT_KEY_ENC_KEY` 由部署环境注入（env 不入库、不入 git），密钥轮转通过双写 + 重加密脚本（不在本 spec 范围）
- A6: 测试白名单仅控制 Z-Pay 通道；当前唯一的 FakePaymentAdapter 在 `APP_ENV=testing` 下不受白名单限制（按既有测试约定）
- A7: 学员手机号是唯一登录标识，与 `Learner.phone` 直接对应；白名单按手机号精确匹配，不做模糊匹配

---

## Dependencies & Constraints

### Blocking Edges

- T001: 需先确认 `app/config` 容器装配文件位置（推测 `config/container.php` 或在 `bootstrap/app.php`），按现有约定绑定 `PaymentAdapter`
- T002: 需先确认 `audit_log` 既有 schema（`AuditLogService::writeAudit` 签名）以复用
- T003: `OrderService::createPending` 需要在拉起适配器前增加「白名单校验」分支；本 spec 限定改这一处

### Non-Blocking Edges

- N1: 学员侧支付通道选择（让学员选 wxpay 还是 alipay）由前端在下单请求 body 加 `channel` 字段；适配器按 `channel` 选 type
- N2: 当前没有「学员侧选择支付通道」的 UI，需要在 `apps/web` 加最小可用的选择控件（与本 spec 同步实现）
- N3: z-pay 的「订单退款」接口（`refund.php`）不在本 spec 范围；标记为后续 spec

---

## Out of Scope

- 学员侧退款流程（z-pay `refund.php`）
- 多商户 / 多账号分账
- 密钥轮转迁移工具
- 学员端主动查询订单（`query.php`）：如学员长时间未收到回调，可走运营后台手动查询；不在本 spec 实现 z-pay 主动轮询
- 把 z-pay 接口升级为签名证书 / RSA 模式（仅 MD5，与 docs/epay 一致）

---

## Open Questions

所有影响实现路径的关键决策已通过合理默认确定（见 Assumptions）。如管理员上线时需调整，可在实现阶段回头修订。

### Q1: 学员侧通道选择 — 默认微信 + 支付宝都暴露，由学员选

**Decision**: `apps/web` 下单时前端在 `POST /api/learner/v1/courses/{id}/orders` body 增加 `channel: 'wxpay'|'alipay'`（默认 `wxpay`），后端在 `enabled_channels` 不包含所选 channel 时返回 `409 PAYMENT_CHANNEL_DISABLED`

**Rationale**: 学习端会展示「微信扫码 / 支付宝扫码」两套入口；学员体验更直接

### Q2: 白名单模式总开关与「启用支付」总开关的优先级 — 「白名单模式」为强制门

**Decision**: `enabled=false` → 一律 `PAYMENT_DISABLED`；`enabled=true && whitelist_only=true` → 校验白名单；`enabled=true && whitelist_only=false` → 全部放行

**Rationale**: 避免「全局关闭」和「白名单模式」混淆；前者比后者优先级高

### Q3: 同步回调 `return_url` 命中 — 仅做签名校验 + 写审计，不改订单状态

**Decision**: 同步回调是学员浏览器跳转，服务端不信任其携带的 `trade_status`；状态变更只接受异步回调

**Rationale**: 防止学员伪造同步 URL 跳过支付；与 z-pay 官方建议一致

---

## Appendix

### Template References

- Design Pattern: Adapter Pattern（适配器层已存在 `PaymentAdapter`，新增 `ZPayPaymentAdapter` 实现）
- Security Pattern: AES-256-GCM 敏感字段加密（与项目现有 6.x 密码学选型一致）
- Audit Pattern: `AuditLogService::writeAudit`（既有，配置与白名单变更复用）

### Glossary

- **z-pay / 易支付**: 第三方支付聚合通道；本 spec 沿用 docs/epay/ 的 PHP SDK 协议
- **pid**: 商户 ID
- **merchant_key**: 商户密钥（MD5 签名密钥）
- **out_trade_no**: 商户侧订单号（与本系统 `orders.out_trade_no` 对应）
- **trade_no**: z-pay 侧流水号（写入 `orders.provider_ref`）
- **trade_status**: z-pay 回调订单状态枚举（`TRADE_SUCCESS` / `TRADE_CLOSED` / 其它）
- **whitelist_only**: 白名单模式开关；开启后仅白名单学员可发起测试订单

### Document References

- 官方文档: https://z-pay.cn/doc.html
- 本地 SDK: `docs/epay/{api,config,index,notify,query,refund,return}.php`
- 既有支付适配器: `apps/api/app/support/payment/{PaymentAdapter,FakePaymentAdapter,NotifyResult}.php`
- 既有分发入口: `apps/api/app/controller/internal/PaymentNotifyController.php`
- 既有订单服务: `apps/api/app/service/OrderService.php`
- 既有审计服务: `apps/api/app/service/` 下的 `*Service.php`，统一通过 `writeAudit()` 私有方法写
