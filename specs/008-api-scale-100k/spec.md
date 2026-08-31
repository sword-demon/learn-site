# 功能规格：API 十万级规模扩展

**功能分支**：`008-api-scale-100k`

**创建日期**：2026-09-01

**状态**：草稿

**输入**：十万级注册学员规模下的 API 高并发与异步化改造；在现有 Webman 架构上引入任务队列、Token 索引、读缓存与部署调参，使公告广播、鉴权踢人、首页读路径与支付回调在 1k～3k 峰值在线下可预期运行。

**前置规格**：`003-admin-notifications`（同步 fan-out 决策在十万级下由本规格 supersede）、`004-admin-crontab-tasks`、`001-personal-learning-site`（200 并发观测项升级为压测门禁）。

## 用户场景与测试（必填）

### 用户故事 1：管理员发送公告不阻塞 API（优先级：P1）

具备通知管理权限的管理员发送公告或站内信后，HTTP 请求应在数秒内返回（仅完成发送记录创建与任务入队），不因十万级 fan-out 长时间占用 HTTP worker。后台可查询 fan-out 进度（pending / running / completed / failed）。学员侧行为与 `003` 一致：收件箱出现消息、在线学员收到 push、未读计数正确。

**优先级原因**：十万级下同步 fan-out 会使 API 数分钟不可用，是规模化的首要阻塞点。

**独立测试**：预置 1 万条 active 学员 fixture（或压测种子），管理员发送公告后 API 在 ≤3 秒内返回；fan-out 在后台完成；抽样学员收件箱有对应行；在线学员在可接受延迟内收到 push。

**验收场景**：

1. **前提** 系统有 ≥1 万 active 学员，**当** 管理员发送公告，**则** 创建 `notification_dispatches` 且 `fan_out_status=pending`，HTTP 在 3 秒内返回成功，不等待全部 insert 完成。
2. **前提** fan-out 任务运行中，**当** 管理员查询该 dispatch 详情，**则** 可见 `fan_out_status` 与已投递数量（`fan_out_done_count`）更新。
3. **前提** fan-out 完成，**当** 任意目标学员打开消息列表，**则** 可见该公告且未读状态正确。
4. **前提** 重复投递同一 fan-out 任务（模拟 worker 重试），**当** 消费完成，**则** 不产生重复收件箱行（`idempotency_key` 幂等）。

---

### 用户故事 2：鉴权踢人与 Token 校验可扩展（优先级：P1）

管理员踢下线某学员或某登录 family 时，操作在秒级内完成，不扫描全库 Redis 键。十万级活跃 token 规模下，单次 kick 不得阻塞 worker 数十秒。日常 access token 校验保持 fail-closed 语义不变。

**优先级原因**：`TokenService::kick` 当前使用 `SCAN access:*`，十万键规模下不可接受。

**独立测试**：预置某 account 下 3 个 family 各 2 个 token 键，执行 kickAll / kickFamily，验证 Redis 中对应键删除且校验失败；操作耗时 <1 秒（fixture 规模下）。

**验收场景**：

1. **前提** 学员有有效 access token，**当** 管理员 kick 该学员全部会话，**则** 现有 access / refresh 失效，下次请求返回 `TOKEN_EXPIRED`。
2. **前提** 学员有多个 family，**当** kick 单个 family，**则** 仅该 family 失效，其他 family 仍有效。
3. **前提** Redis 不可用，**当** 校验 access token，**则** 仍 fail-closed（与 FR-092 一致）。

---

### 用户故事 3：首页与目录读路径抗压（优先级：P1）

未登录或已登录学员高频访问首页聚合（站点介绍、分类树、轮播）时，MySQL 查询压力可控。缓存命中时 p95 延迟显著低于直查库。管理端变更站点信息、分类或轮播后，学习端在约定 TTL 内看到更新。

**优先级原因**：十万级 DAU 下首页是最高频读路径之一。

**独立测试**：连续 1000 次 `GET /api/learner/v1/home`（或等价聚合端点），缓存开启后 p95 <500ms（单实例 smoke）；管理端改 banner 后 ≤TTL 内学习端可见新数据。

**验收场景**：

