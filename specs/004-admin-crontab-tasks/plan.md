# Implementation Plan: 管理后台自动任务管理

**Branch**: `004-admin-crontab-tasks` | **Date**: 2026-08-29 | **Spec**: [spec.md](./spec.md)

**Input**: Feature specification from `/specs/004-admin-crontab-tasks/spec.md`

**Note**: 后端扩展 `apps/api`（迁移、调度 runner、管理 API、执行日志）；新增 `apps/admin` 自动任务模块；复用既有 `workerman/crontab` 与 `NotificationCleanupService`；将硬编码 `NotificationCleanup` 进程迁入统一调度体系。

## Summary

在管理后台新增「自动任务」模块：管理员通过表单配置**预注册**定时任务（首版 `notification.cleanup`）的六段式调度表达式、启用状态与可选参数；服务端校验表达式、预览下一次执行时间；`ScheduledTaskRunner` 进程按库表配置驱动 `workerman/crontab` 自动执行；每次运行写入 `scheduled_task_runs` 完整日志，支持筛选分页与手动触发。

## Technical Context

**Language/Version**: PHP 8.4（Webman 2.2）；TypeScript 5.x strict；Vue 3.5；Node.js 22 LTS

**Primary Dependencies**:
- 后端既有：`workerman/crontab`、`webman/think-orm`、Phinx
- 管理端：Vue 3、Element Plus、Pinia、Axios、Zod

**Storage**: MySQL 8 — 新表 `scheduled_tasks`、`scheduled_task_runs`；`audit_log` 记录配置变更与手动触发

**Testing**: PHPUnit（API 集成）；Vitest + `@vue/test-utils`（admin）；`packages/contracts` Vitest

**Target Platform**: Docker Compose `api` + `admin` 容器

**Project Type**: Monorepo — `apps/api` + `apps/admin` + `packages/contracts`

**Performance Goals**:
- SC-001：保存配置 ≤1 分钟（含预览）
- SC-004：日志列表首屏 ≤2 秒
- SC-006：手动触发到可查询日志 ≤30 秒

**Constraints**:
- 宪章：think-orm 唯一 ORM；Redis 不用于业务缓存/队列（runner 用 DB `updated_at` 轮询重载）
- 六段式 cron；最短调度间隔 60 秒
- 预注册 handler，禁止界面执行任意命令
- 令牌隔离：仅管理端 API

**Scale/Scope**: 5 个用户故事、17 条功能需求；预计新增/修改 ~25 源文件；2 张新表；迁移 1 个既有 crontab 进程

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

| 门禁 | 结论 | 说明 |
|------|------|------|
| I. 容器即运行契约 | PASS | `scheduled_tasks_runner` 在 `api` 容器 `config/process.php` 内 |
| II. 稳定兼容且可复现 | PASS | 无新 Composer 依赖；复用已锁定的 `workerman/crontab` |
| III. 契约优先与端到端类型安全 | PASS | Zod `adminScheduledTask.ts`；双端校验 API |
| IV. 数据变更安全可追溯 | PASS | Phinx 迁移 + 种子；think-orm 模型 |
| V. 质量、安全与可运维性内建 | PASS | PHPUnit + Vitest；`audit_log` + run 日志 |
| VI. 令牌鉴权 | PASS | `scheduled_task.manage`；学员令牌不可调用 |
| Redis 使用边界 | PASS | 未引入 Redis 协调；DB 轮询重载 |
| 双前端独立构建 | PASS | 仅 admin 变更；contracts 共享类型 |

**Phase 1 复查**: 未引入 MQ、并行 ORM 或宿主机 cron；手动触发同步执行在 API worker，自动调度在 runner worker，handler 逻辑共享服务类。

## Project Structure

### Documentation (this feature)

```text
specs/004-admin-crontab-tasks/
├── plan.md
├── research.md
├── data-model.md
├── quickstart.md
├── contracts/
│   └── admin-scheduled-tasks.md
├── checklists/
│   └── requirements.md
└── spec.md
```

### Source Code (repository root)

