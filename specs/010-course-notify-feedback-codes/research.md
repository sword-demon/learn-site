# Research: 010-course-notify-feedback-codes

## R1: 课程发布通知走既有异步 fan-out, 不新建推送栈

**Decision**: 课程状态首次进入 `published` (含从 `unpublished` 再发布) 后, `CourseService::publishCourse` 同步把课改为已发布, 再调用 `NotificationDispatchService::sendCoursePublished()` 写入 `notification_dispatches` (`type=course_published`, `fan_out_status=pending`) 并 `JobDispatcher` 入队 `notification.fan_out`. HTTP 发布成功不等待全体收件箱写完. 在线提示继续走 `notification.push` + `webman/push` 私有频道.

**Rationale**:
- `003` 已落地消息中心, 已读/未读, `resource_type=course` 跳转; 学习端 `StudentCenterView` 已把 `resource_type=course` 链到 `/courses/{id}`, 且 `resource_available` 在课程非已发布时为 false.
- `008` 已落地 `webman/redis-queue:~2.1`, `NotificationFanOutExecutor` (chunk 250 + 幂等 `idempotency_key`) 与 `PushNotificationConsumer`. 课程发布广播与公告同形, 必须复用, 禁止再引入第二套队列或同步十万级 insert.
- 官方 `webman/push` (https://www.workerman.net/doc/webman/plugin/push.html, https://github.com/webman-php/push): 服务端 `Webman\Push\Api::trigger`, 客户端订阅 `private-*` 频道; 本仓库已用 `private-learner-{accountId}` + 事件 `notification`. 用户明确要求继续用该通道.
- 规格 FR-008: 投递失败不得回滚课程发布. 因此发布路径捕获入队失败, 将 dispatch 标 `failed` 并打日志, **不** 把 `NOTIFICATION_FAN_OUT_QUEUE_FAILED` 抛给发布 API.

**Alternatives considered**:

| 方案 | 弃用原因 |
|------|----------|
| 在 `publishCourse` 内循环 `MessageService::emit` | 阻塞 HTTP worker, 违反 SC-001/008 |
| 新 Redis 频道或邮件/短信 | 规格排除; 宪章与 003 已锁定站内通道 |
| 按分类/收藏定向收件人 | 规格假设「智能」= 自动广播, 首版不做推荐 |

**集成要点**:
- `learner_notifications.kind` 已是 `VARCHAR(32)` (003 迁移), 新增 `course_published` 无需改 inbox 枚举.
- `notification_dispatches.type` 仍是 ENUM, 须迁移加入 `course_published`.
- dispatch 增加可空 `resource_type` / `resource_id`; fan-out 写入 inbox 时复制, 不再写 null.
- `idempotency_key` 沿用 `{dispatch_id}:{learner_id}`. 每次真正发生「进入已发布」新建一条 dispatch, 编辑已发布课不触发.
- 已发布时重复点发布: 现有 `publishCourse` 早返回, 本特性保持该行为, 不补发.

---

## R2: webman/push 高性能路径

**Decision**: 不改 push 插件配置与鉴权. fan-out 每 chunk 后继续投递既有 `notification.push` job; `PushNotificationService` 单例 `Api` 对 `private-learner-{id}` 触发 `notification` 事件, 载荷含 `id/kind/title/unread_count`. 学习端 `apps/web/src/utils/push.ts` + `usePushNotifications` 无需新连接; 仅扩展 kind 展示.

**Rationale**:
- 官方文档: 频道/事件为任意字符串, 私有频道以 `private-` 开头, 订阅走 `/plugin/webman/push/auth`. 003 已把 auth 绑到学员 access token 与 `account_id`.
- 推送是增强, REST `GET /messages` + `unread-count` 为兜底 (FR-007).
- 未读计数走 `UnreadCounterService` (008 Redis INCR), 禁止 fan-out 时对 inbox `COUNT(*)`.

**Alternatives considered**:

| 方案 | 弃用原因 |
|------|----------|
| 一条广播频道 `course-published` | 会把消息打给未登录访客, 且绕过收件箱落库 |
| HTTP 内直接 `Api::trigger` 十万次 | 阻塞发布, 与 008 两级任务设计冲突 |

来源: [webman/push 官方文档](https://www.workerman.net/doc/webman/plugin/push.html), 仓库 `PushNotificationService` / `NotificationFanOutExecutor`.

---

## R3: 失败补投

**Decision**: 为 `fan_out_status IN (failed, pending)` 的 dispatch 增加 `NotificationDispatchService::retryFanOut(dispatchId)` (管理端 `POST /notifications/{id}/retry`, 权限 `notification.manage`). 幂等依赖既有 `idempotency_key` 与 executor 的 `completed` 短路径.

**Rationale**: FR-008 要求可追踪与补投. 008 已有 `fan_out_status` 列, 但管理端 UI 尚未展示. 本特性把该字段纳入课程发布 dispatch 的列表/详情, 并提供显式重试, 避免「下架再发布」制造重复消息.

**Alternatives considered**: 运维手工 redis-cli 重投 (不可审计); 自动无限重试 (可能在持久故障时刷屏).

---

## R4: 激活码存储与形态

**Decision**:
- 表 `activation_code_batches` + `activation_codes`.
- 码文: 16 位 Crockford Base32 (去掉 I/L/O/U), 展示为 `XXXX-XXXX-XXXX-XXXX`; 规范化 = 去横线并大写.
- 落库 `code_hash = SHA-256(normalized)` 全局唯一; 另存 `code_prefix`/`code_suffix` 各 4 位供列表脱敏. **明文只在生成 API 响应里出现一次**.
- 一码一课, 成功兑换一次即终态 `redeemed`. 过期按 `expires_at` 派生, 不单独落 `expired` 行状态 (列表筛选时计算).
- 单批数量 1–1000; `expires_at` 可空, 时区 `Asia/Shanghai`.

**Rationale**: 「激活码」在国内网课产品里默认是唯一许可码, 不是可共用优惠码 (`009` 已覆盖优惠券). 高熵 + 哈希降低库泄漏与枚举风险. 明文只回一次对齐规格 FR-019.

**Alternatives considered**:

| 方案 | 弃用原因 |
|------|----------|
| 一码 N 次 (促销码) | 规格 FR-029 排除; 与优惠券重叠 |
| 明文入库 | 备份/日志泄漏即可盗课 |
| 短 6 位数字 | 可穷举, 与 FR-020 冲突 |

---

## R5: 课程访问权第三来源

**Decision**: `course_entitlements.source` ENUM 增加 `activation_code`; 可空 `activation_code_id` FK. `EntitlementService::grant` 接受该 source, **不** 要求 `order_id`. `CourseEntitlement::isRevocable()` 仍仅 `source=free`. 兑换在同一事务内: 锁码行 → 确认无 active 授权 → grant → 标码 `redeemed`.

**Rationale**:
- 规格: 兑换访问权与支付成功同等, 不可按免费加入取消. 现有 `isRevocable()` 已实现该不变量, 只要不把 activation_code 当 free.
- `grant()` 对已有 active 行幂等返回旧行 — **兑换路径绝不能走这条幂等**: 必须先查 active, 若已有则抛 `ENTITLEMENT_ALREADY_ACTIVE` 且 **不消耗码** (FR-015).
- 学员名单 `CourseStudentService` 与 `CourseStudentDTO.source` 扩展第三值.

**Alternatives considered**: 兑换后写一笔 0 元订单再走 purchase (污染订单与支付对账); 把码当优惠券核销 (与 009 职责冲突).

---

## R6: 兑换并发与限流

**Decision**:
- 兑换事务 `SELECT ... FOR UPDATE` 锁定 `activation_codes` 行 (按 `code_hash`). 并发同一码只有一行成功.
- 复用既有 `RateLimit` 中间件: `POST /api/learner/v1/activation-codes/redeem` → 每学员 (无 token 则 IP) 60 秒 8 次; Redis 不可用时 fail-open, 高熵哈希仍抗穷举.
- 错误码对无效/已兑换/已作废/已过期可区分, 但不反馈「还差几位」之类.

**Rationale**: 中间件已用于登录与领券; 宪章 008 已允许 Redis 做限流类基础设施. 业务正确性靠行锁 + 唯一 hash, 不靠 Redis.

**Alternatives considered**: 服务内 Redis 计数 (与中间件重复); 验证码挑战 (兑换 UX 过重).

---

## R7: 意见反馈与公开评价隔离

**Decision**: 新表 `course_feedbacks`, 与 `reviews` 零共享. 正文经 `HtmlSanitizer::sanitize` (与课程简介同一白名单). 学习端复用 `CheckinPlanEditor` (已是 WangEditor 封装). 管理员详情 `v-html` 渲染已消毒 HTML, 与签到计划/课程预览一致. 提交门槛: 登录 + 该课 **active** 课程访问权. 状态仅 `pending` / `processed`. 不在条目内回复.

**Rationale**: `CONTEXT.md` 的「评价」是公开星级; 规格要求私密运营意见. 签到计划已证明学习端可以安全提交富文本. 长度上限 20_000 字符 (低于简介 200_000, 高于站内信 10_000), 空壳 HTML 用既有 `richHtml.ts` 判空.

**Alternatives considered**: 把反馈写成隐藏评价 (污染评分); 新编辑器库 (无必要); 无访问权也可提交 (垃圾与未购课噪音).

---

## R8: 权限与数据范围

**Decision**: 新增权限码:
- `activation_code.manage` (module `catalog`) — 生成/列表/作废
- `course_feedback.manage` (module `catalog`) — 列表/详情/改处理状态

课程发布通知无新权限, 是 `course.publish` 的副作用. 管理端通知列表扩展只读 `course_published` 类型, 重试走既有 `notification.manage`.

两条新码均再套该课 `DataScopeService::assertCourseAccessibleFromScope`. 超级管理员跳过.

**Rationale**: 规格允许「扩展或新增最小必要权限码」; 与 `coupon.manage` 一样独立, 避免把作废码能力绑死在 `course.manage` 上. 不做更细的只读拆分.

**Alternatives considered**: 全部复用 `course.manage` (无法单独授权运营助理处理反馈); 做 view/manage 两套 (规格排除).

---

## R9: 前端挂载点 (H6)

**Decision**:
- 消息: 只扩展 `StudentCenterView` 的 `kindLabel` / `kindTagType` (`course_published` → 「新课」). 跳转逻辑已存在, 不改 tab 派生.
- 兑换: 新路径 `/me/redeem` 并入 `TAB_BY_PATH` (H6: activeTab 由 `route.path` 派生, `el-tabs` 用 proxy ref). 收费课详情在学员未获权时另给兑换表单 (购买区旁).
- 反馈: 课程详情 `el-tabs` 增加「意见反馈」pane, 仅 `viewer_authorized` 可见.
- 管理端: 课程下新路由 `/courses/:id/activation-codes`, `/courses/:id/feedback`, 与既有 `/courses/:id/students` 并列, 不合并进 CourseEditView (避免单文件继续膨胀).

**Rationale**: Lesson 4 / H6 禁止把多 tab 状态存本地 ref. 兑换是独立入口, 不应塞进优惠券页 (领域词禁止把激活码叫优惠券).

**Alternatives considered**: 兑换放进优惠券 tab (词汇污染); 管理端做顶栏「激活码」全局模块 (码始终从属于一门课).

---

## R10: 领域词与 CONTEXT.md

**Decision**: 实现阶段更新 `CONTEXT.md`:
- **课程访问权** 获得方式增加「激活码兑换」.
- 新增 **激活码**, **兑换**, **课程意见反馈**, **课程发布消息**.
- Avoid: 把意见反馈叫评价; 把激活码叫优惠券/优惠码; 把课程发布消息叫公告或站内信.

`EntitlementService::grant` 的 PHPDoc source union 同步更新.

---

## 已关闭的澄清

无残留 `NEEDS CLARIFICATION`. 规格假设全部落入上述决策.
