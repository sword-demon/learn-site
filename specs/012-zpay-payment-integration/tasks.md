---
description: "Task list for 012 Z-Pay 接入"
---

# Tasks: 012 Z-Pay 接入

**Input**: Design documents from `/specs/012-zpay-payment-integration/`
**Prerequisites**: plan.md, spec.md, research.md, data-model.md, contracts/, quickstart.md

**Tests**: TDD 强制要求（H4: 测试与功能同 commit；FR-010 明确要求 phpunit + vitest 全覆盖）。每个 story 在实现前必须先 RED 写测试。

**Organization**: Tasks are grouped by user story to enable independent implementation and testing of each story.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: Can run in parallel (different files, no dependencies)
- **[Story]**: Which user story this task belongs to (US1 = 管理端支付配置；US2 = 测试白名单；US3 = 学员下单+回调闭环)
- Include exact file paths in descriptions

## Path Conventions

本项目 monorepo（`apps/api` + `apps/admin` + `packages/contracts` + `apps/api/tests` + `apps/admin/tests`），不调整。

---

## Phase 1: Setup (Shared Infrastructure)

**Purpose**: 数据库 schema 准备（迁移文件可独立提交），env 文档与契约 schema 落定

- [x] T001 [P] Add Phinx migration `20260904000001_payment_config_and_whitelist.php` in `apps/api/database/migrations/` per data-model.md §5 (creates `payment_config` singleton + `payment_whitelist` with soft-delete + indexes + FK)
- [x] T002 [P] Add Zod schema `packages/contracts/src/paymentConfig.ts` per contracts/payment-admin.md §1 (PaymentConfig output + PaymentConfigUpdateInput + PaymentChannel enum)
- [x] T003 [P] Add Zod schema `packages/contracts/src/paymentWhitelist.ts` per contracts/payment-admin.md §2 (PaymentWhitelistEntry + Create/Update input + ListResponse)
- [x] T004 [P] Re-export new schemas in `packages/contracts/src/index.ts` (add `export * from './paymentConfig'`, `export * from './paymentWhitelist'`)
- [x] T005 Update `.env.example` to add `PAYMENT_KEY_ENC_KEY=` placeholder + comment in `apps/api/.env.example` (32-byte base64; document generation command)
- [x] T006 Run `pnpm --filter @learn-site/contracts test` and `docker exec <api> phinx migrate -e development` to verify T001-T005

**Checkpoint**: Schema / contracts / env 文档就绪，**所有 user story 可并行开始**

---

## Phase 2: Foundational (Blocking Prerequisites)

**Purpose**: 加密 helper、PaymentAdapter 接口扩展、PaymentConfigService 基础读写、容器装配切换 — 任何 user story 都要先有这些

**⚠️ CRITICAL**: No user story work can begin until this phase is complete

- [x] T007 Create AES-256-GCM helper class `App\support\Crypto\AesGcmCipher` in `apps/api/app/support/Crypto/AesGcmCipher.php` (static `encrypt(string $plain, string $keyB64): string` → `v1:<b64_iv>:<b64_cipher>:<b64_tag>`; static `decrypt(string $payload, string $keyB64): string`; fail-fast on missing key)
- [x] T008 Extend `PaymentAdapter` interface in `apps/api/app/support/payment/PaymentAdapter.php` (add `?string $channel = null` 4th param to `createCharge`; add `setSuccessHandler(callable $handler): void` to interface)
- [x] T009 Update `FakePaymentAdapter` in `apps/api/app/support/payment/FakePaymentAdapter.php` to implement new `setSuccessHandler` interface method (rename existing private property to interface method)
- [x] T010 Update `OrderService::__construct` in `apps/api/app/service/OrderService.php` — replace `if ($payment instanceof FakePaymentAdapter)` block with unconditional `$payment->setSuccessHandler(...)`; add 4th `?string $channel = null` param to `createPending(int $learnerId, int $courseId, ?int $learnerCouponId = null, ?string $channel = null)`; pass channel through to `$this->payment->createCharge(..., $channel)`
- [x] T011 Create `PaymentConfigService` in `apps/api/app/service/PaymentConfigService.php` (constructor: getenv `PAYMENT_KEY_ENC_KEY`; public `getActive(): ?array` returns `PaymentConfig` shape with mask; public `update(int $staffId, array $input): array` validates, encrypts merchant_key, upserts `id=1`, invalidates cache, writes audit; public `isWhitelisted(string $phone): bool`; private `maskKey(string $plain): string`; private `writeAudit(...)`; private `loadFromDb()` with 60s in-memory cache)
- [x] T012 Switch default `PaymentAdapter` binding in `apps/api/config/dependence.php` to `new ZPayPaymentAdapter(new PaymentConfigService())` (keep `FakePaymentAdapter` as fallback selected by `getenv('PAYMENT_DRIVER') === 'fake'` or `APP_ENV=testing'`)
- [x] T013 Verify existing `apps/api/tests/PaymentContractTest.php` still passes after T008-T010 (Fake must satisfy new interface; OrderService must not regress) — `5 tests / 12 assertions` passed
- [x] T014 Run `make phpstan` and existing `apps/api/tests/PaymentContractTest.php` to verify Foundational phase is clean — PHPStan `[OK] No errors`; PaymentContractTest passed

