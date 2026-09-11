# Contracts: 课程分销方案

**Feature**: 016-course-distribution

## 文件

- [`distribution.ts`](./distribution.ts) — 前后端共享 Zod Schema.

## 落地说明

`distribution.ts` 作为新模块加进 `packages/contracts/src/`, 在 `packages/contracts/src/index.ts` 增补 `export * from "./distribution";` 一行. 严格沿用既有 `*.ts` 风格: 顶层 `import { z } from "zod"`, 每个 DTO 用 `z.object(...)` + `z.infer<...>`, 金额一律 `number().int()` (单位: 分), 时间一律 `string().min(1)` (ISO-8601, `Asia/Shanghai`), phone 一律正则 `^1[3-9]\d\*{4}\d{4}$`.

## API 路由与权限点对照

> 仅列新增/扩展, 不重复既有路由.

### 管理端 (`/api/admin/distribution/...`)

| 路由 | 方法 | 权限点 | 输入 | 输出 |
|---|---|---|---|---|
| `/config` | GET | `distribution.config` | — | `DistributionConfigDTO` |
| `/config` | PUT | `distribution.config` | `DistributionConfigUpdateInput` | `DistributionConfigDTO` |
| `/course-overrides` | GET | `distribution.config` | query: `page`, `limit` | paginated `DistributionCourseOverrideDTO` |
| `/course-overrides/:courseId` | PUT | `distribution.config` | `DistributionCourseOverrideUpsertInput` | `DistributionCourseOverrideDTO` |
| `/reconcile/by-order/:orderId` | GET | `distribution.reconcile` | — | `AdminCommissionByOrderDTO` |
| `/commissions` | GET | `distribution.reconcile` | query: `page`, `limit`, `learner_id?`, `course_id?`, `status?` | paginated `CommissionRecordDTO` |
| `/commissions/:id/void` | POST | `distribution.reconcile` | `CommissionVoidInput` | `CommissionRecordDTO` |
| `/audit` | GET | `distribution.audit` | query: `page`, `limit`, `action?`, `from?`, `to?` | `DistributionAuditListDTO` |
| `/commissions/export` | GET | `distribution.reconcile` | query: 与 `/commissions` 相同筛选 | csv (手机号脱敏, SC-009) |

### 学习端 (`/api/learner/distribution/...`)

| 路由 | 方法 | 权限 | 输入 | 输出 |
|---|---|---|---|---|
| `/share-entries` | GET | 学员登录 | — | `ShareEntryListDTO` |
| `/share-entries` | POST | 学员登录 | `ShareEntryCreateInput` | `ShareEntryCreateOutput` (明文仅本次返回) |
| `/share-entries/:id` | DELETE | 学员登录 | — | `{ ok: true }` |
| `/commissions` | GET | 学员登录 | query: `page`, `limit`, `status?` | `CommissionListDTO` |
| `/downline` | GET | 学员登录 | query: `level?` | `DownlineListDTO` |

### 落地页 (公开, 不可见推荐人)

| 路由 | 方法 | 说明 |
|---|---|---|
| `/s/:shortCode` | GET | 访客 / 学员都可访问. 解析 short_code, 写 share_visits, 种 cookie, 302 到课程详情页 (`scope=course`) 或首页 (`scope=site`). 不返回任何推荐人信息. |
| `/s/:shortCode/info` | GET | 同上但用于客户端校验短码有效. |

### 与注册流程的钩子

`POST /api/learner/auth/register` 在事务内增加:
- 读 cookie `distribution_visitor_token`. 无 token 或找不到 visit 则不绑定, **禁止凭空生成 token**.
- 按 visitor_token 取最后一次有效访问, 得到 share_entry_id.
- 若该 visitor_token 或该 share_entry 已有 bound_learner_id, 新注册 referrer 保持 NULL.
- 若 share_entry.learner_id ≠ 当前注册学员且新学员 referrer 仍为 NULL, 设置 learners.referrer_learner_id = share_entry.learner_id (应用层 + DB 触发器双层校验).
- 把本次 visit 的 bound_learner_id 写为新学员 id, bound_at = NOW.
- 注册事务 commit 之后, 不再回填, 也无法回滚推荐关系.

### 与订单生命周期的钩子

`OrderService::markSucceeded` 回调链尾增加 (与颁发课程访问权同一事务内):
- `CommissionService::settleForOrder($orderId)` — 读 orders 行锁, 沿 referee 链路取 ≤ 3 个推荐人, 算金额, 写 commission_records (status = pending 或 pending_blocked), 按 config_snapshot 写入. 此步不写 settled.

订单退款窗口结束且未退款 (既有订单状态迁移, 禁止新 cron):
- `CommissionService::markSettledForOrder($orderId)` — pending → settled; pending_blocked 与 voided 不升为 settled.

`OrderService::markRefunded` 回调链尾增加:
- `CommissionService::voidForOrder($orderId, reason='order_refund')` — 把该 order 的所有 commission_records 状态置 voided, source = system_refund_void, 写审计 (actor_type = system).

## 权限点 (写入 PermissionSeeder)

```
['code' => 'distribution.config', 'module' => 'distribution', 'description' => 'Manage distribution config & course overrides']
['code' => 'distribution.reconcile', 'module' => 'distribution', 'description' => 'Reconcile commissions and void']
['code' => 'distribution.audit', 'module' => 'distribution', 'description' => 'Read distribution audit log']
```

`distribution.share` 与 `distribution.view_own` 走学员 JWT 身份, 不进 PermissionSeeder.

## 不导出

- 明文手机号 / 短码明文 / 推荐人昵称 / 完整链路 / 配置内部百分比: 任何 DTO 都不带这些.
- 学员端 `share_url`: 仅在生成当次响应中返回 plaintext_code + share_url, 之后所有 GET 仅返回 `masked_code` (前 4 后 4 中间 \*).
