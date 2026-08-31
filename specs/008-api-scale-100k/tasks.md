---
description: "Task list for API 100k scale"
---

# Tasks: API 十万级规模扩展

**Input**: Design documents from `/specs/008-api-scale-100k/`

**Prerequisites**: plan.md, spec.md, research.md, data-model.md, contracts/, quickstart.md

**Design baseline**: Webman 2.2 + PHP 8.4；think-orm 唯一 ORM；003 通知 / 004 定时任务已实现；本特性 supersede 003 R4 同步 fan-out。

**Tests**: 每个 Phase 同 commit 提交 PHPUnit；perf 脚本在 P8 验收。

**Organization**: Phase 1–8 对应可独立回滚 PR；Phase 2 阻塞 3–7。

## Format: `[ID] [P?] [Story] Description`

---

## Phase 1: 基础设施调参 (PR-1) 🎯

**Purpose**: worker、连接池、事件循环、环境变量文档

**Independent Test**: `WEBMAN_WORKERS=4` 启动后 4 HTTP worker；`php -m` 含 event；池配置读取 env

- [ ] T001 [P] Install `ext-event` in `docker/api/Dockerfile` (`pecl install event`, enable)
- [ ] T002 [P] Add pool env wiring in `apps/api/config/think-orm.php` and `apps/api/config/redis.php` (`DB_POOL_MAX`, `REDIS_POOL_MAX`)
- [ ] T003 Update `apps/api/config/process.php`: `reusePort` true when `getenv('WEBMAN_REUSE_PORT')` or Linux production default
- [ ] T004 [P] Extend `.env.example` and `compose.yaml` with `WEBMAN_WORKERS`, `QUEUE_CONSUMERS`, pool vars per `contracts/queue-and-infra.md`
- [ ] T005 [P] Document十万级建议 replica × worker 表 in `specs/008-api-scale-100k/quickstart.md` §Sizing

**Checkpoint**: `make rebuild-api` 成功；容器 `php -m | grep event`

---

## Phase 2: Redis 队列插件 (PR-2) 🎯

**Purpose**: 安装 consumer 进程骨架

**Independent Test**: 投递测试 job 被 consumer 消费并写日志

- [ ] T006 Run `composer require -W webman/redis-queue:~2.1` in `apps/api/composer.json`
- [ ] T007 [P] Configure `apps/api/config/plugin/webman/redis-queue/` (redis connection, `QUEUE_CONSUMERS` count)
- [ ] T008 [P] Register queue consumer process in plugin `process.php` with `count => getenv('QUEUE_CONSUMERS')`
- [ ] T009 Create stub `apps/api/app/queue/QueueNames.php` constants for four queue names
- [ ] T010 [P] Add `apps/api/tests/QueueSmokeTest.php` — send ping job, assert consumed (test consumer or inline)

**Checkpoint**: `php start.php start` 含 queue consumer 进程；smoke test 绿

---

## Phase 3: 公告异步 Fan-out (PR-3) — US1 🎯 MVP

**Goal**: 发送公告/站内信 HTTP ≤3s；后台 fan-out 进度字段

**Independent Test**: 见 spec US1；`NotificationDispatchTest` 或新 `NotificationFanOutQueueTest`

- [ ] T011 Create migration `apps/api/database/migrations/20260901000001_add_fan_out_to_notification_dispatches.php` per `data-model.md` (backfill historical → completed)
- [ ] T012 Implement `apps/api/app/queue/NotificationFanOutConsumer.php` per `contracts/queue-and-infra.md`
- [ ] T013 Refactor `apps/api/app/service/NotificationDispatchService.php`: remove sync `fanOut` from HTTP path; enqueue job; set `fan_out_status=pending`
- [ ] T014 [P] Extend `shapeDispatch()` / `show()` with fan-out fields
- [ ] T015 [P] Optional: extend `packages/contracts` admin notification schema + test for fan-out fields
- [ ] T016 Implement `apps/api/tests/NotificationFanOutQueueTest.php` (InMemoryRedis + queue stub or sync consumer invoke)
- [ ] T017 Update `apps/api/tests/NotificationDispatchTest.php` for async semantics (immediate pending, consumer completes)

**Checkpoint**: `make migrate`；公告测试绿；1k 学员 fixture API 返回 <3s

---

## Phase 4: Token 索引 (PR-4) — US2

**Goal**: kick 无 SCAN 主路径

**Independent Test**: `TokenIndexKickTest` — issue tokens, kickAll/kickFamily, verify keys deleted <1s

- [ ] T018 Extend `apps/api/app/service/TokenService.php`: `mint`/`rotate` maintain account/family SETs
- [ ] T019 Rewrite `kickAll` / `kickFamily` to use SET members; gate SCAN behind `TOKEN_KICK_ALLOW_SCAN_FALLBACK`
- [ ] T020 [P] On `del` token key, remove from family SETs (or kick path bulk DEL)
- [ ] T021 Implement `apps/api/tests/TokenIndexKickTest.php`
- [ ] T022 [P] Update `apps/api/tests/AuthTokenTest.php` if kick paths changed