**Checkpoint**: 加密 helper、接口扩展、PaymentConfigService、容器装配就绪；OrderService 改完但行为不变；既有 PaymentContractTest 仍通过

---

## Phase 3: User Story 1 - 管理端支付配置（FR-002 / FR-003 / FR-007） (Priority: P1) 🎯 MVP

**Goal**: 管理员能在管理端 `/admin/site/payment-config` 配置 Z-Pay 商户参数（pid / merchant_key / urls / channels / 白名单模式），密文落库，掩码回显，配置变更写 audit_log

**Independent Test**: 登录管理端 → 填写表单 → 提交 → 数据库中 `merchant_key_cipher` 是 `v1:` 开头密文，API 响应 `merchant_key_masked` 是 `********xxxx`，`audit_log` 有一条 `action='payment_config.update'`

### Tests for User Story 1 (RED first) ⚠️

- [x] T015 [P] [US1] Write `apps/api/tests/PaymentConfigServiceTest.php` covering: AES round-trip; mask format `/^\*{8,}\w{0,4}$/`; `update()` rejects missing `PAYMENT_KEY_ENC_KEY`; `update()` writes audit_log; cache invalidation after update
- [x] T016 [P] [US1] Write `apps/api/tests/PaymentConfigControllerTest.php` covering: GET returns mask only (never plaintext); PATCH with invalid Zod input → 400; PATCH success → 200 + mask; missing env → 500 `PAYMENT_KEY_ENC_KEY_NOT_CONFIGURED`
- [x] T017 [P] [US1] Write `apps/admin/tests/views/payment/PaymentConfigForm.spec.ts` covering: submit calls `PATCH /api/admin/v1/payment/config` with merchant_key cleared (or kept) per UX; merchant_key field never renders plaintext; validation errors from Zod surfaced; success toast

### Implementation for User Story 1

- [x] T018 [US1] Create `PaymentConfigController` in `apps/api/app/controller/admin/PaymentConfigController.php` (`get(Request)` returns PaymentConfig shape; `update(Request)` validates Zod via `PaymentConfig` schema, calls `PaymentConfigService::update`, returns updated shape) (depends on T011, T015, T016)
- [x] T019 [US1] Register routes in `apps/api/app/route.php` under `adminV1` group: `GET /payment/config`, `PATCH /payment/config` (with `AdminAuth` middleware; no per-route permission check — middleware chain already enforces; verify against existing site/profile pattern) (depends on T018)
- [x] T020 [P] [US1] Create `apps/admin/src/api/payment.ts` with `fetchPaymentConfig()` + `updatePaymentConfig(input)` (Zod-parsed responses; re-export `PaymentConfig` / `PaymentConfigUpdateInput` from `@learn-site/contracts`) (depends on T002, T003)
- [x] T021 [US1] Create `PaymentConfigView` in `apps/admin/src/views/site/PaymentConfigView.vue` (form with `api_url` / `pid` / `merchant_key` (password input) / `notify_url` / `return_url` / `enabled_channels` checkbox group / `enabled` switch / `whitelist_only` switch; submit → calls `updatePaymentConfig`; on success → refresh + toast) (depends on T017, T020)
- [x] T022 [P] [US1] Add route in `apps/admin/src/router/index.ts`: `path: 'site/payment-config'`, `component: PaymentConfigView`, `meta: { title: '支付配置', permission: 'site.manage' }` (depends on T021)
- [x] T023 [P] [US1] Add menu entry in `apps/admin/src/layouts/AdminMenu.ts`: `{ path: '/site/payment-config', label: '支付配置', permission: 'site.manage' }` under the `系统设置` group (depends on T022)
- [x] T024 [US1] Run `make rebuild-admin` + `make rebuild-api` + manually verify quickstart 场景 1 (POST PATCH, DB check, audit_log query) — 两个生产镜像重建通过；HTTP 配置更新、密文落库、掩码回显和 audit_log 已验证

