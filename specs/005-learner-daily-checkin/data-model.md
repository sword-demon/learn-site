# Data Model: 005-learner-daily-checkin

## 概览

```text
accounts (learner) (1) ──< learner_daily_checkins (N)
                              │
                              └── plan_html (富文本，净化后存储)
```

- **签到记录**（`learner_daily_checkins`）：学员某一自然日的一次签到；含每日计划 HTML。
- **当日状态**：由 `EXISTS (learner_id, checkin_date = TODAY)` 派生，不单独建表。

---

## learner_daily_checkins

学员每日签到记录。

| 字段 | 类型 | 规则 |
|------|------|------|
| id | BIGINT UNSIGNED PK | 自增 |
| learner_id | BIGINT UNSIGNED | FK → `accounts.id`（学员账户） |
| checkin_date | DATE | 站点时区自然日；非空 |
| plan_html | TEXT | 净化后富文本；非空；有效文本 1–10000 字符 |
| checked_in_at | DATETIME | 签到发生时刻（写入时 `NOW()`） |
| created_at | TIMESTAMP | 创建时间（与 `checked_in_at` 同值或略早） |

**唯一约束**: `UNIQUE uk_learner_checkin_date (learner_id, checkin_date)`

**索引**:
- `(learner_id, checkin_date DESC)` — 学员历史列表
- `(checkin_date DESC)` — 管理端按日筛选
- `(checked_in_at DESC)` — 管理端默认排序

**外键**: `learner_id` → `accounts.id` ON DELETE RESTRICT（学员删除策略见假设；首版 RESTRICT，删除学员前需先处理签到记录或走运营流程）

**校验**（服务层）:
- `plan_html` 经 `HtmlSanitizer::sanitize()` 后不得为空壳
- 净化后可见文本长度 1–10000
- `checkin_date` 仅服务端赋值，必须等于站点「今日」
- 重复 `(learner_id, checkin_date)` → `ALREADY_CHECKED_IN`

**删除**: 管理端物理 `DELETE`；无软删除字段。

---

## 客户端 DTO 映射

### LearnerCheckinDTO（学习端列表/详情/今日状态）

| 字段 | 类型 | 来源 |
|------|------|------|
| id | number | `id` |
| checkin_date | string | `checkin_date`（`YYYY-MM-DD`） |
| plan_html | string | `plan_html` |
| checked_in_at | string | ISO8601 `checked_in_at` |

### LearnerTodayCheckinDTO

| 字段 | 类型 | 说明 |
|------|------|------|
| server_date | string | 站点当前自然日 `YYYY-MM-DD` |
| checked_in | boolean | 今日是否已签到 |
| record | LearnerCheckinDTO \| null | 已签时返回当日记录 |

### AdminCheckinDTO（管理端列表/详情）

| 字段 | 类型 | 来源 |
|------|------|------|
| id | number | `id` |
| learner_id | number | `learner_id` |
| learner_display_name | string \| null | JOIN `learners.display_name` |
| learner_phone_masked | string | JOIN `accounts.login` 脱敏 |
| checkin_date | string | `checkin_date` |
| plan_html | string | 详情全量；列表可选 |
| plan_summary | string | 列表：去 HTML 截断 120 字 |
| checked_in_at | string | ISO8601 |

### CreateCheckinInput（学习端 POST body）

| 字段 | 类型 | 规则 |
|------|------|------|
| plan_html | string | 必填；服务端净化与空壳校验 |

---

## 状态转换

### 学员签到

```text
[POST /checkins + active 学员]
  --> 校验 plan_html
  --> HtmlSanitizer::sanitize
  --> 校验非空、长度
  --> checkin_date = TODAY (Asia/Shanghai)
  --> INSERT learner_daily_checkins
  --> INSERT audit_log (checkin.create)
  --> 返回 LearnerCheckinDTO

[重复 POST 同日]
  --> UNIQUE 冲突或预查
  --> INSERT audit_log (checkin.duplicate_rejected)
  --> 409 ALREADY_CHECKED_IN
```

### 管理端删除

```text
[DELETE /checkins/{id}]
  --> 校验 checkin.manage
  --> SELECT 记录
  --> DELETE 行
  --> INSERT audit_log (checkin.delete)
  --> 204

[学员再次 POST 同日]
  --> INSERT 成功（该日已无记录）
```

### 弹窗驱动（客户端）

```text
[LearnerLayout mount + loggedIn]
  --> GET /checkins/today
  --> checked_in=true --> 不弹窗
  --> checked_in=false + sessionStorage dismissed --> 不弹窗
  --> checked_in=false --> 展示 DailyCheckinDialog
  --> [关闭未签] --> sessionStorage dismiss
  --> [POST 成功] --> 关闭弹窗，当日不再弹
```

---

## 与现有系统关系

| 系统 | 关系 |
|------|------|
| `HtmlSanitizer` | 签到计划入库前净化 |
| `hasRichHtml` / `MarkdownRenderer` | 学习端展示 |
| `ContentEditor`（admin） | 参考实现；web 用精简 `CheckinPlanEditor` |
| `PermissionSeeder` | 新增 `checkin.manage` |
| `Authorize` | 管理端路由前缀映射 |
| `audit_log` | 创建、重复签到拒绝与删除审计 |
| `LearnerAuth` | 学习端写操作鉴权 |
| `LearnerLayout` | 挂载 `useDailyCheckinPrompt` |

---

## 权限与可见性

| 操作 | 权限 |
|------|------|
| 查看/删除全部签到记录 | `checkin.manage` |
| 创建签到、查看本人列表/今日状态 | 学员令牌 |
| 跨学员读取 | 拒绝（404 或 403，不泄露存在性） |