**Checkpoint**: kick 测试绿；无生产路径 `scanKeys` 调用（除 fallback flag）

---

## Phase 5: 未读计数 (PR-5) — US4

**Goal**: push 无 COUNT；emit/markRead 维护计数器

**Independent Test**: `UnreadCounterTest`

- [ ] T023 Create `apps/api/app/service/UnreadCounterService.php` (INCR/DECR/get/rebuildFromDb)
- [ ] T024 Update `apps/api/app/service/MessageService.php`: INCR on emit; delegate mark-read to counter
- [ ] T025 Update `apps/api/app/service/PushNotificationService.php`: `triggerOnly()` without COUNT; reuse singleton `Api`
- [ ] T026 Update `apps/api/app/queue/PushNotificationConsumer.php` — trigger only (INCR in emit)
- [ ] T027 Wire `NotificationFanOutConsumer` to enqueue push jobs after chunk insert
- [ ] T028 [P] Update learner mark-read controller/service paths for DECR
- [ ] T029 Implement `apps/api/tests/UnreadCounterTest.php`
- [ ] T030 [P] Update `apps/api/tests/NotificationContractTest.php` for counter semantics

**Checkpoint**: push 路径无 `count()` 查询；未读 API 与列表一致

---

## Phase 6: 首页读缓存 (PR-6) — US3

**Goal**: siteIntro / categoryTree / banners Redis TTL 缓存

**Independent Test**: `HomeCacheTest` — second call skips DB; admin update invalidates

- [ ] T031 Create `apps/api/app/support/cache/HomeCache.php` or methods in `HomeService`
- [ ] T032 Update `apps/api/app/service/HomeService.php` with cache read/write/TTL env
- [ ] T033 [P] Invalidate in `BannerService` admin writes, category admin service, site profile admin
- [ ] T034 Implement `apps/api/tests/HomeCacheTest.php`

**Checkpoint**: 1000 次 home smoke p95 下降（日志或测试 spy）

---

## Phase 7: 支付与定时任务入队 (PR-7) — US5

**Goal**: notify 快速 200；crontab 不跑长 handler

**Independent Test**: `PaymentNotifyAsyncTest`；scheduled job 入队

- [ ] T035 Implement `apps/api/app/queue/PaymentNotifyConsumer.php`
- [ ] T036 Update `apps/api/app/controller/internal/PaymentNotifyController.php` async dispatch (`PAYMENT_NOTIFY_ASYNC`)
- [ ] T037 Implement `apps/api/app/queue/ScheduledTaskConsumer.php`
- [ ] T038 Update `apps/api/app/process/ScheduledTaskRunner.php` to enqueue instead of sync `executor->run`
- [ ] T039 [P] Implement `apps/api/tests/PaymentNotifyAsyncTest.php`
- [ ] T040 [P] Update `apps/api/tests/ScheduledTaskRunnerTest.php` for enqueue behavior

**Checkpoint**: fake notify 立即 200；订单稍后 succeeded

---

## Phase 8: 部署与压测 (PR-8) — US6

**Goal**: Nginx 媒体、health、压测脚本

**Independent Test**: 媒体 URL 不经 PHP；health 含 queue；perf 文档可执行

- [ ] T041 [P] Add Nginx media `alias` in `docker/web/nginx.conf` or api sidecar doc; update `compose.yaml` volumes
- [ ] T042 Extend `apps/api/app/controller/HealthController.php` with queue check
- [ ] T043 Create `apps/api/tests/perf/load-smoke.sh` (k6/wrk) per SC-004
- [ ] T044 [P] Update `README.md` perf section: 200→500 concurrent gate note
- [ ] T045 [P] Optional admin: show `fan_out_status` in `apps/admin` notification detail view

**Checkpoint**: quickstart §Verification 全部可执行

---

## Dependencies

```text
Phase 1 ─┬─► Phase 2 ─► Phase 3 ─► Phase 5 (push jobs)
         │                    │
         │                    └─► Phase 7 (scheduled uses queue)
         └─► Phase 4 (parallel after P2)
Phase 6 parallel after P1
Phase 8 after P2–7
```

## MVP Scope (最小可上线十万级)

**P1 + P2 + P3 + P4**（基础设施 + 队列 + 公告异步 + token kick）

公测前强烈建议加上 **P5 + P6**（未读 + 读缓存）。

---

## 审查清单

- [ ] 所有新增 service 写操作保留 audit 约定
- [ ] 队列 consumer 内异常不崩溃进程（log + failed status）
- [ ] `tasks/lessons.md` 追加 Lesson（合并后 `/lesson-learned`）
