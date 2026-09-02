# Data Model: 010-course-notify-feedback-codes

约定与 `001` 一致: InnoDB, `utf8mb4_unicode_ci`, 无符号 BIGINT PK, 时间字段按 `Asia/Shanghai` 写入 DATETIME/TIMESTAMP, 对外输出走服务内 `toIso8601`.

## 概览

```text
courses
  ├── notification_dispatches (type=course_published, resource=course)
  │     └── learner_notifications (kind=course_published, resource=course)
  ├── activation_code_batches
  │     └── activation_codes ──redeem──► course_entitlements (source=activation_code)
  └── course_feedbacks
```

---

## 扩展: notification_dispatches

| 变更 | 规则 |
|------|------|
| `type` ENUM | 增加 `course_published` |
| `resource_type` VARCHAR(32) NULL | 课程发布为 `course`; 公告/站内信为 NULL |
| `resource_id` BIGINT UNSIGNED NULL | 课程 id |
| 既有 `fan_out_*` | 复用 008; 发布入队失败 → `failed` 且课程保持已发布 |

**校验**:
- `type=course_published` 时 `recipient_mode=all`, `resource_type=course`, `resource_id` 指向存在的课程.
- `sender_staff_id` = 执行发布的后台用户.

**索引**: `(resource_type, resource_id)`, 保留 `(type, created_at)`.

---

## 扩展: learner_notifications

| 变更 | 规则 |
|------|------|
| `kind` | 已是 VARCHAR(32); 新值 `course_published` |
| `resource_type` / `resource_id` | fan-out 从 dispatch 复制, 不得再写 NULL |
| `idempotency_key` | `{dispatch_id}:{learner_id}` |
| `dispatch_id` | 指向本次发布投递 |

清理: 003 的 2 个月收件箱任务按 `created_at` 删除, 新 kind 自动覆盖.

---

## 扩展: course_entitlements

| 变更 | 规则 |
|------|------|
| `source` ENUM | `free` \| `purchase` \| `activation_code` |
| `activation_code_id` BIGINT UNSIGNED NULL | 仅 `source=activation_code` 时有值; FK → `activation_codes.id` RESTRICT |

**不变量** (延续 001 + 本规格):
- 同一学员同一课至多一条 `status=active` (既有生成列唯一索引).
- `isRevocable()` 仅 `source=free`.
- `source=activation_code` 时 `order_id` 必须为 NULL.
- `source=purchase` 时仍要求 `order_id`.

---

## activation_code_batches

管理员一次生成操作.

| 字段 | 类型 | 规则 |
|------|------|------|
| id | BIGINT UNSIGNED PK | 自增 |
| course_id | BIGINT UNSIGNED FK | → courses.id RESTRICT |
| quantity | INT UNSIGNED | 1–1000, 等于子行数 |
| expires_at | DATETIME NULL | NULL=不过期; 子码复制该值 |
| created_by_staff_id | BIGINT UNSIGNED | 生成者 |
| created_at | DATETIME | |
| updated_at | DATETIME | |

**索引**: `(course_id, created_at)`.

**校验** (`ActivationCodeService`):
- 课程必须 `status=published` 且 `price_mode=paid`.
- 课程须通过调用者数据范围.
- `expires_at` 若有值必须严格晚于生成时刻.

---

## activation_codes

| 字段 | 类型 | 规则 |
|------|------|------|
| id | BIGINT UNSIGNED PK | |
| batch_id | BIGINT UNSIGNED FK | → batches.id RESTRICT |
| course_id | BIGINT UNSIGNED FK | 冗余便于兑换按课校验 |
| code_hash | CHAR(64) | SHA-256 hex, **全局唯一** |
| code_prefix | CHAR(4) | 明文前 4 位 (规范化后) |
| code_suffix | CHAR(4) | 明文后 4 位 |
| status | ENUM | `unused` \| `redeemed` \| `void` |
| expires_at | DATETIME NULL | 从批次复制 |
| redeemed_by_learner_id | BIGINT UNSIGNED NULL | 兑换成功时 |
| redeemed_at | DATETIME NULL | |
| voided_by_staff_id | BIGINT UNSIGNED NULL | |
| voided_at | DATETIME NULL | |
| created_at | DATETIME | |
| updated_at | DATETIME | |

**索引**:
- UNIQUE `code_hash`
- `(course_id, status)`
- `(batch_id)`
- `(redeemed_by_learner_id)`

**状态机**:

```text
unused --redeem 成功--> redeemed
unused --管理员作废--> void
unused + now>=expires_at --> 派生已过期 (仍存 unused, 兑换拒绝)
redeemed / void --> 终态, 不可作废, 不可再兑
```

**兑换事务顺序**:
1. 规范化输入, 算 hash; 找不到 → `ACTIVATION_CODE_INVALID` (不暴露内部).
2. `FOR UPDATE` 该行.
3. `void` / `redeemed` / 过期 / 课程非已发布 → 对应错误, 不改行.
4. 学员对该课已有 active 授权 → `ENTITLEMENT_ALREADY_ACTIVE`, **不改码**.
5. `EntitlementService::grant(..., 'activation_code')` + 本行 `redeemed`.
6. `writeAudit('activation_code.redeem')`.

**展示**: 列表 `display_code = prefix + '****' + suffix`. 明文数组只出现在创建批次的响应 `codes[]`.

---

## course_feedbacks

| 字段 | 类型 | 规则 |
|------|------|------|
| id | BIGINT UNSIGNED PK | |
| course_id | BIGINT UNSIGNED FK | → courses.id RESTRICT |
| learner_id | BIGINT UNSIGNED FK | → accounts.id RESTRICT |
| body_html | MEDIUMTEXT | 已消毒 HTML, 非空 |
| status | ENUM | `pending` \| `processed`, 默认 pending |
| processed_by_staff_id | BIGINT UNSIGNED NULL | |
| processed_at | DATETIME NULL | |
| created_at | DATETIME | |
| updated_at | DATETIME | |

**索引**: `(course_id, created_at)`, `(course_id, status)`, `(learner_id, created_at)`.

**校验**:
- 提交者必须对该课有 `course_entitlements.status=active`.
- `HtmlSanitizer::sanitize`; 消毒后可见文本为空 → 拒绝.
- 输入长度 ≤ 20_000 字符; 超限 `FEEDBACK_BODY_TOO_LONG`.
- 允许多条; 不与 `reviews` 关联.

**状态机**: `pending` ↔ `processed` (管理员可打回待处理). 变更写审计.

---

## 关系与删除

- 课程有激活码或反馈或兑换产生的 entitlement 时, 沿用既有「有学习记录/订单/授权则不能删除」. 激活码行 RESTRICT 课程删除, 与 entitlement 一致.
- 作废码不物理删除.
- 学员账户停用: 码保持 `redeemed`, 不可转赠.

---

## 权限种子

`PermissionSeeder` 追加:

| code | module | 说明 |
|------|--------|------|
| `activation_code.manage` | catalog | 生成/查询/作废课程激活码 |
| `course_feedback.manage` | catalog | 查看与处理课程意见反馈 |