1. **前提** 缓存为空，**当** 首次请求首页数据，**则** 从 DB 读取并写入缓存。
2. **前提** 缓存有效，**当** 重复请求，**则** 不重复执行相同 DB 聚合查询（可通过日志或测试 spy 验证）。
3. **前提** 管理员更新轮播图，**当** 保存成功，**则** 相关缓存键失效或更新，学习端在 ≤5 分钟内看到新轮播（可配置 TTL 上限）。

---

### 用户故事 4：未读计数与消息列表可扩展（优先级：P1）

学员消息未读数通过轻量接口与 push 载荷返回，不在每条 push 前对百万级 `learner_notifications` 表做 `COUNT`。标记已读后未读数单调递减且幂等。

**优先级原因**：十万级 × 人均数十条消息 → 表达百万行；逐条 COUNT 在 fan-out 时会放大为十万次查询。

**独立测试**：学员有 3 条未读，emit 第 4 条后 unread +1；标记 1 条已读后 unread -1；push 载荷 `unread_count` 与 `GET unread-count` 一致。

**验收场景**：

1. **前提** 学员有 N 条未读，**当** 调用 unread-count API，**则** 返回 N（与列表筛选一致）。
2. **前提** 新消息 emit，**当** push 触发，**则** 载荷含正确 `unread_count`，且无额外全表 COUNT。
3. **前提** 并发两次标记同条已读，**当** 均成功，**则** 未读数只减 1。

---

### 用户故事 5：支付回调快速应答（优先级：P2）

内部 / 未来微信支付回调解析成功后立即返回 200，订单状态变更在队列 consumer 中幂等执行。重复回调不产生双 grant。

**优先级原因**：支付渠道对回调超时敏感；十万级促销时重试风暴会拖垮同步路径。

**独立测试**：POST fake notify succeeded，响应 <1 秒；consumer 完成后订单为 succeeded 且 entitlement 唯一。

**验收场景**：

1. **前提** 订单 pending，**当** 支付回调 succeeded，**则** HTTP 立即 200，订单稍后变为 succeeded。
2. **前提** 订单已 succeeded，**当** 重复相同回调，**则** 仍 200 且 entitlement 不重复。

---

### 用户故事 6：运维可水平扩展与观测（优先级：P2）

生产部署可通过环境变量调整 worker 数、连接池、队列 consumer 数；API 可多副本 + 负载均衡。提供队列深度与健康检查扩展项，支撑十万级运维。

**独立测试**：`WEBMAN_WORKERS` 与池配置生效；`GET /health` 包含 queue 可达性（或 dedicated metrics 端点文档化）；`make perf` 或文档化 k6 脚本达到约定并发门槛。

**验收场景**：

1. **前提** 设置 `WEBMAN_WORKERS=4`，**当** 重启 api 容器，**则** 进程数为 4（另加 scheduled_tasks_runner、push 等固定进程）。
2. **前提** 队列积压 > 阈值，**当** 运维查看日志或指标，**则** 可识别积压与消费速率（文档化观测方式）。

---

### 边界场景

- 公告发送时 fan-out 任务失败：dispatch 标记 `fan_out_status=failed`，可人工重试入队；已成功的 chunk 不重复插入。
- 队列 consumer 与 HTTP worker 共享 MySQL：连接池上限按进程数汇总，不得超过 MySQL `max_connections` 规划。
- Redis 队列不可用：公告 API 返回明确错误，不 silent 丢消息；已创建的 dispatch 可重试 fan-out。
- 未读 Redis 计数与 DB 漂移：以 DB 为真相源提供修复任务（admin 或 scheduled reconcile，首版可文档化手动修复命令）。
- Token 索引与旧键：迁移期双写索引；kick 同时清理索引与遗留无索引键（一次性迁移脚本或 lazy 兼容）。
- 多 API 实例：缓存失效通过 Redis pub/sub 或统一 TTL，避免长期脏读。
- 定时任务 handler 执行时间超过 crontab 间隔：投递队列执行，与 `004` skip 策略一致。

## 需求（必填）

### 功能需求