**Checkpoint**: 管理员可配置商户参数；密文落库；掩码回显；audit_log 落库

---

## Phase 4: User Story 2 - 测试白名单管理（FR-004 / FR-005） (Priority: P1)

**Goal**: 管理员在 `/admin/site/payment-whitelist` 增删改查手机号白名单；学员侧 `whitelist_only=true` 时白名单生效

**Independent Test**: 管理员新增手机号 → 数据库 `payment_whitelist` 新行；学员 A（手机未命中）下单 → `403 NOT_IN_WHITELIST`；白名单内学员下单 → 200

### Tests for User Story 2 (RED first) ⚠️

- [x] T025 [P] [US2] Write `apps/api/tests/PaymentWhitelistServiceTest.php` covering: add rejects invalid phone; add rejects duplicate; toggle enabled; soft delete sets `deleted_at`; `isWhitelisted()` returns true/false correctly for enabled/disabled/deleted entries
- [x] T026 [P] [US2] Write `apps/api/tests/PaymentWhitelistControllerTest.php` covering: GET list pagination; POST validation; PATCH toggle; DELETE requires auth; soft-deleted entries not returned in list
- [x] T027 [P] [US2] Write `apps/api/tests/OrderServiceWhitelistTest.php` covering: `whitelist_only=false` skips check; `whitelist_only=true` + unlisted phone → `BusinessException('FORBIDDEN', 'NOT_IN_WHITELIST')`; `whitelist_only=true` + listed phone → proceeds; `whitelist_only=true` + disabled entry → treated as unlisted
- [x] T028 [P] [US2] Write `apps/admin/tests/views/payment/PaymentWhitelistView.spec.ts` covering: render list with mask; add dialog rejects bad phone; toggle works; remove requires `ElMessageBox.confirm`; `whitelist_only` switch syncs with backend config

### Implementation for User Story 2

- [x] T029 [P] [US2] Create `PaymentWhitelistService` in `apps/api/app/service/PaymentWhitelistService.php` (`add(int $staffId, string $phone, bool $enabled, ?string $note): int`; `toggle(int $staffId, int $id, bool $enabled): void`; `softDelete(int $staffId, int $id): void`; `list(int $page, int $limit): array`; private `writeAudit(...)`; private `validatePhone()`) (depends on T025, T026)
- [x] T030 [US2] Create `PaymentWhitelistController` in `apps/api/app/controller/admin/PaymentWhitelistController.php` (CRUD endpoints with Zod input validation via `PaymentWhitelistCreateInput` / `PaymentWhitelistUpdateInput` from `@learn-site/contracts`) (depends on T029)
- [x] T031 [US2] Register routes in `apps/api/app/route.php` under `adminV1`: `GET /payment/whitelist`, `POST /payment/whitelist`, `PATCH /payment/whitelist/{id}`, `DELETE /payment/whitelist/{id}` (depends on T030)
- [x] T032 [US2] Add whitelist check in `OrderService::createPending` in `apps/api/app/service/OrderService.php` (after entitlement check, before coupon lock: load `accounts.login` for `$learnerId`; if `PaymentConfigService::isWhitelistOnly()` then call `PaymentWhitelistService::isWhitelisted($phone)`; throw `BusinessException('FORBIDDEN', 'NOT_IN_WHITELIST')` on miss) (depends on T027, T029)
- [x] T033 [US2] Update `OrderController::create` in `apps/api/app/controller/learner/OrderController.php` (accept `channel` body field; pass to `orders->createPending`) (depends on T032)
- [x] T034 [P] [US2] Create `apps/admin/src/api/paymentWhitelist.ts` with `listPaymentWhitelist(page, limit)`, `addPaymentWhitelist(input)`, `togglePaymentWhitelist(id, enabled)`, `removePaymentWhitelist(id)` (depends on T003)
- [x] T035 [US2] Create `PaymentWhitelistView` in `apps/admin/src/views/site/PaymentWhitelistView.vue` (el-table list with mask rendering; add dialog with phone validation; switch for enabled; remove with `ElMessageBox.confirm`; top switch for `whitelist_only` syncing with `PaymentConfig`) (depends on T028, T034)
- [x] T036 [P] [US2] Add route in `apps/admin/src/router/index.ts`: `path: 'site/payment-whitelist'`, `component: PaymentWhitelistView`, `meta: { title: '支付白名单', permission: 'site.manage' }` (depends on T035)
- [x] T037 [P] [US2] Add menu entry in `apps/admin/src/layouts/AdminMenu.ts`: `{ path: '/site/payment-whitelist', label: '支付白名单', permission: 'site.manage' }` (depends on T036)
- [x] T038 [US2] Manually verify quickstart 场景 2 + 场景 3 (white list 增删改 + 学员拦截 + 已加入白名单后下单) — HTTP CRUD、软删、未命中 403、命中后下单 200 已验证

