# Implementation Plan: 课程分销方案

**Branch**: `016-course-distribution` | **Date**: 2026-09-06 | **Spec**: [spec.md](./spec.md)

**Input**: Feature specification from `/specs/016-course-distribution/spec.md`

## Summary

为站点新增课程分销能力: 学员生成分享入口 → 访客用未注册手机号注册绑定推荐人 → 下单结算时按链路最多向 3 名推荐人发放佣金 (一级 / 二级 / 三级). 配置、覆盖、对账、审计、撤销全在管理端闭环, 学习端仅看到分享入口与脱敏的佣金 / 下级信息. 严格守合规硬约束 (级别上限 = 3, 任何管理员不可解除). 与既有 OrderService 生命周期钩子集成, 不引入新的消息总线.

## Technical Context

- **Language/Version**: PHP 8.4 (apps/api, think-orm + webman), Vue 3 + TypeScript (apps/web 与 apps/admin)
- **Primary Dependencies**:
  - apps/api: think-orm, webman-framework 2.2, Phinx (migration), 既有 OrderService / EntitlementService / NotificationDispatchService
  - packages/contracts: zod
  - apps/web / apps/admin: Element Plus, Pinia, vue-router
- **Storage**: MySQL 8.4 (新增 5 张表 + 1 列扩展 + 1 行 site_settings key); Redis 仅用于短期 cookie / 临时 share_visits 缓存, 不作为主存储
- **Testing**: PHPUnit (apps/api/tests), Vitest (apps/web/tests, apps/admin/tests), Playwright (make test-e2e)
- **Target Platform**: Webman 服务端 + 两端 SPA (无移动端原生, 不涉及)
- **Project Type**: 既有 monorepo (apps/api + apps/web + apps/admin + packages/contracts), 沿用
- **Performance Goals**: 单笔订单结算钩子在订单 succeeded 事务内同步完成, P95 ≤ 200 ms; 学习端「我的分销」分页 ≤ 500 ms; 对账页按订单查询 ≤ 300 ms
- **Constraints**:
  - 沿用 webman 单进程同步回调, 不引入新的 MQ
  - 金额一律以分为单位存储与计算
  - 时区 `Asia/Shanghai`
  - 不修改学员主表 status 字段语义, 不挂入既有通知 / 钱包
- **Scale/Scope**: 5 张新表, 1 列扩展, 1 行 site_settings key; 管理端 8 个新路由, 学习端 5 个新路由, 公开落地页 2 个路由; 4 个新权限点; 1 个新 Vue 页面 (学习端) + 3 个新 Vue 页面 (管理端)

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

无 project constitution, 退而采用既有 CLAUDE.md / 通用规则作为约束, 全部满足:

- **简单优先** (CLAUDE.md): 5 张新表是为合规审计边界必须的最小集, 不为将来扩展预留
- **无懒惰**: 不写"待以后实现"占位; 退款撤销钩子在 OrderService::markRefunded 内同步完成, 不另起 cron 兜底
- **库 API 优先核对** (CLAUDE.md): 复用 think-orm / webman / zod / phinx / Element Plus; 不引入新依赖
- **管理端写操作审计** (CLAUDE.md 既有约定): 所有写操作经 service `writeAudit()`, 落到 `distribution_audit_log`
- **service 层规约** (CLAUDE.md 既有约定): 跨方法常量变 private const; 时区统一 `Asia/Shanghai`; 副作用封装为 `writeAudit()` 私有方法; 写路径第一行做业务闸门
- **契约字段必须从 packages/contracts 引用** (CLAUDE.md): 前端不手写 DTO 类型
- **新增视图同 commit 提交对应 vitest/phpunit 测试** (CLAUDE.md): 见 quickstart.md 测试覆盖矩阵
- **Webman + think-orm** (CLAUDE.md): 明确 webman 2.2 + think-orm, 不用 Laravel/PHP-FPM 推断
- **不修改既有模块语义**: 不动 orders 主表 / EntitlementService / NotificationDispatchService
- **依赖 session 状态的弹窗 / 抽屉** (CLAUDE.md): 「我的分销」页走 composable, 关闭 tab 自动撤销
- **trust boundary 取值** (CLAUDE.md): 所有 share_entries / commission_records / distribution_audit_log 的 id 与数值统一 int, 时间统一 int Unix 秒

无 violation, 无需 Complexity Tracking 表.

## Project Structure

### Documentation (this feature)

```
specs/016-course-distribution/
├── plan.md              # 本文
├── research.md          # Phase 0
├── data-model.md        # Phase 1
├── quickstart.md        # Phase 1
├── contracts/
│   ├── distribution.ts  # Phase 1
│   └── README.md        # Phase 1
└── tasks.md             # Phase 2 ($speckit-tasks 输出)
```

### Source Code (既有 monorepo, 不新建项目)

