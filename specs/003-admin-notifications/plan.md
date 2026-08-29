# Implementation Plan: 管理后台通知与学员消息中心

**Branch**: `003-admin-notifications` | **Date**: 2026-08-29 | **Spec**: [spec.md](./spec.md)

**Input**: Feature specification from `/specs/003-admin-notifications/spec.md`

**Note**: 全栈功能——扩展 `apps/api`（推送、定时任务、迁移、服务）、新增 `apps/admin` 通知模块、增强 `apps/web` 消息中心与实时推送；复用既有 `learner_notifications` 与 `MessageService` 管线。

## Summary

在管理后台新增「通知管理」模块，支持**公告**（全体在册学员广播）与**站内信**（指定学员）。发送后写入 `notification_dispatches` 发送记录，并 fan-out 到 `learner_notifications` 收件箱。学习端在既有消息页展示全部类型，导航显示未读角标；在线学员通过 **webman/push** 私有频道实时感知新消息。**workerman/crontab** 每日分批清理超过 2 个月的学员收件箱记录；后台发送记录长期保留。

## Technical Context

**Language/Version**: PHP 8.4（Webman 2.2）；TypeScript 5.x strict；Vue 3.5；Node.js 22 LTS

**Primary Dependencies**:
- 后端新增：`webman/push`、`workerman/crontab`
- 后端既有：`webman/think-orm`、`webman/redis`、Phinx
- 管理端：Vue 3、Element Plus、Pinia、Axios、Zod
- 学习端：Vue 3、Axios、Zod；`webman/push` 提供的 `push.js` / `push-vue.js` 适配层

**Storage**: MySQL 8 — 新表 `notification_dispatches`、`notification_dispatch_recipients`；扩展 `learner_notifications`（`kind` 扩展、`dispatch_id`）；`audit_log` 审计

**Testing**: PHPUnit（API 集成/契约）；Vitest + `@vue/test-utils`（admin/web）；`packages/contracts` Vitest

**Target Platform**: Docker Compose 编排的 `api` + `admin` + `web` 容器；push WebSocket `3131`、HTTP API `3232`

**Project Type**: Monorepo — `apps/api` + `apps/admin` + `apps/web` + `packages/contracts`

**Performance Goals**:
- SC-001/SC-007：公告 fan-out 1000 学员 ≤5 分钟，常规规模 ≤30 秒
- SC-002：推送到达 ≤5 秒（p90）
- SC-003：消息列表首屏 ≤2 秒

**Constraints**:
- 宪章：think-orm 唯一 ORM；Redis 不用于业务缓存（push 插件内部状态除外）
- 令牌隔离：管理端/学习端 API 与 push 鉴权分离
- 首版纯文本正文；不引入邮件/短信/站外推送
- 推送为增强，REST 列表 + `unread-count` 为兜底

**Scale/Scope**: 5 个用户故事、22 条功能需求；预计新增/修改 ~35 源文件；2 张新表 + 1 张扩展表

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

| 门禁 | 结论 | 说明 |
|------|------|------|
| I. 容器即运行契约 | PASS | push/crontab 进程在 `api` 容器内；`compose.yaml` 暴露 3131/3232；Nginx 反代 wss |
| II. 稳定兼容且可复现 | PASS | `composer require webman/push workerman/crontab` 锁定版本；更新 `composer.lock` |
| III. 契约优先与端到端类型安全 | PASS | Zod 扩展 `notification.ts`；新增 `adminNotification.ts`；双端校验 API 响应 |
| IV. 数据变更安全可追溯 | PASS | Phinx 迁移扩展 `kind`、新表、索引；清理策略可回滚文档化 |
| V. 质量、安全与可运维性内建 | PASS | PHPUnit + Vitest 覆盖发送/隔离/清理/鉴权；`audit_log` 审计 |
| VI. 令牌鉴权 | PASS | push 私有频道鉴权绑定学员 `account_id`；管理端 `notification.manage` |
| Redis 使用边界 | PASS（附注） | push 插件连接状态用 Redis 为基础设施，非业务缓存 |
| 双前端独立构建 | PASS | admin/web 各自构建测试；contracts 共享类型 |

**Phase 1 复查**: 设计未引入并行 ORM、消息队列或站外通道；fan-out 同步分块满足当前规模，复杂度有文档化升级路径（dispatch `status` 字段可后续添加）。

## Project Structure

### Documentation (this feature)

```text
specs/003-admin-notifications/
├── plan.md
├── research.md
├── data-model.md
├── quickstart.md
├── contracts/
│   ├── admin-notifications.md
│   └── learner-notifications.md
├── checklists/
│   └── requirements.md
└── spec.md
```

### Source Code (repository root)