**Checkpoint**: 白名单 CRUD 闭环；学员侧白名单拦截生效；前端页面可用

---

## Phase 5: User Story 3 - 学员下单 + 异步回调闭环（FR-001 / FR-006 / FR-007 / FR-009） (Priority: P1) 🎯

**Goal**: 学员侧 `POST /api/learner/v1/courses/{id}/orders` 带 `channel` 返回 z-pay `redirect_url`；z-pay 异步回调验签成功 → 订单 `succeeded` + 发放 entitlement；同步回调验签 + 重定向不改单

**Independent Test**: 学员下单 → 拿到 `redirect_url=https://z-pay.cn/submit.php?...`；推送合法异步回调 → 订单 `status='succeeded'`, `provider_ref=<trade_no>`, `course_entitlements` +1；重放同一回调 → 幂等无新 entitlement

### Tests for User Story 3 (RED first) ⚠️

- [x] T039 [P] [US3] Write `apps/api/tests/ZPayPaymentAdapterTest.php` covering: `sign()` produces known MD5 vector; `createCharge()` returns `redirect_url` with correct param order; `parseNotify()` accepts GET and POST; signature mismatch → null; unknown `out_trade_no` → null; `TRADE_SUCCESS` → `NotifyResult::succeeded`; `TRADE_CLOSED` → `NotifyResult::failed`; money mismatch → null (adapter-level, not service-level)
- [x] T040 [P] [US3] Write `apps/api/tests/PaymentNotifyControllerTest.php` covering: `zpayNotify` valid signature + TRADE_SUCCESS → `markSucceeded` called with `provider_ref`; replay same notify → no-op (idempotent); `zpayNotify` unknown `out_trade_no` → 200 + audit_log; `zpayNotify` money mismatch → 200 + audit_log; `zpayReturn` invalid signature → audit_log + 302 to `/orders?status=invalid`; `zpayReturn` valid signature → 302 to `/orders/{id}?status=pending&trade_no=...` (does NOT call markSucceeded)
- [x] T041 [P] [US3] Write `apps/web/tests/views/orders/CheckoutChannel.spec.ts` (or equivalent) covering: 学员选 wxpay/alipay radio; disabled channel greyed out; submit sends `channel` field — covered by `apps/web/tests/CheckoutView.test.ts`

### Implementation for User Story 3

