# Implementation Plan: API 十万级规模扩展

**Branch**: `008-api-scale-100k` | **Date**: 2026-09-01 | **Spec**: [spec.md](./spec.md)

**Input**: Feature specification from `/specs/008-api-scale-100k/spec.md`

**Note**: 后端专注 `apps/api` + `docker/api` + `compose.yaml` + `packages/contracts`（dispatch 字段）；可选 admin 展示 fan-out 进度。无学习端必改项。

## Summary

在十万级学员规模下，将公告 fan-out、push、支付回调、定时 handler 迁入 **Redis 队列**；**Token 反向索引** 替代 SCAN kick；**Redis 未读计数** 与 **首页读缓存** 降低 MySQL 压力；Docker 安装 **ext-event**、调 worker / 连接池、Nginx 媒体直出；压测脚本升级并发门禁。

## Technical Context

**Language/Version**: PHP 8.4（Webman 2.2）；Workerman 5.1

**Primary Dependencies**:
- 新增：`webman/redis-queue:~2.1`
- 既有：`webman/redis`、`webman/think-orm`、`webman/push`、`workerman/crontab`

**Storage**: MySQL 8（`notification_dispatches` 扩展列）；Redis（队列 + 计数 + 缓存 + token 索引）

**Testing**: PHPUnit（队列 consumer、token 索引、缓存、async notify）；扩展 `tests/perf/`

**Target Platform**: Docker Compose 多副本 api + Nginx LB（文档化）

**Project Type**: Monorepo — 主改 `apps/api`；`packages/contracts` 可选扩展

**Performance Goals**:
- SC-001：1 万 fixture 公告 API p95 <3s
- SC-002：kick <1s（无 SCAN 主路径）
- SC-003：首页缓存 smoke p95 <500ms
- SC-004：500+200 并发压测 p95 <2s

**Constraints**:
- think-orm 唯一 ORM；审计仍走 service `writeAudit`
- 令牌 fail-closed 语义不变
- 队列至少一次 + 业务幂等
- Redis 扩展用途限定：队列、未读计数、TTL 读缓存

**Scale/Scope**: 6 用户故事、14 FR；预计 ~35 源文件；1 迁移；1 新 Composer 依赖

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

| 门禁 | 结论 | 说明 |
|------|------|------|
| I. 容器即运行契约 | PASS | queue consumer 在 `config/plugin/.../process.php` |
| II. 稳定兼容且可复现 | PASS | 锁定 `webman/redis-queue:~2.1` |
| III. 契约优先 | PASS | 可选 Zod 扩展 dispatch 字段 |
| IV. 数据变更安全可追溯 | PASS | Phinx 迁移 + backfill |
| V. 质量内建 | PASS | PHPUnit 同 commit |
| VI. 令牌鉴权 | PASS | Token 索引不改变对外语义 |
| Redis 使用边界 | **AMEND** | 本特性授权队列/计数/读缓存；见 research R1 |
| 双前端独立构建 | PASS | API-only；admin UI 可选 |

**Phase 1 复查**: consumer 与 HTTP 共享 Service 层；无 controller 内散写队列逻辑。

## Project Structure

### Documentation

```text
specs/008-api-scale-100k/
├── spec.md
├── plan.md
├── research.md
├── data-model.md
├── tasks.md
├── quickstart.md
├── contracts/
│   └── queue-and-infra.md
└── checklists/
    └── requirements.md
```

### Source Code

```text
apps/api/
├── composer.json                          # + webman/redis-queue
├── config/
│   ├── process.php                        # reusePort, pool env
│   ├── think-orm.php                      # pool from env
│   ├── redis.php
│   └── plugin/webman/redis-queue/         # plugin config + process
├── database/migrations/
│   └── 20260901000001_add_fan_out_to_notification_dispatches.php
├── app/
│   ├── queue/
│   │   ├── NotificationFanOutConsumer.php
│   │   ├── PushNotificationConsumer.php
│   │   ├── PaymentNotifyConsumer.php
│   │   └── ScheduledTaskConsumer.php
│   ├── service/
│   │   ├── NotificationDispatchService.php   # async send
│   │   ├── MessageService.php                  # unread counter
│   │   ├── PushNotificationService.php         # triggerOnly, singleton Api
│   │   ├── TokenService.php                    # index + kick
│   │   ├── HomeService.php                     # cache
│   │   └── UnreadCounterService.php            # new (optional)
│   ├── controller/internal/PaymentNotifyController.php
│   ├── process/ScheduledTaskRunner.php
│   └── support/cache/HomeCache.php             # optional helper
├── tests/
│   ├── NotificationFanOutQueueTest.php
│   ├── TokenIndexKickTest.php
│   ├── HomeCacheTest.php
│   ├── UnreadCounterTest.php
│   ├── PaymentNotifyAsyncTest.php
│   └── perf/load-smoke.sh
docker/api/Dockerfile                        # ext-event
compose.yaml                                 # env vars, nginx media note
packages/contracts/                          # optional dispatch fields
```

## Implementation Phases (映射 tasks.md)

| Phase | 内容 | 可独立合并 |
|-------|------|------------|
| P1 | ext-event、worker/pool、reusePort、.env | PR-1 |
| P2 | redis-queue 插件 + consumer 骨架 | PR-2 |
| P3 | Fan-out 异步 + 迁移 + dispatch 字段 | PR-3 |
| P4 | Token 索引 + kick | PR-4 |
| P5 | Unread 计数 + push 去 COUNT | PR-5 |
| P6 | Home 读缓存 + 失效 | PR-6 |
| P7 | Payment + Scheduled 入队 | PR-7 |
| P8 | Nginx 媒体 + health + perf 脚本 | PR-8 |

## Complexity Tracking

| 违规 / 风险 | 为何需要 | 拒绝更简单方案的原因 |
|-------------|----------|----------------------|
| Redis 队列 | 十万 fan-out | 同步 chunk 仍阻塞数分钟 |
| Token 索引 | kick 毫秒级 | SCAN 十万键秒级～分钟级 |
| 未读 Redis 计数 | 十万次 COUNT | DB 聚合不可扩展 |
| 读缓存 | 5 万+ 日读 | 单表查询可撑但浪费连接 |
