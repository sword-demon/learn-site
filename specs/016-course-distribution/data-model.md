# Data Model: 课程分销方案

**Feature**: 016-course-distribution
**Spec**: [spec.md](./spec.md)
**Research**: [research.md](./research.md)

## 实体总览

| 实体 | 持久化 | 用途 |
|---|---|---|
| `learners.referrer_learner_id` (新列) | `learners` 表 | 推荐关系, 一旦写入不可改 |
| `share_entries` (新表) | 学员生成的一次性分享入口 |
| `share_visits` (新表) | 访客打开分享入口的访问痕迹 |
| `distribution_course_overrides` (新表) | 单课分销覆盖 |
| `commission_records` (新表) | 佣金记录 (按订单快照) |
| `distribution_audit_log` (新表) | 分销审计 |
| `site_settings` 复用 | 站点级分销配置 (key=`distribution_config`) |

## 表与字段

### learners — 列扩展

加一列: `referrer_learner_id BIGINT UNSIGNED NULL`, 自引用 FK 到 `learners.id`. ON DELETE RESTRICT, ON UPDATE CASCADE. 加索引 `idx_learner_referrer (referrer_learner_id)`.

DB 触发器 (migration 内): 禁止 `UPDATE learners SET referrer_learner_id = ... WHERE id = ...` 在 referrer_learner_id 已有值时执行. 仅允许 NULL → value 的初次写入.

### share_entries — 学员分享入口

| 字段 | 类型 | 约束 |
|---|---|---|
| id | BIGINT UNSIGNED | PK, auto_increment |
| learner_id | BIGINT UNSIGNED | NOT NULL, FK → learners.id |
| short_code | CHAR(12) | NOT NULL, UNIQUE, 字母数字 (去易混字符) |
| short_code_encrypted | VARBINARY(255) | NOT NULL, 可逆加密形态 (用于落地页校验) |
| scope | ENUM('course', 'site') | NOT NULL |
| course_id | BIGINT UNSIGNED | NULL, FK → courses.id (scope=course 时 NOT NULL) |
| created_at | DATETIME | NOT NULL |
| distribution_enabled_at_creation | TINYINT(1) | NOT NULL (生成时是否开启分销) |
| revoked_at | DATETIME | NULL (学员主动撤销入口) |

索引:
- `UNIQUE (short_code)`
- `idx_share_entry_learner (learner_id, created_at)`

### share_visits — 访问痕迹

| 字段 | 类型 | 约束 |
|---|---|---|
| id | BIGINT UNSIGNED | PK |
| share_entry_id | BIGINT UNSIGNED | NOT NULL, FK → share_entries.id |
| visitor_token | CHAR(32) | NOT NULL (随机, 种在 cookie 也存 DB, 用于跨设备恢复) |
| visited_at | DATETIME | NOT NULL |
| bound_learner_id | BIGINT UNSIGNED | NULL (若该访问最终带来新注册, 写入学员 id) |
| bound_at | DATETIME | NULL |

索引:
- `idx_share_visit_entry_time (share_entry_id, visited_at)`
- `idx_share_visit_token (visitor_token)` (注册时按此查回 share_entry_id)

### distribution_course_overrides — 课程级覆盖

| 字段 | 类型 | 约束 |
|---|---|---|
| course_id | BIGINT UNSIGNED | PK, FK → courses.id |
| enabled | TINYINT(1) | NOT NULL DEFAULT 0 (课程级开关) |
| level1_pct | DECIMAL(5,4) | NULL (NULL = 用全局) |
| level2_pct | DECIMAL(5,4) | NULL |
| level3_pct | DECIMAL(5,4) | NULL |
| per_order_cap_cents | INT UNSIGNED | NULL (单笔封顶, NULL = 用全局) |
| per_learner_course_cap_cents | INT UNSIGNED | NULL |
| updated_by | BIGINT UNSIGNED | NOT NULL, FK → staff_users.account_id |
| updated_at | DATETIME | NOT NULL |

