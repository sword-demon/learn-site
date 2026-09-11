# Research: 课程分销方案

**Feature**: 016-course-distribution
**Spec**: [spec.md](./spec.md)
**Date**: 2026-09-06

## 摘要

本规格为新增功能（绿地）。研究围绕三个方向：(1) 落地点（API + 数据库 + 契约 + 两端 UI）、(2) 与既有模块的对接方式（订单、退款、激活码、收件箱）、(3) 合规硬约束的工程化落点（级别上限 3、单笔接收人 ≤ 3、推荐关系不可改）。

无 NEEDS CLARIFICATION，全部以既有约定推断。

## 关键决策

### Decision 1 — 推荐关系持久化在学员档案的 referrer_learner_id 列

**Rationale**: 学员注册是一次性事务，访客通过分享链接落地后注册即被绑定。把「推荐人」作为 `learners.referrer_learner_id`（外键到同一张 `learners` 表）写入，写入发生在注册事务的第一行 commit 之前，与既有注册流程在同一事务里。推荐关系一旦写入由 DB 约束 + 应用层双层保护（FR-001）：DB 触发器禁止 UPDATE 修改该列；应用层在写前做只读检查。

**Alternatives considered**:
- 单独 `referrals` 表 + 多对一关联：增加 join 成本，且学员查自己的推荐人仍要 join。学员档案上有 referrer 字段更直接。
- 写到 `share_entries.bound_learner_id`：分享入口可再生（学员在不同时间多次生成），把绑定关系绑在某个特定入口上会让「清 cookie 后仍能恢复」变难。绑在学员档案上更稳。

### Decision 2 — 分享入口用「短码 + cookie + DB」三层，绑定以 DB 为准

**Rationale**: FR-007 要求清 cookie / 换设备仍能恢复推荐关系。落地点是: 访客点分享链接时, 在 DB `share_visits` 记一条 (share_entry_id, session_token, learner_id_at_visit = null), 同时种一个短期 cookie (180 天, HttpOnly)。访客注册时: 先按 cookie 的 session_token 查 share_visits, 找到 share_entry_id, 拿到所属学员. 即使 cookie 没了, 仍可按 session_token 落 DB 的方式兜底 (FR-007 持久化语义), 因为 session_token 本身持久化.

**Alternatives considered**:
- 纯 cookie 方案: 不可, 因为用户清 cookie 后无法恢复, 违反 FR-007.
- 纯 DB 方案: 需要访客身份长期记住, 不可能.
- 折中: DB + cookie 双轨, cookie 优先, DB 兜底, 符合 FR-007 的"持久化"语义.

### Decision 3 — 佣金记录按订单快照独立成表, 与订单快照同事务写入

**Rationale**: FR-016 写「配置变更不导致历史快照改写」, FR-021 写「佣金以分为单位, 不得引入浮点误差」. 把 commission_records 与 orders 同事务写入, 表内每条记录携带: order_id, referrer_learner_id (即推荐人), referee_learner_id (即下单学员), level (1/2/3), amount_cents, status, config_snapshot_json. 配置 snapshot 是结算时刻的配置序列化, 后续回放校验用.

**Alternatives considered**:
- 不存 config_snapshot, 只存数值: 后续回放无法证明当时为什么是这个数, 影响 SC-011 验收.
- 引用当前配置: 配置变更后无法复盘.

### Decision 4 — 支付成功写 pending, 退款窗口结束再 settled

**Rationale**: FR-018 首版固定「订单成功且退款期结束」. `markSucceeded` 与颁发课程访问权同一事务内调用 `settleForOrder`, 只写入 `pending` / `pending_blocked` 并固化 `config_snapshot_json`. 既有订单「退款窗口结束且未退款」状态迁移处调用 `markSettledForOrder` (`pending → settled`). 退款订阅 `OrderService::markRefunded` → `voidForOrder`. 三者都挂既有订单生命周期, 不需要新外部消息总线或 cron.

