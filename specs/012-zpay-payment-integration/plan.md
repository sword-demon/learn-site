# Implementation Plan: 012 Z-Pay 接入

**Branch**: `012-zpay-payment-integration` | **Date**: 2026-09-04 | **Spec**: [spec.md](./spec.md)

**Input**: Feature specification from `/specs/012-zpay-payment-integration/spec.md`

## Summary

把当前的 `FakePaymentAdapter` 替换为基于 z-pay.cn / 易支付协议的 `ZPayPaymentAdapter`，覆盖微信支付（`wxpay`）与支付宝（`alipay`）。新增管理端「支付配置」与「测试白名单」两个页面（复用 `site.manage` 权限），学员侧下单时增加 `channel` 字段与白名单拦截门。商户密钥以 AES-256-GCM 落库（key 来自 env `PAYMENT_KEY_ENC_KEY`），API 永远只回显掩码。异步 / 同步回调沿用既有 `NotifyResult` 模型与 `PaymentNotifyController` 入口，仅新增 `zpayNotify` / `zpayReturn` 路由；订单状态翻转只接受异步回调。

## Technical Context

**Language/Version**: PHP 8.2（Webman 框架，已固定）；前端 Vue 3 + TypeScript（已固定）
**Primary Dependencies**:
- 后端：webman、webman/think-orm、Phinx（迁移）、phpunit、phpstan
- 前端：Element Plus、Pinia、Zod、Axios、Vitest
- 新增：仅一个对称加密 helper（AES-256-GCM，PHP 标准库 `openssl_encrypt`），不引入新 composer 包

**Storage**: MySQL 8（既有用 think-orm + 单一 ORM 锁定，宪法 §IV）；新增 `payment_config`（单行 + CHECK 约束）与 `payment_whitelist`（软删 + phone 唯一索引）
**Testing**: phpunit（后端单测 + 集成测试）+ Vitest（前端组件测试）
**Target Platform**: Docker 容器（OrbStack 本地、Linux 生产），沿用既有 compose 编排
**Project Type**: 既有 monorepo，3 应用 + 1 共享 contracts 包；本 spec 仅触碰后端 / 管理端 / contracts
**Performance Goals**: 学员下单端到端 P95 ≤ 500ms（白名单命中 60s cache 后只多 1 次 DB 读）；异步回调 P95 ≤ 200ms（重业务走既有 `JobDispatcher` 派发）
**Constraints**:
- 商户密钥永不出现在 API 响应 / 日志 / 审计中
- 同步回调不改订单状态（防伪造 URL 跳成功）
- 异步回调必须幂等（依赖既有 `markSucceeded` 事务保护）
- 不引入新 composer 依赖
**Scale/Scope**: 复用既有学员量级（万级账号，几十门付费课程），单条 payment_config + N 条白名单

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

| 原则 | 评估 | 备注 |
|------|------|------|
| I. 容器即运行契约 | ✅ PASS | 不修改 compose / Dockerfile；新表随既有 `make migrate` 走 |
| II. 稳定兼容且可复现 | ✅ PASS | 不升级 Webman / PHP / 镜像；`composer.lock` 与 `pnpm-lock.yaml` 不变 |
| III. 契约优先与端到端类型安全 | ✅ PASS | 新增 `packages/contracts/src/paymentConfig.ts` + `paymentWhitelist.ts`；前后端共用 Zod schema；服务端校验（Zod 输入）+ DB 约束双层 |
| IV. 数据变更安全可追溯 | ✅ PASS | 新表走 Phinx 迁移（可前向 / 回滚）；CHECK 约束 `id=1` 强保护单例；唯一索引阻止 phone 重复；不引入新 ORM |
| V. 质量、安全与可运维性内建 | ✅ PASS | 密钥 AES-256-GCM + env 注入（不出现在代码 / 镜像 / git）；phpunit 后端 + vitest 前端 + phpstan；写 `audit_log` 覆盖所有配置 / 白名单变更 |
| VI. 令牌鉴权、无感续期与可踢下线 | ✅ PASS | 不动令牌；回调端点不走 `LearnerAuth` / `AdminAuth`（provider 走签名，与既有 Fake 回调同模式） |

**关键决策与宪法 §V「密钥不得进入代码、镜像、Compose 文件或版本控制」一致**：`merchant_key` 仅以密文形式存 DB，明文从 env `PAYMENT_KEY_ENC_KEY` 派生 AES key；API 响应只回显掩码。

**关键决策与宪法 §III「Zod schema + 服务端校验」一致**：Zod 契约放在 `packages/contracts/`，前后端共用；后端在 controller 层做 Zod 校验再走 service。