### commission_records — 佣金记录

| 字段 | 类型 | 约束 |
|---|---|---|
| id | BIGINT UNSIGNED | PK |
| order_id | BIGINT UNSIGNED | NOT NULL, FK → orders.id |
| referrer_learner_id | BIGINT UNSIGNED | NOT NULL, FK → learners.id (佣金接收人) |
| referee_learner_id | BIGINT UNSIGNED | NOT NULL, FK → learners.id (下单学员) |
| level | TINYINT UNSIGNED | NOT NULL, CHECK (level BETWEEN 1 AND 3) |
| amount_cents | INT UNSIGNED | NOT NULL (0 允许, 表示按封顶截断) |
| status | ENUM('pending', 'settled', 'voided', 'pending_blocked') | NOT NULL |
| source | ENUM('system_settle', 'system_refund_void', 'admin_void') | NOT NULL |
| config_snapshot_json | JSON | NOT NULL (结算时的分销配置) |
| order_paid_cents_snapshot | INT UNSIGNED | NOT NULL (订单实付快照) |
| created_at | DATETIME | NOT NULL |
| settled_at | DATETIME | NULL |
| voided_at | DATETIME | NULL |
| voided_by | BIGINT UNSIGNED | NULL, FK → staff_users.account_id (管理员撤销时) |
| void_reason | VARCHAR(500) | NULL |

约束与索引:
- `CHECK (level BETWEEN 1 AND 3)`
- `UNIQUE (order_id, referrer_learner_id)` (单笔订单同一接收人唯一)
- `idx_commission_referrer (referrer_learner_id, status, created_at)` (学员端按状态分页查自己)
- `idx_commission_order (order_id)` (对账页按订单查)
- `idx_commission_referee (referee_learner_id)` (学员端查被推荐人脱敏列表)

### distribution_audit_log — 分销审计

| 字段 | 类型 | 约束 |
|---|---|---|
| id | BIGINT UNSIGNED | PK |
| actor_type | ENUM('admin', 'system') | NOT NULL |
| actor_id | BIGINT UNSIGNED | NULL (system 时 NULL) |
| action | VARCHAR(64) | NOT NULL (config.update / course.override / commission.settle / commission.void / etc.) |
| subject_type | ENUM('config', 'course_override', 'commission', 'share_entry') | NOT NULL |
| subject_id | BIGINT UNSIGNED | NULL |
| before_json | JSON | NULL |
| after_json | JSON | NULL |
| reason | VARCHAR(500) | NULL (撤销必填) |
| created_at | DATETIME | NOT NULL |

索引:
- `idx_audit_action_time (action, created_at)`
- `idx_audit_subject (subject_type, subject_id)`
- 不写明文手机号, 不写明文推荐人昵称.

### site_settings — 站点分销配置 (复用)

| key | value (json) |
|---|---|
| `distribution_config` | 见下 |

```jsonc
{
  "enabled": false,
  "level_cap": 3,                    // DB CHECK 约束 <= 3
  "level1_pct": 0.10,
  "level2_pct": 0.05,
  "level3_pct": 0.025,
  "base": "order_paid",              // order_paid / list_price / sale_price
  "per_order_cap_cents": 5000,
  "per_learner_course_cap_cents": 20000,
  "per_learner_total_cap_cents": null,
  "settlement": "order_settled_after_refund_window", // order_settled / order_settled_after_refund_window / admin_manual
  "refund_void_rule": "void_all",   // void_all / pro_rata / none
  "payout_form": "cash_record_only", // cash_record_only (首版) / site_balance
  "learner_can_view_detail": true
}
```

DB 层加 CHECK: `JSON_EXTRACT(value, '$.level_cap') <= 3`. (MySQL 8 支持.)

## 状态机

### commission_records.status