**Alternatives considered**:
- 引入新的 message queue / Redis stream: 过度工程, 且宪章禁止把 Redis 用于令牌/验证码以外的业务缓存.
- 在 markSucceeded 时直接写 settled: 违反退款窗口默认, 会出现先发后退款的窗口.
- cron 周期扫描订单: 不能保证结算及时, SC-007 要求退款 30 秒内撤销.

### Decision 5 — 级别上限 ≤ 3 走「应用层 + DB CHECK 约束 + 单测」三层防护

**Rationale**: SC-003 要求"写入失败率 100%, 任何管理员 (含超级管理员) 都不能解除". 三层防护: (a) DistributionConfigService::saveConfig 拒绝 ≤ 0 或 > 3; (b) site_settings JSON CHECK (`level_cap` BETWEEN 1 AND 3); (c) 单测覆盖试图绕过 API 直写 DB 的场景.

**Alternatives considered**:
- 仅应用层校验: 不可, 因为有运维通道 (SQL 直写) 能绕过.
- 仅 DB 约束: 不可, 因为应用层先要给「> 3」的友好错误提示.
- 双层都不够, 必须有针对 DB 直写的测试, 见 SC-003.

### Decision 6 — 单笔订单接收人去重 ≤ 3 在结算前用 DB 唯一约束保障

**Rationale**: FR-011 要求「单笔订单接收人总人数 ≤ 3, 接收人不得重复」. 在 commission_records 上加唯一索引 (order_id, referrer_learner_id), 应用层在结算时按 order_id 计数, 若去重后人数 > 3 直接拒绝并写审计. SC-001 / SC-004 共同验收.

**Alternatives considered**:
- 仅应用层计数: 不可, 因为并发下两个结算请求都可能通过校验.
- 仅唯一索引 (order_id, referrer_learner_id): 只能防重复接收人, 防不了超过 3 个不同接收人, 所以必须配合应用层计数.

### Decision 7 — 佣金按"级别配置 + 单笔封顶"约束分摊

**Rationale**: 用户没明说级别间比例分配, 默认采用「三个级别独立配置比例」+「单笔订单总佣金上限 = 单笔封顶」. 每级佣金 = min(order_paid × level_pct, 单笔封顶 × level_pct / 总比例). 三级相加不得超过单笔封顶. 配置变更写入时若超出, 拒绝保存并提示.

**Alternatives considered**:
- 固定 100%/50%/25% 三档: 不灵活, 管理员无法按业务调.
- 让管理员自由设置, 不加约束: 会出现总佣金超单笔封顶的漏洞.
- 选这个: 既灵活又合规.

### Decision 8 — 学员脱敏在 API 层一次性做, 不在前端做

**Rationale**: SC-009 要求「任何接口任何导出场景中, 返回的学员手机号明文数量为 0」. 在 CommissionService::listForLearner / listForAdmin / exportCsv 一处做 maskPhone, 全部接口统一从 service 拿 DTO. 审计日志同样不写明文手机号, 只写 learner_id.

**Alternatives considered**:
- 前端脱敏: 不可, 因为任何抓包工具都能看到接口原始响应, 违反 SC-009.
- 多个 service 各做一次: 容易漏一处, 选 service 集中.

### Decision 9 — 路由与权限点遵循既有 module.code 命名

**Rationale**: PermissionSeeder 用 `module.code` 风格, 既有 order/promotion/catalog 模块. 新增权限点:
- `distribution.config` (module=distribution) — 管理分销配置
- `distribution.share` (module=distribution) — 学员生成分享入口
- `distribution.view_own` (module=distribution) — 学员看自己的佣金/下级
- `distribution.reconcile` (module=distribution) — 管理员对账
- `distribution.audit` (module=distribution) — 管理员审计
学员端 `distribution.share` 和 `distribution.view_own` 不需要 PermissionSeeder, 由 JWT 学员身份自动具备. 管理端写入 PermissionSeeder 的是 3 个点: `distribution.config` / `distribution.reconcile` (对账 + 撤销) / `distribution.audit`. 撤销不单独 seeder.