```
apps/api/
├── app/
│   ├── controller/
│   │   ├── learner/DistributionController.php        # 学习端 5 路由
│   │   ├── admin/DistributionController.php          # 管理端 8 路由
│   │   └── public/ShareLandingController.php        # 公开落地页 2 路由
│   ├── service/
│   │   ├── DistributionConfigService.php
│   │   ├── ShareEntryService.php
│   │   ├── ShareVisitService.php
│   │   ├── ReferralBindingService.php                # 注册事务钩子
│   │   ├── CommissionService.php                     # 结算 / 撤销 / 对账
│   │   ├── CommissionReplayService.php               # 回放校验
│   │   └── DistributionAuditService.php
│   ├── model/
│   │   ├── Learner.php                               # + referrer_learner_id
│   │   ├── ShareEntry.php                            # 新
│   │   ├── ShareVisit.php                            # 新
│   │   ├── DistributionCourseOverride.php            # 新
│   │   ├── CommissionRecord.php                      # 新
│   │   └── DistributionAuditLog.php                  # 新
│   ├── support/
│   │   └── DistributionConfigValidator.php           # 配置保存前校验
│   └── functions.php                                 # 新增 referral maskPhone 等
├── database/migrations/
│   └── 20260906000001_distribution.php              # 单文件, 全部新表 + 列扩展
├── database/seeds/PermissionSeeder.php               # 新增 4 行 permission
└── tests/
    ├── DistributionConfigServiceTest.php
    ├── ShareEntryServiceTest.php
    ├── ReferralBindingServiceTest.php
    ├── CommissionServiceTest.php
    ├── CommissionCapTruncationTest.php
    ├── DistributionAuditCoverageTest.php
    ├── OrderRefundVoidCommissionTest.php
    ├── AdminVoidRequiresReasonTest.php
    ├── BlockedReferrerNoUpgradeTest.php
    ├── ExportCsvMaskingTest.php
    └── (其余见 quickstart.md 测试覆盖矩阵)
```

```
apps/web/
├── src/
│   ├── api/distribution.ts                           # 学员端 fetch* 函数
│   ├── views/DistributionView.vue                    # 「我的分销」页
│   ├── composables/useDistribution.ts                # 会话状态与撤销
│   └── router/index.ts                               # + /distribution
└── tests/
    ├── DistributionView.test.ts
    ├── LearnerPlaintextLeak.test.ts
    ├── DistributionApi.test.ts
    └── (其余见 quickstart.md)

apps/admin/
├── src/
│   ├── api/distribution.ts                           # 管理端 fetch* 函数
│   ├── views/distribution/
│   │   ├── DistributionConfigView.vue
│   │   ├── DistributionCourseOverridesView.vue
│   │   ├── DistributionReconcileView.vue
│   │   └── DistributionAuditView.vue
│   └── router/index.ts                               # + 4 路由
└── tests/
    ├── DistributionConfigView.test.ts
    ├── DistributionReconcileView.test.ts
    ├── DistributionAuditView.test.ts
    └── (其余见 quickstart.md)

packages/contracts/src/
├── distribution.ts                                   # 新文件
└── index.ts                                          # + export * from "./distribution"
```

**Structure Decision**: 沿用既有 monorepo. 不新增项目根目录, 不改既有 monorepo 拆分. 新增文件全部进现有 apps/api / apps/web / apps/admin / packages/contracts.

## Complexity Tracking

无 violation. 表省略.

## 关键工程决策 (摘要, 详见 research.md)

1. 推荐关系持久化在 `learners.referrer_learner_id`, DB 触发器禁止 UPDATE
2. 分享入口用「短码 + cookie + DB」三层, 注册时按 visitor_token 持久化标识恢复
3. 佣金记录与订单同事务写入, 表内 `config_snapshot_json` 保证回放可证
4. 结算事件订阅 `OrderService::markSucceeded`, 退款订阅 `OrderService::markRefunded`
5. 级别上限 ≤ 3 走应用层 + DB CHECK + 单测三层防护
6. 单笔接收人 ≤ 3 走应用层计数 + DB UNIQUE 双层防护
7. 脱敏在 service 层一次性做, 前端 / 导出 / 审计均不返明文
8. 配置写入复用既有 `site_settings` 单行表, key=`distribution_config`

## 与既有模块的集成点

| 集成点 | 文件 | 改动 |
|---|---|---|
| `OrderService::markSucceeded` | apps/api/app/service/OrderService.php | 回调链尾追加 `CommissionService::settleForOrder($orderId)` |
| `OrderService::markRefunded` | apps/api/app/service/OrderService.php | 回调链尾追加 `CommissionService::voidForOrder($orderId, 'order_refund')` |
| `LearnerController::register` | apps/api/app/controller/learner/AuthController.php | 注册事务内追加 `ReferralBindingService::bindFromVisitor($learnerId)` |
| `PermissionSeeder` | apps/api/database/seeds/PermissionSeeder.php | 新增 4 行 permission 记录 |
| `packages/contracts/src/index.ts` | packages/contracts/src/index.ts | + `export * from "./distribution"` |

## 验收口径

`make test` / `make test-api` / `make test-web` / `make test-admin` / `make test-e2e` 全过, 覆盖率 ≥ 80%. quickstart.md 中所有 SC 全过. `make lint` `make typecheck` `make phpstan` 全过.

`$speckit-tasks` 阶段产出 `tasks.md` 拆解为可独立回滚的 commit.