- **FR-001**：必须引入基于 Redis 的异步任务队列（`webman/redis-queue` 或等价），独立 consumer 进程消费任务。
- **FR-002**：`NotificationDispatchService` 发送公告 / 站内信时，必须仅同步创建 dispatch 记录并入队 `NotificationFanOutJob`；禁止在 HTTP 请求内完成全量 fan-out。
- **FR-003**：`notification_dispatches` 必须增加 `fan_out_status`（`pending|running|completed|failed`）与 `fan_out_done_count`；可选 `fan_out_error` 摘要。
- **FR-004**：`NotificationFanOutJob` 必须按 chunk（默认 250）批量 `insertAll`，每 chunk 后批量投递 `PushNotificationJob` 或等价批量 push。
- **FR-005**：`PushNotificationJob` 必须使用 Redis 未读计数器（`unread:{learnerId}`），禁止每条 push 前 `COUNT(*)` 全表查询。
- **FR-006**：`MessageService::emit` 与标记已读必须维护未读计数器（INCR / DECR，下限 0）；列表查询仍以 DB 为准。
- **FR-007**：`TokenService` 签发 / 轮换 token 时必须维护 `account:{id}:families` 与 `family:{id}:keys` 索引；`kickAll` / `kickFamily` 必须按索引删除，禁止 `SCAN access:*` / `refresh:*` 作为生产路径。
- **FR-008**：`HomeService`（或等价聚合）对 `siteIntro`、`categoryTree`、`banners` 必须使用 Redis 缓存 + TTL；管理端写操作必须触发失效。
- **FR-009**：`PaymentNotifyController` 解析成功后必须入队 `PaymentNotifyJob` 并立即返回 200（fake 与 future wechat 路径统一模式）。
- **FR-010**：`ScheduledTaskExecutor` 自动触发的 handler 必须入队执行（手动触发可保持同步或同样入队，实现阶段二选一并文档化）。
- **FR-011**：Docker 镜像必须安装 `ext-event`；`config/process.php` 在 Linux 生产环境默认 `reusePort=true`（开发环境可关闭）。
- **FR-012**：`think-orm` 与 `webman/redis` 连接池 `max_connections` 必须与 `WEBMAN_WORKERS` 联动调优（文档与 `.env.example` 给出十万级建议值）。
- **FR-013**：媒体文件 `GET /api/media/*` 必须由 Nginx 直出或 CDN，禁止经 PHP worker 读盘（Compose / 部署文档更新）。
- **FR-014**：必须提供压测脚本或扩展 `apps/api/tests/perf/timing.sh`，验证 500 并发目录读 + 200 并发进度上报 p95 <2s（多实例或调参后作为合并门禁）。

### 非功能需求

- **NFR-001**：目标规模 — 10 万注册学员；峰值在线 1k～3k；峰值 API QPS 500～2000（读多写少）。
- **NFR-002**：公告 API 同步阶段 ≤3 秒（不含 fan-out）。
- **NFR-003**：单实例 16 worker + 2 副本为生产起步配置；须支持水平扩展。
- **NFR-004**：队列任务至少一次投递；业务层幂等（现有 `idempotency_key`、订单状态机）。
- **NFR-005**：宪章 Redis 边界扩展：允许 Redis 用于**任务队列、未读计数、热点读缓存**；仍禁止无 TTL 的业务数据长期堆在 Redis。

## 成功标准

- **SC-001**：1 万学员 fixture 下发送公告，API p95 <3s，且 10 分钟内 fan-out 完成（单 consumer 基准）。
- **SC-002**：kickAll 在 1 万 token 键 fixture 下 <1s（无 SCAN 全表路径）。
- **SC-003**：首页聚合缓存命中后，smoke 1000 请求 p95 <500ms（单实例、无并发竞争）。
- **SC-004**：压测门禁：500 并发目录 + 200 并发进度，p95 <2s，成功率 ≥99%（文档化环境）。
- **SC-005**：现有 PHPUnit / 集成测试全绿；新增队列与 token 索引测试覆盖核心路径。

## 假设

- 十万级为**注册学员数**；同时在线远低于注册数；push 在线连接数按 DAU 估算扩容。
- 学员侧消息保留 2 个月策略不变（`NotificationCleanupService` 改走队列消费）。
- 管理端 UI 可选展示 fan-out 进度（首版 API 字段就绪即可，UI 可后续迭代）。
- 不引入 Kafka / RabbitMQ；Redis 队列为唯一异步总线。
- MySQL 单主起步；读写分离不在本规格范围。

## 范围外

- 学习端 / 管理端 UI 大改（除可选 fan-out 状态展示）。
- 协程全面改造所有 Service。
- CDN 采购与多区域部署。
- `learner_notifications` 分库分表（仅 batch 清理 + 索引优化）。
