# Implementation Plan: 学员每日签到与每日计划

**Branch**: `005-learner-daily-checkin` | **Date**: 2026-08-30 | **Spec**: [spec.md](./spec.md)

**Input**: Feature specification from `/specs/005-learner-daily-checkin/spec.md`

**Note**: 全栈功能——`apps/api` 新增签到表与服务、`apps/web` 弹窗与列表页、`apps/admin` 签到管理模块；复用 `HtmlSanitizer` 与既有分页/审计模式。

## Summary

为学习端已登录学员提供**每日签到**：提交富文本「每日计划」，每自然日限签一次。进入学习端时未签到则弹窗提醒（同会话关闭后不重复，新会话再提醒）。独立签到列表页展示本人历史。管理端可查询全部学员签到记录并删除；删除后该日可重签。数据存 `learner_daily_checkins`，权限码 `checkin.manage`。

## Technical Context

**Language/Version**: PHP 8.4（Webman 2.2）；TypeScript 5.x strict；Vue 3.5；Node.js 22 LTS

**Primary Dependencies**:
- 后端既有：`webman/think-orm`、`HtmlSanitizer`、Phinx
- 学习端新增：`@wangeditor/editor-for-vue`（与 admin 同版本，用于 `CheckinPlanEditor`）
- 管理端/学习端：Vue 3、Element Plus、Pinia、Axios、Zod

**Storage**: MySQL 8 — 新表 `learner_daily_checkins`；`audit_log` 审计

**Testing**: PHPUnit（API 集成）；Vitest + `@vue/test-utils`（admin/web）；`packages/contracts` Vitest

**Target Platform**: Docker Compose 编排的 `api` + `admin` + `web` 容器

**Project Type**: Monorepo — `apps/api` + `apps/admin` + `apps/web` + `packages/contracts`

**Performance Goals**:
- SC-001：弹窗展示 ≤3 秒（p95）
- SC-004：签到列表首屏 ≤2 秒
- SC-005：管理端筛选准确率 100%

**Constraints**:
- 宪章：think-orm 唯一 ORM；令牌隔离
- 自然日按 `Asia/Shanghai` 服务端判定
- 富文本入库前 `HtmlSanitizer`；学习端 `v-html` 仅展示净化结果
- 首版无编辑/补签/排行榜/公开展示他人计划

**Scale/Scope**: 4 个用户故事、19 条功能需求；预计新增/修改 ~25 源文件；1 张新表

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

| 门禁 | 结论 | 说明 |
|------|------|------|
| I. 容器即运行契约 | PASS | 无宿主机依赖；迁移经 Phinx 在 api 容器执行 |
| II. 稳定兼容且可复现 | PASS | 无新后端 Composer 依赖（web 侧 WangEditor 与 admin 版本对齐） |
| III. 契约优先与端到端类型安全 | PASS | 新增 `packages/contracts/src/dailyCheckin.ts`；双端 Zod 校验 |
| IV. 数据变更安全可追溯 | PASS | Phinx 迁移 + `UNIQUE(learner_id, checkin_date)`；`audit_log` |
| V. 质量、安全与可运维性内建 | PASS | PHPUnit + Vitest；HtmlSanitizer 防 XSS |
| VI. 令牌鉴权 | PASS | 学习端 `LearnerAuth`；管理端 `checkin.manage` |
| Redis 使用边界 | PASS | 本功能不使用 Redis |
| 双前端独立构建 | PASS | web/admin 各自构建；contracts 共享类型 |

**Phase 1 复查**: 未引入并行 ORM、消息队列或站外通道；`sessionStorage` 仅客户端 UX，非业务状态源。

## Project Structure

### Documentation (this feature)

```text
specs/005-learner-daily-checkin/
├── plan.md
├── research.md
├── data-model.md
├── quickstart.md
├── contracts/
│   ├── admin-daily-checkin.md
│   └── learner-daily-checkin.md
├── checklists/
│   └── requirements.md
└── spec.md
```

### Source Code (repository root)