```text
apps/api/
├── composer.json                          # + webman/push, workerman/crontab
├── config/
│   ├── process.php                        # + notification_cleanup 进程
│   └── plugin/webman/push/                # 插件配置（安装后）
├── database/
│   ├── migrations/
│   │   └── 20260829000001_notification_dispatches.php
│   └── seeds/PermissionSeeder.php         # + notification.manage
├── app/
│   ├── controller/
│   │   ├── admin/NotificationController.php
│   │   └── learner/NotificationController.php  # + unreadCount
│   ├── service/
│   │   ├── MessageService.php             # emit 后触发 push
│   │   ├── NotificationDispatchService.php
│   │   ├── PushNotificationService.php
│   │   └── NotificationCleanupService.php
│   ├── process/
│   │   └── NotificationCleanup.php      # crontab 入口
│   └── route.php                          # admin + learner 路由
└── tests/
    ├── NotificationDispatchTest.php
    ├── NotificationCleanupTest.php
    └── NotificationPushAuthTest.php

apps/admin/
├── src/
│   ├── api/notifications.ts
│   ├── layouts/AdminMenu.ts               # + 通知管理
│   ├── router/index.ts                    # + /notifications 路由
│   └── views/notifications/
│       ├── NotificationListView.vue
│       └── NotificationComposeDialog.vue
└── tests/NotificationListView.test.ts

apps/web/
├── src/
│   ├── api/notifications.ts               # + unreadCount
│   ├── composables/usePushNotifications.ts
│   ├── layouts/LearnerLayout.vue          # 未读角标
│   └── views/me/MessagesView.vue          # kind 扩展 + push 刷新
└── tests/
    ├── MessagesView.test.ts               # 扩展
    └── PushNotifications.test.ts

packages/contracts/src/
├── notification.ts                        # kind + dispatch_id + UnreadCount
├── adminNotification.ts                   # 新增
└── index.ts

compose.yaml                               # api expose 3131, 3232
docker/web/nginx.conf                      # wss 反代（如需要）
```

**Structure Decision**: 业务逻辑集中在 `apps/api` 服务层；推送与清理为独立 service + process，不污染控制器。前端 admin 负责运营发送与查询，web 负责收件箱与实时订阅。契约统一在 `packages/contracts`。

## Complexity Tracking

> 无宪章违规需豁免。

| Violation | Why Needed | Simpler Alternative Rejected Because |
|-----------|------------|-------------------------------------|
| — | — | — |

## Phase 0: Research Summary

见 [research.md](./research.md)。关键结论：

- **webman/push** + 私有频道 `private-learner-{id}` + 自定义鉴权
- **workerman/crontab** 每日 03:30 分批清理 2 个月前 `learner_notifications`
- 扩展既有收件箱表，新增 `notification_dispatches` 发送记录
- 公告 fan-out 分块 200–500；同步完成返回
- `GET /messages/unread-count` 支撑导航角标
- `notification.manage` 单一权限码；`audit_log` 记录发送与清理

## Phase 1: Design Summary

见 [data-model.md](./data-model.md)、[contracts/admin-notifications.md](./contracts/admin-notifications.md)、[contracts/learner-notifications.md](./contracts/learner-notifications.md)、[quickstart.md](./quickstart.md)。

### 数据层

- `notification_dispatches` — 发送记录（长期保留）
- `notification_dispatch_recipients` — 站内信收件人
- `learner_notifications` — 扩展 `kind`、`dispatch_id`；`kind` 改 VARCHAR(32)

### API 面

**管理端** (`notification.manage`):
- `POST /notifications/announcements`
- `POST /notifications/internal-messages`
- `GET /notifications`、`GET /notifications/{id}`

**学习端**（学员令牌）:
- `GET /messages`（扩展 kind）
- `GET /messages/unread-count`（新增）
- `POST /messages/{id}/read`（既有）
- WebSocket `private-learner-{id}` / 事件 `notification`

### 前端

- **admin**: 列表 + 发送对话框（类型切换、学员多选）
- **web**: `LearnerLayout` 角标、`usePushNotifications` composable、`MessagesView` kind 标签

### 运维

- `config/process.php` 注册 `notification_cleanup` handler
- Compose / Nginx 暴露 push 端口与 wss 反代
- 环境变量：`PUSH_APP_KEY`、`PUSH_APP_SECRET`（示例写入 `.env.example`）

## Implementation Notes (for tasks phase)

1. **迁移顺序**: 先建 `notification_dispatches`，再 ALTER `learner_notifications`（`kind` VARCHAR + `dispatch_id`）
2. **MessageService**: `emit()` 末尾调用 `PushNotificationService::notifyLearner($learnerId, $row)`；保持幂等
3. **Fan-out**: `NotificationDispatchService::broadcastAnnouncement()` 游标分页 active learners；`idempotency_key = "{dispatchId}:{learnerId}"`
4. **Push 鉴权**: 修改插件 `route.php`——解析 `Authorization: Bearer`，用 `TokenService` 校验学员令牌，校验频道名
5. **清理**: `NotificationCleanupService::purgeExpired($batchSize=500)`；写 `audit_log`；crontab `0 30 3 * * *`
6. **权限种子**: `PermissionSeeder` 增加 `notification.manage`；超管默认拥有
7. **前端 push**: 登录后 `LearnerLayout` 初始化 composable；登出断开；`VITE_PUSH_URL` + `VITE_PUSH_APP_KEY`
8. **测试数据**: 清理测试用 `Carbon` 或 SQL 固定日期插入 61/59 天前记录

## Next Step

执行 `/speckit-tasks` 生成可执行任务清单 `tasks.md`。