**关键决策与宪法 §IV「单一 ORM」一致**：所有 DB 访问走 think-orm，不引入 Eloquent；新增表继承 `App\support\think\Model`（如需要 model 包装）。

**Constitution 复检（Phase 1 后）**：

- I 不变
- II 不变
- III 通过：contracts 已落地
- IV 通过：迁移可前向 / 回滚
- V 通过：测试覆盖明确；密钥全链路掩码
- VI 不变

## Project Structure

### Documentation (this feature)

```text
specs/012-zpay-payment-integration/
├── plan.md              # 本文件
├── research.md          # Phase 0 输出
├── data-model.md        # Phase 1 输出
├── quickstart.md        # Phase 1 输出
├── contracts/
│   ├── payment-admin.md
│   └── payment-webhook.md
└── tasks.md             # Phase 2 输出（/speckit-tasks）
```

### Source Code (repository root)

```text
apps/api/
├── app/
│   ├── support/
│   │   ├── Crypto/
│   │   │   └── AesGcmCipher.php            # NEW: 薄包装（encrypt/decrypt）
│   │   └── payment/
│   │       ├── PaymentAdapter.php          # EDIT: 加 ?string $channel 参数；setSuccessHandler 上提接口
│   │       ├── FakePaymentAdapter.php      # EDIT: 实现 setSuccessHandler 接口方法
│   │       ├── ZPayPaymentAdapter.php      # NEW: 真实实现
│   │       └── NotifyResult.php            # EDIT（可选）: 加 ?string $channel
│   ├── service/
│   │   ├── PaymentConfigService.php        # NEW: 读写 + AES + cache + 私有 writeAudit
│   │   ├── PaymentWhitelistService.php     # NEW: 增删改查 + 私有 writeAudit
│   │   └── OrderService.php                # EDIT: createPending 接 ?string $channel + 白名单拦截
│   ├── controller/
│   │   ├── admin/
│   │   │   ├── PaymentConfigController.php # NEW: GET/PATCH
│   │   │   └── PaymentWhitelistController.php # NEW: CRUD
│   │   ├── internal/
│   │   │   └── PaymentNotifyController.php # EDIT: 加 zpayNotify + zpayReturn
│   │   └── learner/
│   │       └── OrderController.php         # EDIT: 解析 body.channel
│   └── route.php                           # EDIT: 注册 admin / 内部 / 学员新端点
├── config/
│   └── dependence.php                      # EDIT: 默认 PaymentAdapter → ZPayPaymentAdapter（保留 Fake 切换）
└── database/migrations/
    └── 20260904000001_payment_config_and_whitelist.php  # NEW

apps/admin/src/
├── views/
│   └── site/
│       ├── PaymentConfigView.vue           # NEW
│       └── PaymentWhitelistView.vue        # NEW
├── api/
│   └── payment.ts                          # NEW: 前后端对齐的 fetch helper
├── router/
│   └── index.ts                            # EDIT: 注册两个子路由
└── layouts/
    └── AdminMenu.ts                        # EDIT: 增加两个菜单项

packages/contracts/src/
├── paymentConfig.ts                        # NEW: Zod schema
├── paymentWhitelist.ts                     # NEW: Zod schema
└── index.ts                                # EDIT: export 新 schema

apps/api/tests/
├── ZPayPaymentAdapterTest.php              # NEW
├── PaymentConfigServiceTest.php            # NEW
├── PaymentWhitelistServiceTest.php         # NEW
├── PaymentNotifyControllerTest.php         # NEW
└── OrderServiceWhitelistTest.php           # NEW

apps/admin/tests/views/payment/
├── PaymentConfigForm.spec.ts               # NEW
└── PaymentWhitelistView.spec.ts            # NEW
```

**Structure Decision**: 沿用既有 monorepo 布局；本 spec 触碰 5 个应用 / 包（apps/api, apps/admin, packages/contracts, apps/api/tests, apps/admin/tests），不新增顶层目录。

## Complexity Tracking

> **Fill ONLY if Constitution Check has violations that must be justified**

无违反。Complexity 不新增：

- 不新增抽象（PaymentAdapter 既有；ZPayPaymentAdapter 只是同一接口的第二个实现）
- 不新增 composer 依赖（AES 用 PHP 标准库；Zod 既有）
- 不引入队列 / 缓存中间件（白名单 60s in-memory cache，与既有 `HomeCache` 同款；回调派发走既有 `JobDispatcher`）
- 单表与单配置项不需要 Repository / Factory 包装
- 测试只在既有用例目录新增文件，不重构既有