```
                system_settle
[pending]  ─────────────────────►  [settled]
   │                                    │
   │ system_refund_void / admin_void    │
   ▼                                    ▼
[voided]  ◄─────────────────────────────┘
                              (from settled, 走同样路径)

[pending_blocked]  ◄─ system_settle 时推荐人账户不可用
```

迁移规则:
- pending → settled: 由 system_settle 在订单退款期结束且无退款时触发.
- pending → voided: 由 system_refund_void 在订单退款时触发, 或 admin_void 在管理员撤销时触发.
- pending → pending_blocked: 由 system_settle 时检测到推荐人账户不可用.
- settled → voided: 同样可由退款 (事后) 或管理员撤销触发.
- pending_blocked → voided: 管理员按审计流程撤销.
- voided 是终态, 不可再改.

### share_entries.revoked_at

- NULL → 有效.
- 非 NULL → 已撤销, 不再记 visit.

## 关系图

```
learners (1) ──< (0..n) share_entries
share_entries (1) ──< (0..n) share_visits
share_visits (0..1) >── (1) learners (bound_learner_id)

learners.referrer_learner_id ──> learners.id (自引用, 0..1)

orders (1) ──< (0..3) commission_records
commission_records.referrer_learner_id ──> learners
commission_records.referee_learner_id ──> learners
commission_records.voided_by ──> staff_users

courses (1) ──< (0..1) distribution_course_overrides

site_settings ── (单行, key='distribution_config')

distribution_audit_log (独立, 通过 subject_type+subject_id 反查)
```

## 校验规则

### 推荐关系写入 (FR-001 / FR-002 / FR-005)

- 注册事务第一行: 按 visitor_token 查 share_visits, 取最新未绑定的访问, 拿到 share_entry.learner_id 作为 referrer.
- 校验: referrer 必须存在且不是当前注册学员. 校验通过后 UPDATE learners SET referrer_learner_id = ? WHERE id = ? AND referrer_learner_id IS NULL.
- 同 visitor_token 第二个注册: 因 referrer_learner_id 已非 NULL, 校验失败, 该次注册获得 referrer = NULL.

### 结算完整性 (FR-011 / FR-012)

在写 commission_records 之前, 同事务内:
1. 取 orders 行锁.
2. 取该订单的 referee (下单学员) 链路, 沿 referrer_learner_id 上溯, 取最近的 ≤ 3 个祖先 (按链路距离升序, 即 1/2/3 级).
3. 按配置算每级 amount_cents, 总和 ≤ per_order_cap_cents. 超额按级别从远到近截断.
4. 写入 N 条 (N ≤ 3) commission_records, 应用层校验去重后接收人 = N.
5. 同一 (order_id, referrer) 唯一约束保证不重复.

### 配置变更 (FR-016)

- level_cap: 应用层拒绝 > 3, DB CHECK 拒绝 > 3, 单测覆盖.
- 其它字段: 写入时校验与单笔封顶一致 (level1_pct + level2_pct + level3_pct 三级之和不得导致总额超 per_order_cap_cents / order_paid_cents). 若选 base=order_paid, 按 1 元订单反推, 上限比例 = per_order_cap_cents / 100. 三级比例之和 ≤ 该上限.

## 容量估算

- share_entries: 每学员 1~10 条 → 10 万学员 ≈ 100 万行. OK.
- share_visits: 每分享入口 0~1000 条 → 1 亿行上限, 需按月分区 (建表加 created_at 索引即可, MySQL 8 partitioning 首版不做, 索引足够).
- commission_records: 每订单 ≤ 3 条 → 100 万订单 ≈ 300 万行. OK.

## 不存储的字段

- 明文手机号 (仅 learners.phone, 学员 ID 引用).
- 推荐人 / 被推荐人昵称 / 头像 (学员端展示按 learner_id 现取, 不冗余到 commission_records).
- 短码明文 (仅存 short_code_encrypted, 学员端展示用脱敏).