```text
apps/api/
├── database/
│   ├── migrations/
│   │   └── 20260830000001_learner_daily_checkins.php
│   └── seeds/PermissionSeeder.php         # + checkin.manage
├── app/
│   ├── controller/
│   │   ├── admin/CheckinController.php
│   │   └── learner/CheckinController.php
│   ├── service/CheckinService.php
│   ├── middleware/Authorize.php           # + /checkins 映射
│   └── route.php                          # admin + learner 路由
└── tests/DailyCheckinTest.php

apps/admin/
├── src/
│   ├── api/checkins.ts
│   ├── layouts/AdminMenu.ts               # + 签到管理
│   ├── router/index.ts                    # + /checkins
│   └── views/checkins/CheckinListView.vue
└── tests/CheckinListView.test.ts

apps/web/
├── src/
│   ├── api/checkins.ts
│   ├── components/
│   │   ├── CheckinPlanEditor.vue          # 精简 WangEditor
│   │   └── DailyCheckinDialog.vue
│   ├── composables/useDailyCheckinPrompt.ts
│   ├── layouts/LearnerLayout.vue          # 挂载弹窗逻辑 + 导航入口
│   ├── router/index.ts                    # + /me/checkins
│   └── views/me/CheckinListView.vue
└── tests/
    ├── DailyCheckinDialog.test.ts
    └── CheckinListView.test.ts

packages/contracts/src/
├── dailyCheckin.ts
└── index.ts                               # export
```

**Structure Decision**: `CheckinService` 集中业务逻辑（净化、日期、唯一约束、审计）；控制器保持薄。学习端弹窗由 composable + layout 驱动，列表独立路由。管理端单列表页 + 详情抽屉。

## Complexity Tracking

> 无宪章违规需豁免。

| Violation | Why Needed | Simpler Alternative Rejected Because |
|-----------|------------|-------------------------------------|
| — | — | — |

## Phase 0: Research Summary

见 [research.md](./research.md)。关键结论：

- 单表 `learner_daily_checkins` + `UNIQUE(learner_id, checkin_date)`
- 站点时区 `Asia/Shanghai` 判定自然日
- 学习端精简 `CheckinPlanEditor` + 既有 `HtmlSanitizer`
- `GET /checkins/today` 驱动弹窗；`sessionStorage` 会话内关闭
- `checkin.manage` 权限；`audit_log` 记录创建与删除
- 物理删除；删除后可重签

## Phase 1: Design Summary

见 [data-model.md](./data-model.md)、[contracts/admin-daily-checkin.md](./contracts/admin-daily-checkin.md)、[contracts/learner-daily-checkin.md](./contracts/learner-daily-checkin.md)、[quickstart.md](./quickstart.md)。

### 数据层

- `learner_daily_checkins` — 学员、日期、计划 HTML、签到时间

### API 面

**学习端**（学员令牌）:
- `GET /checkins/today`
- `POST /checkins`
- `GET /checkins`
- `GET /checkins/{id}`（可选）

**管理端** (`checkin.manage`):
- `GET /checkins`
- `GET /checkins/{id}`
- `DELETE /checkins/{id}`

### 前端

- **web**: `DailyCheckinDialog` + `useDailyCheckinPrompt` + `CheckinListView` + 导航「每日签到」
- **admin**: `CheckinListView` 筛选/分页/详情/删除确认

### 运维

- 迁移：`phinx migrate`
- 种子：`checkin.manage` 写入 `PermissionSeeder`

## Implementation Notes (for tasks phase)

1. **迁移**: 先建表与唯一索引；`down()` 删表
2. **CheckinService::create()**: 净化 → 空壳校验 → `checkin_date=today` → insert；捕获 duplicate → `409`
3. **CheckinService::delete()**: 管理端专用；写 `audit_log` 后 DELETE
4. **今日状态**: `getTodayStatus($learnerId)` 返回 `server_date` + `checked_in` + optional record
5. **弹窗**: `useDailyCheckinPrompt` 在 `LearnerLayout` `onMounted`；`server_date` 作 sessionStorage key 后缀
6. **权限**: `PermissionSeeder` + `Authorize::MAP` + `AdminMenu` + 路由 `meta.permission`
7. **契约**: `dailyCheckin.ts` 导出 DTO + `CreateCheckinInput` + list envelopes
8. **测试**: 重复签到、跨学员 404、删除后 `today.checked_in=false`、script 标签净化

## Next Step

执行 `/speckit-tasks` 生成可执行任务清单 `tasks.md`。
