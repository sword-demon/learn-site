# Implementation Plan: 学习事实漏斗

**Branch**: `017-learning-fact-funnel` | **Date**: 2026-09-11 | **Spec**: [spec.md](./spec.md)

**Input**: Feature specification from `/specs/017-learning-fact-funnel/spec.md`

## Summary

管理端按课程观察学习事实漏斗：访问权生效 → 首次打开课节 → 有效进度 → 完成课程。与试看、订单、发布触达分区。只读聚合既有 `course_entitlements` / `lesson_progresses` / `course_enrollments` / `orders` / `notification_dispatches`，不新增业务表，不把进度写入路径接到报表。默认窗口 30 个自然日（可选 7 / 90）。文案锁定为事实转化，禁止增量效果。权限复用 `course_student.view`。

## Technical Context

- **Language/Version**: PHP 8.4（apps/api, think-orm + webman 2.2），Vue 3 + TypeScript（apps/admin）
- **Primary Dependencies**:
  - apps/api: think-orm、既有 Entitlement / Progress / Order / NotificationDispatch
  - packages/contracts: zod
  - apps/admin: Element Plus、vue-router（无学习端页面）
- **Storage**: MySQL 8.4 只读现有表；可选覆盖索引。不用 Redis。不新建漏斗表。
- **Testing**: PHPUnit（apps/api/tests）、Vitest（apps/admin/tests 与 packages/contracts）
- **Target Platform**: Webman API + 管理端 SPA
- **Project Type**: 既有 monorepo，不新建应用
- **Performance Goals**: 单课漏斗 GET 在验收数据量下管理端 3 秒内出齐四阶段（SC-001）；学员进度提交成功率与打开报表前一致（SC-006）
- **Constraints**:
  - 时区 `Asia/Shanghai`
  - 进度 / 访问权写路径禁止调用漏斗服务
  - Redis 不缓存报表
  - GET 不写 `audit_log`
  - 契约固定 disclaimer 与 pending 文案
- **Scale/Scope**: 1 个管理端 GET、1 个管理端页面、1 个权限复用、0 张新业务表、contracts 1 个模块

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

对照 `.specify/memory/constitution.md` (v1.2.0)：

- **I 容器即运行契约**: 验收走 Makefile / Compose
- **III 契约优先**: DTO 进 `packages/contracts`，管理端 Zod 校验响应
- **IV 数据变更安全**: 无新业务表；若加索引则走 Phinx。模型仍 think-orm
- **V 质量门禁**: PHPUnit + Vitest；contracts 变更需 `make rebuild-all`
- **质量门禁 #2 Redis**: 报表不用 Redis
- **简单优先**: 只读聚合，不引入快照表 / 队列 / 新权限码
- **管理端写操作审计**: 本功能无写操作
- **service 层**: 只读服务，时区 `Asia/Shanghai`，数据范围闸门在公开方法第一行

Phase 1 后复核：契约为只读 GET；data-model 明确禁止投影回写；quickstart V5 验证写路径隔离。无 violation，无需 Complexity Tracking。

## Project Structure

### Documentation (this feature)

```text
specs/017-learning-fact-funnel/
├── plan.md
├── research.md
├── data-model.md
├── quickstart.md
├── contracts/
│   ├── learningFactFunnel.ts
│   └── README.md
└── tasks.md             # $speckit-tasks，本命令不创建
```

### Source Code (既有 monorepo)

```text
packages/contracts/src/learningFactFunnel.ts
packages/contracts/src/index.ts
packages/contracts/src/__tests__/learningFactFunnel.test.ts

apps/api/app/service/LearningFactFunnelService.php
apps/api/app/controller/admin/LearningFactFunnelController.php
apps/api/app/route.php
apps/api/app/middleware/Authorize.php
apps/api/tests/LearningFactFunnelServiceTest.php
apps/api/tests/LearningFactFunnelRouteTest.php

apps/admin/src/api/learningFactFunnel.ts
apps/admin/src/views/catalog/LearningFactFunnelView.vue
apps/admin/src/router/index.ts
apps/admin/tests/LearningFactFunnelView.test.ts
```

可选：`apps/api/database/migrations/20260911000002_learning_fact_funnel_indexes.php`（仅覆盖索引）。

**Structure Decision**: 管理端单课只读报告。学习端不改。进度服务不引用漏斗服务。

## Complexity Tracking

> 无宪章违反，本表留空。
