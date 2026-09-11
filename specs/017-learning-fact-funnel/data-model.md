# Data Model: 学习事实漏斗

**Feature**: 017-learning-fact-funnel
**Spec**: [spec.md](./spec.md)
**Research**: [research.md](./research.md)

## 实体总览

本功能**不新增业务表**。观察结果是只读投影。

| 实体 | 持久化 | 用途 |
|---|---|---|
| 访问权生效记录 | 复用 `course_entitlements` | 进入队列、来源、窗口起算 |
| 课节进度 | 复用 `lesson_progresses` | 首次打开、有效进度、试看打开 |
| 学习记录 | 复用 `course_enrollments` | 完成课程 |
| 课节 | 复用 `lessons` + `chapters` | 归属课程、是否试看 |
| 购买订单 | 复用 `orders` | 订单对照 |
| 课程发布消息 | 复用 `notification_dispatches` | 发布触达对照 |
| 学习事实漏斗（投影） | 不落库 | GET 响应 |

## 复用表与观察字段

时区一律 `Asia/Shanghai`。比较用服务端时间。

### course_entitlements — 进入队列

| 字段 | 观察用法 |
|---|---|
| `learner_id`, `course_id` | 学员-课程一行进入队列 |
| `source` | `free` / `purchase` / `activation_code` |
| `created_at` | 访问权生效时刻；窗口 = `[created_at, created_at + window_days)` 按自然日 |
| `status`, `revoked_at` | 取消免费加入不删进入队列；后继阶段只计取消前、且晚于 `created_at` 的事件 |

同一学员同一课在窗口内若有多行历史（取消后再加入），进入队列按**窗口内第一次生效**计 1，来源取该次。

### lesson_progresses — 打开与有效进度

通过 `lessons` → `chapters.course_id` 归属课程。

| 字段 | 观察用法 |
|---|---|
| `opened_at` | 首次打开；空则回退 `created_at` |
| `completed`, `completed_at` | `completed = 1` 且 `completed_at` 在窗口内且 `>=` 生效时刻 → 有效进度 |
| `learner_id`, `lesson_id` | 去重到学员 |

试看对照：`lessons.is_preview = 1` 且打开时刻该学员对该课没有已生效访问权（无行，或该课访问权 `created_at` 晚于打开时刻）。

### course_enrollments — 完成课程

| 字段 | 观察用法 |
|---|---|
| `completed_at` | 非空且落在生效时刻与窗口结束之间 |

`created_at` / `progress_percent` 不单独构成漏斗阶段。

### orders — 订单对照

| 字段 | 观察用法 |
|---|---|
| `course_id` | 本课 |
| `status` | 至少统计 `succeeded`；可选列出其他支付结果人数/笔数 |
| `succeeded_at` 或 `created_at` | 成功单用成功时刻落入窗口；其他状态用创建时刻 |

订单对照**不是**漏斗阶段，不得并入完成课程。

### notification_dispatches — 发布触达对照

| 字段 | 观察用法 |
|---|---|
| `type` | 仅 `course_published` |
| `resource_id` | 课程 id |
| `recipient_count` | 触达人数合计 |
| `created_at` | 是否落入所选报告日界（与漏斗窗口独立标明） |

与进入队列人数分区，不得当作首次打开。

## 投影：学习事实漏斗

一次 GET 的计算结果，不持久化。

| 字段 | 含义 |
|---|---|
| `course_id` | 课程 |
| `window_days` | 7 / 30 / 90 |
| `source` | `all` / `free` / `purchase` / `activation_code` |
| `generated_at` | 计算完成时刻 (ISO-8601) |
| `disclaimer` | 固定事实转化声明 |
| `stages.entitled` | 进入队列人数 |
| `stages.first_opened` | 生效后首次打开课节人数（前一阶段子集） |
| `stages.valid_progress` | 生效后有效进度人数（前一阶段子集） |
| `stages.completed` | 生效后完成课程人数（前一阶段子集） |
| `pending.in_window` | 窗口未结束且未打开课节 |
| `pending.window_elapsed` | 窗口已结束仍未打开课节 |
| `trial` | 试看对照 |
| `orders` | 订单对照 |
| `publish_reach` | 发布触达对照 |

### 阶段子集不变量

```
completed ⊆ valid_progress ⊆ first_opened ⊆ entitled
```

违反则视为实现错误，不得用对照区数字填洞。

### 待开始划分

对 `entitled` 且尚未 `first_opened` 的学员：

- `now < window_end` → `pending.in_window`（禁止称流失）
- 否则 → `pending.window_elapsed`（称窗口内未转化，仍禁止称流失）

`pending.in_window + pending.window_elapsed + first_opened = entitled`

## 校验规则

- `window_days` 只允许 7、30、90；缺省 30。
- `source` 只允许枚举；`all` 时各来源进入队列之和必须等于 `entitled`。
- 分母为零时转化率为 `null`，展示“—”。
- 课程无有效课节时后三阶段为 0，并带 `no_effective_lesson` 说明，不把结构问题写成未转化。
- 数据范围外课程：与课程学员列表相同，返回 NOT_FOUND / FORBIDDEN，不返回空漏斗冒充无数据。
- 本投影的计算不得写入上述任何表。

## 索引（可选，只读友好）

若单课聚合超过验收等待，仅允许新增：

- `course_entitlements (course_id, created_at, source)`
- 已有 `lesson_progresses (learner_id, lesson_id)` unique 足够按学员取打开/完成

禁止为报表给 `lesson_progresses` 加触发器或计数列。

## 状态

漏斗本身无状态机。被观察对象沿用既有状态：

- 访问权 `active` / `revoked`
- 课节进度 `completed` 0→1 单调（进度重置走既有管理动作，不重置漏斗起点）
- 订单支付结果既有枚举
