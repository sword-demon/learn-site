# Research: 学习事实漏斗

**Feature**: [spec.md](./spec.md)
**Branch**: `017-learning-fact-funnel`
**Date**: 2026-09-11

## Codebase Context (existing patterns to reuse)

| Layer | Canonical pattern | Reference |
|---|---|---|
| 课程访问权 | `course_entitlements`：`source` = `free` / `purchase` / `activation_code`，`created_at` = 生效时刻，`status` = `active` / `revoked` | `EntitlementService`，`20260823000004_create_learning.php` |
| 课节打开 | `lesson_progresses.opened_at` 在首次进度写入时设置 | `ProgressService.php` |
| 有效进度 / 完成课节 | `lesson_progresses.completed = 1` 且 `completed_at` 单调 | 同上；视频 90% 自动完成，Markdown/PDF 需先打开 |
| 完成课程 | `course_enrollments.completed_at` | `20260823000004_create_learning.php` |
| 试看 | `lessons.is_preview`；无访问权也可 `PublicLessonService::deliver` | `PublicLessonService.php` |
| 订单 | `orders.status`；仅 `succeeded` 产生付费访问权 | `OrderService` |
| 发布触达 | `notification_dispatches.type = course_published`，`resource_id` = 课程 | `NotificationDispatchService` |
| 课程学员页 | `/courses/:id/students`，权限 `course_student.view`，受数据范围 | `CourseStudentView.vue` |
| 管理端读接口 | 薄 controller + service 聚合，不写 `audit_log` | `DashboardService` |
| 时区 | `Asia/Shanghai` + `nowDatetime()` | `app/functions.php` |
| Redis | 仅令牌与图形验证码 | 宪章质量门禁 #2 |

## Decisions

### D1. 只读聚合现有表，不新建漏斗表，不走 Redis

**Decision**: `LearningFactFunnelService` 在管理端 GET 时对 `course_entitlements`、`lesson_progresses`、`course_enrollments`、`orders`、`notification_dispatches`、`lessons` 做只读聚合。不写新业务表，不在进度事务里更新报表，不用 Redis 缓存。

**Rationale**: 规格要求统计不得拖慢学习写入。进度路径已经用行锁写 `lesson_progresses`；再挂报表会把观察读进热路径。宪章禁止把 Redis 当业务缓存。按课程查询、窗口最长 90 日，现有索引加覆盖索引足够首版。

**Alternatives considered**:
- 进度提交时同步累加漏斗计数 — 拒绝：直接违反 FR-024。
- 定时任务快照表 — 拒绝：首版为假设性吞吐引入新表和调度，属于提前抽象。
- Redis 缓存课程漏斗 — 拒绝：宪章 Redis 仅用于令牌 / 验证码。

### D2. 漏斗事件全部用“访问权生效之后”的时间戳

**Decision**:
- 进入队列：窗口内 `course_entitlements.created_at`（含随后被取消免费加入的历史行）。
- 首次打开课节：该课任一课节 `COALESCE(opened_at, created_at)` 的最小值，且 `>= entitlement.created_at` 且落在窗口内。
- 有效进度：该课任一课节 `completed = 1` 且 `completed_at` 在生效时刻与窗口结束之间。
- 完成课程：`course_enrollments.completed_at` 在同一区间。

**Rationale**: 规格问的是“购买或发放之后是否开始并完成”。生效前的试看不得抬高后三阶段。`opened_at` 是打开事实；`completed` 才是有效进度，避免把“打开即完成”和“打开但未学”混在一起。

**Alternatives considered**:
- 用 `course_enrollments.created_at` 当打开 — 拒绝：加入免费课就会建学习记录，不等于打开课节。
- 把 `position_seconds > 0` 当有效进度 — 拒绝：Markdown/PDF 打开就会写 position；与 CONTEXT 课节进度不一致。

### D3. 试看对照只统计可核验的登录学员事实

**Decision**: 试看人数 = 对本课 `is_preview = 1` 课节有 `opened_at`，且该打开时刻没有该课有效课程访问权（无行，或 `entitlement.created_at` 晚于该 `opened_at`）的去重学员数。未登录访客试看不计入，因为 `PublicLessonService::deliver` 不为访客写进度。

**Rationale**: 规格禁止页面曝光冒充事实。访客试看没有服务端进度行，不能编造。登录后试看留下 `lesson_progresses`，可以核对。

**Alternatives considered**:
- 为访客试看新建点击日志 — 拒绝：扩大范围，且接近页面曝光。
- 用学习端前端埋点 — 拒绝：规格只要服务端进度 / 订单 / 访问权。

### D4. 订单与发布触达复用既有行，不改语义

**Decision**: 订单对照按该课 `orders` 在窗口内按 `created_at`（或 `succeeded_at` 对成功单）分组支付结果，至少返回 `succeeded` 笔数。发布触达取该课 `notification_dispatches` 中 `type = course_published` 的 `recipient_count` 合计（及 dispatch 条数），与“窗口内访问权生效人数”分开展示。

**Rationale**: 015 已要求访问权人数与通知人数分开。漏斗进入队列用访问权，触达用消息，互不替代。

**Alternatives considered**:
- 用消息已读当触达 — 拒绝：已读不是规格里的发布触达事实，且会把打开消息当成学习。
- 把券核销当漏斗来源 — 拒绝：FR-013 以来源为准，券只可能促成支付成功。

### D5. 窗口进行中 vs 窗口内未转化在服务端划分

**Decision**: 对进入队列但未达某阶段的学员：若 `now < created_at + window_days`（中国标准时间日期）则计入 `in_window_pending`；否则计入 `window_elapsed_not_converted`。文案由契约固定字符串下发，禁止“流失”“弃学”。

**Rationale**: 规格把误判未开始为流失列为代价。客户端不得自己用本地时钟重贴标签。

**Alternatives considered**:
- 只给一个“未转化”总数 — 拒绝：刚发放的课会被看成失败。
- 前端按 `generated_at` 自己切 — 拒绝：文案和口径会漂。

### D6. 权限复用 `course_student.view`，挂在课程学员旁

**Decision**: `GET /api/admin/v1/courses/{id}/learning-funnel` 需要 `course_student.view`，并走既有课程数据范围。管理端路由 `/courses/:id/learning-funnel`，从课程学员页进入。不新增权限码。GET 不写 `audit_log`。

**Rationale**: 漏斗是课程学员名单的汇总观察，不是全站工作台。新权限码没有多一类读者。读路径写审计是噪音。

**Alternatives considered**:
- `dashboard.view` — 拒绝：工作台是全站，漏斗是单课且要数据范围。
- 新权限 `course.funnel` — 拒绝：首版读者与课程学员相同，多一个码要改角色种子。

### D7. 覆盖索引可以加，热路径不加锁

**Decision**: 若验收查询偏慢，只允许新增只读友好索引，例如 `course_entitlements (course_id, created_at, source)`。禁止在 `ProgressService` 事务中 `SELECT` 漏斗或 `GET_LOCK`。

**Rationale**: 索引不改变写入语义；热路径耦合会。

**Alternatives considered**: 物化计数器、汇总队列 — 见 D1。