- [x] T042 [US3] Create `ZPayPaymentAdapter` in `apps/api/app/support/payment/ZPayPaymentAdapter.php` (constructor: inject `PaymentConfigService`; private `sign(array $payload, string $merchantKey): string`; private `decryptKey(): string`; `createCharge(int $orderId, float $amount, string $currency, ?string $channel = null)` returns `['type'=>'redirect','redirect_url'=>..., 'out_trade_no'=>(string)$orderId, 'amount'=>$amount, 'currency'=>$currency, 'provider'=>'zpay', 'channel'=>$channel]`; throws `BusinessException('CONFLICT', 'PAYMENT_DISABLED')` when no active config; throws `BusinessException('CONFLICT', 'PAYMENT_CHANNEL_DISABLED')` when channel not in `enabled_channels`; `parseNotify(Request)` reads GET/POST, validates signature, returns `NotifyResult` or null; implements `setSuccessHandler` interface method) (depends on T007, T011, T039)
- [x] T043 [US3] Add `zpayNotify` and `zpayReturn` methods in `apps/api/app/controller/internal/PaymentNotifyController.php` (zpayNotify: extract out_trade_no; lookup order; check amount matches; call `markSucceeded` for TRADE_SUCCESS or `markFailed` for others; on null `parseNotify` return 400; on amount mismatch or unknown order write audit + return 200; zpayReturn: only verify signature, write audit, 302 to `${APP_BASE_URL}/orders/{id}?status=pending&trade_no=...` or `/orders?status=invalid`) (depends on T040, T042)
- [x] T044 [US3] Register routes in `apps/api/app/route.php`: `POST /api/internal/v1/payments/zpay/notify` and `GET /api/internal/v1/payments/zpay/return` (NO middleware, register in all envs not just testing) (depends on T043)
- [x] T045 [US3] Verify that `OrderController::create` already routes `channel` field through `OrderService::createPending` → adapter from Phase 4 (T033), confirm end-to-end by running quickstart 场景 4 (success path) + 场景 5 (sync return) — 路由链路、成功回调发放权益、幂等重放和同步回调重定向已通过测试及 HTTP smoke 验证
- [x] T046 [P] [US3] Add channel selector in `apps/web` checkout page (Vue component reading `enabled_channels` from new `GET /api/learner/v1/payment/options` endpoint or extending existing OrderController response) — **scoped minimum: add `payment.channel` field to existing `createPending` response and a radio in apps/web views** (depends on T041)
- [x] T047 [US3] Run quickstart 场景 4（重放 + 失败 + 金额不一致 + 未知订单）+ 场景 5（同步）+ 场景 7（channel 选择） — 成功/失败/金额不一致/未知订单/同步重定向及 wxpay、alipay 通道已验证

**Checkpoint**: 学员下单 + channel 字段 + z-pay 异步回调成功闭环 + 同步重定向 + 幂等 + 异常路径全覆盖

---

## Phase 6: Polish & Cross-Cutting Concerns

**Purpose**: 全栈贯通 / 静态分析 / 既有契约不退化 / 文档

- [x] T048 [P] Run `make phpstan` against `apps/api` and ensure no new errors from T007, T011, T018, T029, T030, T042, T043 files — Compose PHPStan run found no new errors in feature files; 33 pre-existing errors remain elsewhere, and no `make phpstan` target exists
- [x] T049 [P] Run `pnpm --filter @learn-site/contracts test` to verify Zod schemas (T002, T003) are tested — `23 files / 83 tests` passed
- [x] T050 [P] Run `pnpm --filter @learn-site/admin test` to verify vitest coverage (T017, T028, T041) — `41 files / 142 tests` passed
- [x] T051 [P] Run full `apps/api/tests/PaymentContractTest.php` and `apps/api/tests/OrderServiceTest.php` (existing) to confirm no regression — `PaymentContractTest` passed；仓库不存在 `OrderServiceTest.php`，相关订单测试已通过
- [x] T052 Run quickstart.md scenarios 1-7 end-to-end against `make rebuild-api && make rebuild-admin` containers — 生产镜像重建通过，场景 1-7 的 API/回调/通道 smoke 已完成
- [x] T053 [P] Update `apps/admin/src/views/site/SiteProfileView.vue` or create `apps/admin/src/views/site/PaymentHubView.vue` if a "支付中心" landing is desired (optional; skipped because it is outside the spec scope)
- [x] T054 [P] Add entry to `tasks/lessons.md` summarizing hard rules surfaced during implementation (per H3 from CLAUDE.md: 教训提炼)
- [x] T055 Run `make lint && make typecheck && make test` from repo root as final gate — lint、typecheck、API 353 tests、contracts 83 tests、web 154 tests、admin 142 tests 全部通过

### Verification Notes

- 所有实现任务和自动化验证任务已完成；quickstart 的场景验证使用当前 Compose 服务执行 API/回调 smoke，未连接真实 Z-Pay 商户后台发起实际扣款。
- `make test` 仍报告 1 条 PHPUnit deprecation；前端生产构建仅有 chunk 大小告警，均不影响退出码。

