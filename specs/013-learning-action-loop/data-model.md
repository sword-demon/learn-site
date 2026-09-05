# Data Model: 013-learning-action-loop

## 概览

```text
现有事实表
  accounts
  ├── course_entitlements ── course_enrollments ── lesson_progresses
  ├── favorites ── courses
  ├── orders ── learner_coupons
  ├── map_enrollments ── learning_maps / map_stage_courses
  └── learner_notifications

新增
  learner_reminder_evaluations ── learner_notifications

请求时计算
  LearningActionService -> 一个 next_action 或服务端降级/空状态
```

`next_action` 不是实体，不新增 `learner_next_actions`、推荐分数、学习节奏快照或候选缓存。课程访问权、学习进度、地图状态、收藏、订单、优惠券和消息仍由各自现有表及服务端状态机负责。

## learner_reminder_evaluations

每个学员、固定规则和固定事件最多一行，保存该事件最近一次评估以及最近一次通知投递信息。它是节流与解释记录，不是学习状态表。

| 字段 | 类型 | 约束/用途 |
|------|------|-----------|
| id | BIGINT UNSIGNED PK | 自增 |
| learner_id | BIGINT UNSIGNED | 非空；FK → `accounts.id`，删除 `CASCADE`；service 仍须确认账户为 active learner |
| rule_code | VARCHAR(48) | 非空；`favorite_not_started`、`order_expiring`、`coupon_expiring`、`learning_inactive` |
| candidate_key | VARCHAR(160) | 非空、ASCII 规范化；事件身份，见下表 |
| resource_type | VARCHAR(32) NULL | 多态资源类型；不建多态 FK |
| resource_id | BIGINT UNSIGNED NULL | 资源 ID；列表降级目标可为空 |
| evaluation_day | DATE | 按 `Asia/Shanghai` 写入的评估本地日 |
| evaluation_status | ENUM | `not_eligible`、`quiet_hours`、`daily_cap`、`throttled`、`resource_unavailable`、`sent`、`failed` |
| reason_code | VARCHAR(64) | 非空；供解释与排障，不直接暴露内部错误文本 |
| last_evaluated_at | DATETIME | 非空；服务端评估时间 |
| first_sent_at | DATETIME NULL | 第一次成功写入消息的时间 |
| last_sent_at | DATETIME NULL | 最近一次成功写入消息的时间；每日上限按该字段计数 |
| send_count | INT UNSIGNED | 默认 0；每次成功写消息加 1 |
| notification_id | BIGINT UNSIGNED NULL | 最近一条消息 ID；FK → `learner_notifications.id`，删除 `SET NULL` |
| suppressed_at | DATETIME NULL | 因每日上限跳过时写入，防止下一批立即补发 |
| error_message | VARCHAR(500) NULL | 仅内部日志/排障；不得进入学习端响应 |
| created_at | DATETIME | 非空 |
| updated_at | DATETIME | 非空 |

**唯一键与索引**：

- `UNIQUE (learner_id, rule_code, candidate_key)`：并发评估无法创建同一事件的第二行。
- `INDEX (learner_id, last_sent_at)`：统计学员上海本地日内已发送数量。
- `INDEX (rule_code, last_evaluated_at)`：定位规则评估和任务运行状态。
- `INDEX (notification_id)`：消息清理或追踪时快速回溯。

`candidate_key` 非空且使用固定 ASCII 格式，避免依赖包含 nullable 字段的唯一索引。资源 ID 不做 FK，因为目标可以是课程、课节、地图、订单、优惠券或列表路径，且这些表已有各自的生命周期和删除策略。

## 事件键与状态语义

| 规则 | 事件键 | 资源 | 事件结束条件 |
|------|--------|------|--------------|
| 收藏课程待开始 | `favorite:{favorite_id}` | `course/{course_id}` | 收藏取消、重新开始课程、课程不再发布 |
| 订单临期 | `order:{order_id}` | `order/{order_id}` | 订单成功、失败、取消或 15 分钟截止 |
| 优惠券临期 | `coupon:{learner_coupon_id}` | `coupon/{coupon_id}`，路径为优惠券列表 | 券使用、锁定、作废、过期或不再适用 |
| 长期未学习 | `inactive:{local_week_start}` | 当前可执行学习目标 | 产生有效学习行为或本周事件结束 |
| 规则无候选 | `none:{local_date}` | 空 | 仅用于记录本地日内的未命中状态，不发送消息 |

收藏关系删除后再插入会得到新 `favorite_id`，因此“同一收藏关系一次”不会阻止新的收藏关系触发。订单当前没有 `expires_at`，`order:{id}` 的截止时间必须由 `OrderService` 的共享 15 分钟计算得出。优惠券同一学员同一评估只选最早到期的一张；同一课程适用多张券不会产生多条提醒。