**Alternatives considered**:
- 用现有 module (如 promotion): 课程分销与优惠券虽都是促销, 但合规审计边界不同, 单独 module 更便于按模块出审计报表.
- 把撤销拆成第四个管理端权限点: 首版对账与撤销同一职责, 挂在 `distribution.reconcile` 即可.

### Decision 10 — 配置变更不引入新表, 沿用 site_settings 单行表

**Rationale**: 既有 site_settings 表已经存放站点级配置 (站点名/简介等). 分销配置本质上是站点级, 沿用 site_settings 行, key = `distribution_config`, value = json 序列化. 课程级覆盖用新表 `distribution_course_overrides` (course_id 主键, 覆盖字段 nullable). 这样既复用既有配置加载机制, 又支持单课覆盖.

**Alternatives considered**:
- 新建 distribution_configs 表: 与 site_settings 重叠, 增加读路径.
- 把课程覆盖塞进 courses 表: 课程表太胖, 已有 30+ 字段, 不应再塞.

## 关键技术细节

### 数据迁移文件命名

按既有惯例: `20260906000001_distribution.php` (单文件 migration 含全部新表 + 列扩展).

### 锁与并发

- 结算时对 orders.id 行锁, 防止同订单双结算.
- 退款撤销时同样行锁 + 检查 commission_records.status.
- 写 distribution_config 用 site_settings 的 advisory lock 或 version 字段乐观锁 (沿用既有 site_settings 模式).

### 与既有模块的边界

- 不修改 orders / learners 主表语义, 仅做列扩展 (orders 不需要扩展, 因为 commission_records 用 order_id 外键). learners 加 referrer_learner_id (可空, FK 自引用).
- 不修改 EntitlementService.
- 不修改 NotificationDispatchService (首版不投递佣金到账消息).
- 不修改 CourseService / LearningMapService.

### 校验层

- 写路径第一行做业务闸门: 「配置未开启」「课程未开启分销」「链路深度不足」「推荐人账户不可用」, 命中则拒绝结算.
- 输入参数: 所有数值用 int (分), 时间用 int (Unix timestamp 秒), 不接受 string 数值 (防注入).

### 时区

`Asia/Shanghai`, 沿用既有 OrderService::todayDate() / nowDatetime().

### 性能

- commission_records 按 (referrer_learner_id, status, created_at) 建索引, 学员查自己的佣金分页.
- 按 (order_id) 建唯一索引, 结算时 upsert.
- share_entries 按 learner_id + created_at 建索引.
- share_visits 按 share_entry_id + created_at 建索引; 按 visitor_token 建索引用于注册时恢复.

## 风险与缓解

| 风险 | 缓解 |
|---|---|
| 推荐关系被管理员手工改 | DB 触发器禁止 UPDATE referrer_learner_id; 应用层禁改; 审计 |
| 单笔订单接收人 > 3 | 应用层计数 + DB 唯一索引 + 单测覆盖并发双写 |
| 退款未触发佣金撤销 | OrderService::markRefunded 钩子强保证, SC-007 30 秒内验证 |
| 配置变更影响历史快照 | config_snapshot_json 入 commission_records, SC-011 回放校验 |
| 学员端泄露明文手机号 | API 层一次性 mask, SC-009 全场景验收 |
| 级别上限被改 | 应用层 + DB CHECK + 单测三层, SC-003 验收 |
| 学习端分享入口在关站后仍可见 | 前端路由守卫 + 接口 404, SC-010 验收 |

## 不可做的项目 (再次确认)

按 FR-026 / FR-027 锁定: 邀请码、跨账户结算、提现、佣金到账消息、推荐人排行榜、自动升级等首版不做.