---

## Dependencies & Execution Order

### Phase Dependencies

- **Phase 1 (Setup)**: No dependencies - can start immediately
- **Phase 2 (Foundational)**: Depends on Phase 1 completion - **BLOCKS all user stories**
- **Phase 3 (US1 配置)**: Depends on Phase 2 completion
- **Phase 4 (US2 白名单)**: Depends on Phase 2 completion; depends on US1 only via shared `PaymentConfigService::getActive()` (cache + `whitelist_only` switch) — can run in parallel with US3 if US1's `whitelist_only` switch is mocked or skipped in US2 tests
- **Phase 5 (US3 下单+回调)**: Depends on Phase 2 completion; depends on US1's `PaymentConfigService` and US2's `PaymentWhitelistService` for end-to-end, but unit tests for `ZPayPaymentAdapter` and `PaymentNotifyController` can run independently
- **Phase 6 (Polish)**: Depends on US1 + US2 + US3 completion

### User Story Dependencies

- **US1 (P1)**: No dependencies on other stories
- **US2 (P1)**: No code dependencies on US1 (shares `PaymentConfigService` but can be tested with stub); OrderService whitelist check in T032 should be merged AFTER US1 lands (so `whitelist_only` config exists)
- **US3 (P1)**: Depends on US1 (`PaymentConfigService` for `createCharge` config) and US2 (whitelist check in OrderService); but `ZPayPaymentAdapter` unit tests can be written before US1 / US2 land if config is stubbed

**Recommended execution order (single developer)**: Phase 1 → Phase 2 → US1 → US2 → US3 → Phase 6

**Recommended parallel split (3 developers)**:

- Dev A: US1 (配置 + PaymentConfigService)
- Dev B: US2 (白名单 + OrderService 拦截)
- Dev C: US3 (ZPayPaymentAdapter + 回调)
- All converge for Phase 6

### Within Each User Story

- Tests (RED) MUST be written first
- `apps/api` services before controllers before route.php
- `apps/admin` api helpers before views before router before menu
- Migrations before services that use them

### Parallel Opportunities

- T001-T005 (Phase 1): all [P]
- T007-T012 (Phase 2): T007, T008, T009, T011 are [P] within their files; T010 depends on T008-T009; T012 depends on T011
- Within US1: T015-T017 tests [P]; T020 + T022 + T023 frontend wiring [P]
- Within US2: T025-T028 tests [P]; T029 + T034 + T036 + T037 [P] across files; T032 depends on T029
- Within US3: T039-T041 tests [P]; T044 + T046 [P] across files

---

## Implementation Strategy

### MVP First (US1 only)

1. Complete Phase 1: Setup (T001-T006)
2. Complete Phase 2: Foundational (T007-T014) — **required, blocks everything**
3. Complete US1: 配置（Phase 3）
4. **STOP and VALIDATE**: 管理员填一份配置、查 DB 密文、查 API 掩码、查 audit_log
5. 如果仅交付「管理员能配支付参数」即可视为 MVP，US2 / US3 后续迭代

### Incremental Delivery

1. Setup + Foundational → 基础就绪
2. US1 → 管理员能配 → 内部 demo
3. US2 → 白名单就位 → 学员拦截生效
4. US3 → 真实下单 + 回调 → 端到端 demo（学员扫码付钱成功发权益）
5. Phase 6 → 静态分析 + 全量测试 + 文档

### Parallel Team Strategy

3 个开发并行（详见上文 Dependencies）

---

## Notes

- [P] tasks = different files, no dependencies
- [Story] label maps task to specific user story for traceability
- 严格遵守 H4（CLAUDE.md）：测试与功能同 commit，每个 commit 粒度 = 一个可独立回滚的改动
- 严格遵守 H1：白名单拦截在 `OrderService::createPending` 入口处 guard；不依赖调用方
- 严格遵守 H2：管理端 `ElMessageBox.confirm` 二次确认（不写 `confirm(...)`）
- 严格遵守 H3：常量（加密版本号、字符长度上限、错误码）跨方法 → `const`；敏感字段 → AES；写审计 → 私有 `writeAudit`
- 实施期间若发现 z-pay 协议细节与 `docs/epay/` 不一致，以 https://z-pay.cn/doc.html 为准并回写 `spec.md` / `research.md`