`last_sent_at` 只记录成功投递，不因 `throttled`、`quiet_hours` 或 `daily_cap` 改写。重复评估可以把 `evaluation_status` 更新为 `throttled`，但仍保留最近发送时间和消息 ID；`send_count` 只统计真实消息行，不统计评估次数。

## 状态转换

```text
not_eligible
    └─ 发现候选 ──┬─ quiet_hours ── 08:00 后 ──────┐
                 ├─ daily_cap ── 事件结束/新事件 ───┤
                 ├─ throttled ── 超过节流窗口 ──────┤
                 ├─ resource_unavailable ──────────┤
                 ├─ failed ── 下次任务重试 ─────────┤
                 └─ sent <──────────────────────────┘
```

详细规则：

1. `not_eligible` 不创建通知；候选出现后使用真实事件键建立/更新记录。
2. `quiet_hours` 表示条件成立但未主动触达；次日 08:00 后重新验证条件。
3. `daily_cap` 表示本日明确跳过；`suppressed_at` 防止同一事件在当日后续批次补发。
4. `throttled` 表示最近成功投递仍在 72 小时内；收藏事件即使超过 72 小时也不再发，长期未学习使用下一周事件键。
5. `resource_unavailable` 表示生成/发送前资源重新校验失败；不创建死链消息。
6. `failed` 只用于可重试的数据库/消息写入故障；下一次任务使用相同事件键和消息幂等键重试。
7. `sent` 表示已通过 `MessageService::emit` 写入 `learner_notifications`，未读计数继续由既有 `UnreadCounterService` 负责。

## 一致性与事务

提醒服务处理单个学员时：

1. 以 `accounts.id` 行锁串行化该学员的并行评估。
2. 读取四类候选并按“订单、优惠券、收藏、长期未学习”排序，每条规则最多一个。
3. 对每个候选锁定其评估行或插入唯一事件行，检查资源最新状态、候选是否被节流和当前上海本地日已发送数量。
4. 仅在允许发送时调用 `MessageService::emit`，幂等键由 `learner_id + rule_code + candidate_key` 确定性生成。
5. 成功后在同一数据库事务中更新 `sent`、`last_sent_at`、`notification_id`、`send_count`；失败不增加未读计数。

账号行锁是每日上限的并发闸门，不新增全局锁或 Redis 锁。`MessageService` 的既有唯一 `(learner_id, idempotency_key)` 仍是消息级最后防线。

## 现有事实的读取边界

### 主下一步

- 订单：`orders.status=pending`，截止时间大于 now 且进入 24 小时窗口；只读本人订单。
- 优惠券：`learner_coupons.status=unused`、活动 active、`expires_at > now`、在 3 个自然日窗口内，并适用至少一门已发布收费课程；适用范围和门槛复用 `CouponService`。
- 继续学习：`course_entitlements.status=active`、课程已发布、课节 enabled 且未完成；`course_enrollments` 只作为恢复位置和最近时间来源。
- 消息：本人未读、资源仍可用的 `learner_notifications`；资源失效时不作为可执行主行动。
- 地图：本人已加入的已发布地图，使用 `LearningMapService` 的阶段/步骤顺序和访问状态。
- 收藏：本人仍持有、课程已发布、尚无课程开始记录的 `favorites`。

### 长期未学习

最近有效学习时间来自本人 `lesson_progresses.updated_at` 的服务端写入。登录、查看列表、查看课程详情、收藏和单纯打开消息不更新该信号；有效课节进度写入后，下一次评估解除 `learning_inactive`。

## 迁移与回滚

计划新增 `apps/api/database/migrations/20260904000002_learning_action_loop.php`：

- `up` 创建 `learner_reminder_evaluations` 及字段、唯一键、索引和外键。
- `down` 先删除 `notification_id` 与 `learner_id` 外键，再删除索引和表；不会触碰既有课程进度、订单、优惠券和消息数据。
- 迁移不修改 `orders`，不修改既有消息行，不把历史消息转成自动提醒。
- 回滚前应按仓库惯例完成数据库备份；回滚会丢失提醒评估节流记录，恢复后可能对仍有效事件重新评估，故属于需要明确操作的回滚动作。

同时新增 `LearnerReminderEvaluation` think-orm 模型；业务查询和写入只能通过 `support\think\Db`/该模型，不能引入第二套 ORM。

## 不落库的对象

以下对象只存在于 PHP 请求内或 API 响应内：

- 候选的排序分值/优先级：只保存固定 `priority`，不保存学习偏好分数。
- `next_action`：每次请求重新读取，含 `generated_at`，不做缓存。
- 学习节奏：由最近有效 `lesson_progresses` 时间推导，不新增 `learning_rhythm` 字段。
- 客户端 action store：前端可在组件生命周期内保存当前响应，但不得持久化或用它覆盖服务端进度、访问权和提醒状态。