```text
apps/api/
├── config/
│   └── process.php                        # - notification_cleanup; + scheduled_tasks_runner
├── database/
│   ├── migrations/
│   │   └── 20260829000002_scheduled_tasks.php
│   └── seeds/
│       ├── PermissionSeeder.php           # + scheduled_task.manage
│       └── ScheduledTaskSeeder.php        # notification.cleanup 默认行
├── app/
│   ├── controller/admin/
│   │   └── ScheduledTaskController.php
│   ├── service/
│   │   ├── ScheduleExpressionService.php  # isValid, nextRunAt, minInterval
│   │   ├── ScheduledTaskService.php
│   │   └── ScheduledTaskRunService.php
│   ├── scheduled/
│   │   ├── ScheduledTaskHandler.php       # interface
│   │   ├── ScheduledTaskHandlerRegistry.php
│   │   └── handler/
│   │       └── NotificationCleanupHandler.php
│   ├── process/
│   │   ├── ScheduledTaskRunner.php        # crontab 加载 / reload
│   │   └── NotificationCleanup.php        # 删除或留空废弃
│   └── middleware/Authorize.php         # + scheduled-tasks 映射
└── tests/
    ├── ScheduledTaskExpressionTest.php
    ├── ScheduledTaskControllerTest.php
    └── ScheduledTaskRunnerTest.php

apps/admin/
├── src/
│   ├── api/scheduledTasks.ts
│   ├── layouts/AdminMenu.ts               # + 自动任务
│   ├── router/index.ts                    # + /scheduled-tasks, /scheduled-tasks/runs
│   └── views/scheduled-tasks/
│       ├── ScheduledTaskListView.vue
│       ├── ScheduledTaskEditDialog.vue
│       └── ScheduledTaskRunLogView.vue
└── tests/
    ├── ScheduledTaskListView.test.ts
    └── ScheduledTaskRunLogView.test.ts

packages/contracts/src/
├── adminScheduledTask.ts
└── index.ts
```

**Structure Decision**: 调度执行集中在 `ScheduledTaskRunner` + `scheduled/` handler 注册表；HTTP 层仅配置与查询。管理端列表 + 编辑对话框 + 日志子页，与通知模块 UI 模式一致。

## Complexity Tracking

> 无宪章违规需豁免。

| Violation | Why Needed | Simpler Alternative Rejected Because |
|-----------|------------|-------------------------------------|
| — | — | — |

## Phase 0: Research Summary

见 [research.md](./research.md)。关键结论：

- 单一 `ScheduledTaskRunner` 进程 + `Crontab::destroy()` 动态重载
- `Parser::isValid` + 自研 `nextRunAt` 扫描；最短间隔 60s
- `handler_code` 注册表；首版 `notification.cleanup`
- 重叠执行 → `skipped` 日志
- DB `updated_at` 30s 轮询同步配置（不用 Redis pub/sub）
- 迁移：移除 `notification_cleanup` 独立进程

## Phase 1: Design Summary

见 [data-model.md](./data-model.md)、[contracts/admin-scheduled-tasks.md](./contracts/admin-scheduled-tasks.md)、[quickstart.md](./quickstart.md)。

### 数据层

- `scheduled_tasks` — 任务配置（种子插入清理任务）
- `scheduled_task_runs` — 执行日志（success / failed / skipped）

### API 面

**管理端** (`scheduled_task.manage`):
- `GET /scheduled-tasks`、`GET /scheduled-tasks/{id}`
- `PATCH /scheduled-tasks/{id}`
- `POST /scheduled-tasks/validate-expression`
- `POST /scheduled-tasks/{id}/run`
- `GET /scheduled-tasks/runs`、`GET /scheduled-tasks/runs/{id}`

### 前端

- **admin**: 任务列表 + 编辑对话框（表达式、启用、参数、预览）；执行日志 Tab/子路由

### 运维

- `config/process.php` 注册 `scheduled_tasks_runner`
- 部署后确认无双进程清理；配置变更 ≤30s 生效

## Implementation Notes (for tasks phase)

1. **迁移顺序**: `scheduled_tasks` → `scheduled_task_runs` → 种子默认清理任务
2. **Runner**: `onWorkerStart` → `loadTasks()`；Timer 30s 检查 `MAX(updated_at)`；`reload()` 销毁全部 Crontab 再重建
3. **执行包装**: `ScheduledTaskExecutor::run($task, $trigger, $actorId)` 统一写 run 行、更新 `last_run_*`、捕获异常
4. **手动触发**: API 进程调用同一 `ScheduledTaskExecutor`（同步）；`running` 标志用 DB `SELECT ... FOR UPDATE` 或 Redis 可选——首版用 runner 内存标志仅防自动重叠；API 手动触发用 `scheduled_tasks` 行锁或 `running` 列
5. **表达式**: 强制 `preg_match` 六段非空；拒绝五段式
6. **权限**: `PermissionSeeder` + `Authorize::MAP` 前缀 `/scheduled-tasks`
7. **废弃**: 删除 `NotificationCleanup.php` 进程注册；handler 内调 `NotificationCleanupService`
8. **contracts**: 导出 Zod；admin `scheduledTasks.ts` 对齐契约

## Next Step

执行 `/speckit-tasks` 生成可执行任务清单 `tasks.md`。
