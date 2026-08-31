# Research: 008-api-scale-100k

## R1: 异步任务队列选型

**Decision**: 采用官方插件 `webman/redis-queue:~2.1`（底层 `workerman/redis-queue`）。

**Rationale**:
- 与 Webman 2.2 / Workerman 5.1 同栈；文档：https://www.workerman.net/doc/webman/queue/redis.html
- 独立 `config/plugin/webman/redis-queue/process.php` consumer 进程，与 HTTP worker 隔离。
- `Client::send()` 异步投递适合 HTTP 请求路径；consumer 内同步处理业务。
- 003 规格在 ≤1000 学员下选择同步 fan-out；十万级 supersede 该决策。

**宪章 Redis 边界**:
- 003 R1 禁止「业务缓存/队列」是针对当时规模与 MVP 范围。
- 本特性显式扩展：Redis 可用于**任务队列、未读计数、热点读缓存（带 TTL）**。
- push 插件内部 Redis 仍属基础设施；与队列共用 Redis 实例，使用不同 key 前缀。

**Alternatives considered**:

| 方案 | 弃用原因 |
|------|----------|
| 继续同步 fan-out + 加 worker | 十万 insert + 十万 push 仍占满 worker 数分钟，无法达标 |
| MySQL 作队列表 | 轮询延迟高、与 004 crontab 重叠、锁竞争 |
| Kafka / RabbitMQ | 运维复杂度与当前单体 Compose 不匹配 |

**集成要点**:
- `composer require -W webman/redis-queue:~2.1`
- Consumer 进程数 `QUEUE_CONSUMERS` 环境变量，默认 2。
- 失败重试：`onConsumeFailure` 记录日志 + 有限重试；fan-out 依赖 `idempotency_key` 幂等。
- 监控：日志字段 `queue.depth` / `job.name` / `duration_ms`；可选 Redis `LLEN` 队列长度。

---

## R2: 公告 fan-out 任务拆分

**Decision**: 两级任务 — `NotificationFanOutJob(dispatch_id)` → chunk insert → `PushNotificationJob` 批量或逐条。

**Rationale**:
- HTTP 仅 `insert dispatch` + `Client::send(FanOut)` + 返回。
- FanOut consumer：加载 recipient 列表（公告 `activeLearnerIds`；站内信自 `notification_dispatch_recipients`），`fan_out_status=running`，chunk 250 `insertAll`，更新 `fan_out_done_count`，完成后 `completed`。
- Push consumer：INCR unread、trigger push（复用单例 `Api` 客户端）。

**Alternatives considered**:

| 方案 | 弃用原因 |
|------|----------|
| 单任务内 insert + push 全做完 | 单 job 过长，失败重试粒度粗 |
| 每学员一条 FanOut job | 十万条消息元数据压 Redis |

**进度字段**: `notification_dispatches.fan_out_status`、`fan_out_done_count`、`fan_out_error`（VARCHAR 500）。

---

## R3: Token 反向索引

**Decision**: 签发 / 轮换时维护：

```text
account:{accountId}:families     → SET family_id
family:{familyId}:access_keys    → SET key_suffix (access:{hash} 后缀)
family:{familyId}:refresh_keys   → SET key_suffix
```

`kickAll`: `SMEMBERS account:{id}:families` → 对每个 family 删 keys + revoked 标记。  
`kickFamily`: 直接删该 family 的 key sets。

**Rationale**:
- O(家族数) 而非 O(全站 token 数)。
- 十万用户 × 平均 1～2 family → kick 毫秒级。

**迁移**:
- 新 token 双写索引。
- 旧 token 无索引：verify 仍靠 `access:{hash}` 直查；kick 对无索引 account fallback 一次 SCAN（记录 warning，仅迁移期）。
- 可选一次性 `artisan` 式脚本重建索引（非 MVP 阻塞）。

**Alternatives considered**:

| 方案 | 弃用原因 |
|------|----------|
| 仅 SCAN | 十万键不可接受 |
| JWT 无状态 | 与 FR-092 不透明可吊销令牌冲突 |

---

## R4: 未读计数器

**Decision**: Redis key `unread:{learnerId}` 整数；`emit` +1；`markRead` -1（`max(0, n-1)`）；全量 `markAllRead` 置 0。

**Rationale**:
- 消除 push 路径 COUNT。
- `GET unread-count` 读 Redis；定期或按需与 DB `COUNT WHERE read_at IS NULL` reconcile（scheduled 任务，P2）。

**漂移处理**:
- 标记已读时若 Redis 缺失，从 DB COUNT 回填一次。
- 清理过期通知时批量 DECR 或 rebuild（cleanup handler 扩展）。

---

## R5: 首页读缓存

**Decision**: Redis 字符串（JSON）+ TTL：

| Key | TTL | 失效触发 |
|-----|-----|----------|
| `cache:home:site_intro` | 300s | SiteProfile 更新 |
| `cache:home:category_tree` | 300s | Category CRUD |
| `cache:home:banners` | 60s | Banner CRUD |

**Rationale**:
- 5k DAU × 10 次/日 ≈ 5 万次读；缓存降 DB 90%+。
- 短 TTL 兜底失效遗漏。

**Alternatives considered**:

| 方案 | 弃用原因 |
|------|----------|
| HTTP Cache-Control 仅 | 个性化 home 字段（登录态）使 CDN 复杂 |
| think-cache 插件 | 与 webman/redis 重复；直接用 Redis 更简单 |

---

## R6: 基础设施调参（十万级起步）

| 参数 | 建议值 | 说明 |
|------|--------|------|
| `WEBMAN_WORKERS` | `CPU×2`（单 Pod 8 核 → 16） | HTTP worker |
| `QUEUE_CONSUMERS` | 4～8 | fan-out + push 消费 |
| DB `pool.max_connections` | 30～50 / worker | 与实例数汇总规划 |
| Redis `pool.max_connections` | 20～30 / worker | |
| API 副本 | ≥2 | LB 后水平扩展 |
| `ext-event` | 安装 | Dockerfile `pecl install event` |

**reusePort**: Linux 生产 `true`；macOS 开发可 `false`。

**媒体**: Nginx `location /api/media/` alias 到 uploads 目录；API 路由保留鉴权路径仅管理上传。

---

## R7: 支付回调异步化

**Decision**: `PaymentNotifyController::dispatch` 解析成功后 `Client::send(PaymentNotifyJob)` → 立即 `ok`；consumer 调用现有 `markSucceeded` / `markFailed`（幂等）。

**Rationale**:
- 微信回调要求快速 200。
- 与 fake notify 测试路径统一。

**测试**: fake notify 仍可在 testing 环境同步模式开关（`PAYMENT_NOTIFY_ASYNC=0`）便于单测。

---

## R8: 定时任务入队

**Decision**: `ScheduledTaskRunner` crontab 回调仅 `Client::send(ScheduledTaskJob { task_id })`；consumer 调用现有 `ScheduledTaskExecutor::run`。

**Rationale**:
- 长清理任务不阻塞 runner 事件循环。
- 与 004 `hasActiveRun` 互斥逻辑保留在 executor 内。

---

## R9: 压测与门禁

**Decision**: 新增 `apps/api/tests/perf/load-smoke.sh`（k6 或 `wrk` 脚本，文档化依赖）；README 将 200 并发观测升级为可选 CI 夜间任务；合并门禁保持 `timing.sh` 单用户 95% <2s。

**目标**: 500 并发 `GET categories/courses` + 200 并发 progress PATCH，p95 <2s，≥99% 成功（2 副本 × 16 worker 基准环境）。
