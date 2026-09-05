# Implementation Plan: 学习行动循环

**Branch**: `013-learning-action-loop` | **Date**: 2026-09-04 | **Spec**: [spec.md](./spec.md)

**Input**: Feature specification from `/specs/013-learning-action-loop/spec.md`

## Summary

登录学员通过独立的 `GET /me/next-action` 获得服务端计算的唯一主下一步；该结果只解释现有访问权、进度、地图、收藏、订单、优惠券和消息，不持久化第二份学习状态。四类自动提醒由既有 scheduled task 每 5 分钟评估，使用一张 MySQL 评估/投递状态表实现事件幂等、72 小时节流、收藏关系一次性提醒、长期未学习按周提醒、每日 3 条上限和 `Asia/Shanghai` 勿扰时段，并写入既有学习端消息中心。

订单临期继续复用当前 `OrderService` 的唯一截止规则：`created_at + 15 分钟`。计划不新增 `orders.expires_at`，但会抽出可复用的截止时间计算，避免提醒服务和超时取消服务各自推导。若未来要支持真正的“提前一天”长时订单，应另立订单生命周期规格。

## Technical Context

**Language/Version**: PHP 8.4；Webman 2.2 runtime；TypeScript 5.x strict；Vue 3.5；Node.js 22 LTS

**Primary Dependencies**: `webman/think-orm`、Phinx、现有 scheduled task runner、`MessageService`、`ProgressService`、`LearningMapService`、Vue 3、Element Plus、Pinia、Vue Router、Axios、Zod；不新增 Composer/npm 依赖

**Storage**: MySQL 8；新增 `learner_reminder_evaluations`；复用 `learner_notifications`、`accounts`、`course_entitlements`、`course_enrollments`、`lesson_progresses`、`learning_maps`、`favorites`、`orders`、`learner_coupons`。本功能不新增 Redis 业务键、缓存或队列。

**Testing**: API PHPUnit（服务、契约、迁移与并发边界）；学习端/共享契约 Vitest；Compose test profile、PHPStan、Prettier、ESLint、TypeScript、生产构建和必要的 Playwright 验收

**Target Platform**: Docker Compose 编排的 `api`、`web`、MySQL、Redis 容器；macOS 使用 OrbStack

**Project Type**: Monorepo web application：`apps/api` + `apps/web` + `apps/admin` + `packages/contracts`

**Performance Goals**: `GET /me/next-action` 在常规单学员数据量下 p95 不超过 2 秒；完成课节后客户端重新读取下一步在 5 秒内完成；提醒任务按 200 名学员批量扫描且同一任务不重入

**Constraints**: 服务端是唯一事实；固定候选优先级与稳定平局规则；提醒同规则同资源 72 小时最多一条；收藏关系一次、长期未学习每 7 个自然日一次；每学员每天最多 3 条；`Asia/Shanghai` 22:00–08:00 勿扰；站内消息复用既有未读计数和保留规则；不得引入机器学习、站外通知、复杂用户设置或前端学习状态副本

**Scale/Scope**: 1 个学习端主行动入口、4 个自动提醒规则、1 张评估状态表、1 个 scheduled handler；不建设推荐模型、运营配置台或新的通知渠道

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

| 门禁 | 结论 | 说明 |
|------|------|------|
| I. 容器即运行契约 | PASS | API、迁移、测试和学习端构建均通过 Compose/OrbStack 运行；不依赖宿主 PHP/Node |
| II. 稳定兼容且可复现 | PASS | 不新增运行时依赖；沿用锁定的 PHP、Node、Composer 和 pnpm 版本 |
| III. 契约优先与端到端类型安全 | PASS | 新增共享 Zod 的 next-action DTO，扩展 notification kind/resource DTO；Axios API 层集中解析 |
| IV. 数据变更安全可追溯 | PASS | 一张 Phinx 迁移、一致的唯一键/索引、think-orm 访问；下一步不落库，不复制进度 |
| V. 质量、安全与可运维性内建 | PASS | PHPUnit/Vitest/静态检查/构建/Compose 健康检查；评估状态与 scheduled task run 可定位失败 |
| VI. 令牌鉴权与身份隔离 | PASS | next-action 和提醒评估只接受学习端令牌；资源解析始终按当前学员重新校验 |
| Redis 使用边界 | PASS | 评估、节流、每日上限和消息幂等均使用 MySQL；仅复用已有调度入口，不增加 Redis 状态 |
| 双前端独立构建 | PASS | 本功能只改学习端和共享契约，admin 不增加依赖，三端仍可独立构建 |

## Project Structure

### Documentation (this feature)

```text
specs/013-learning-action-loop/
├── plan.md
├── research.md
├── data-model.md
├── quickstart.md
├── contracts/
│   └── learner-action-loop.md
├── checklists/requirements.md
└── tasks.md             # 由 $speckit-tasks 生成，本阶段不创建
```

### Source Code (repository root)

```text
apps/api/
├── app/controller/learner/LearningActionController.php
├── app/service/LearningActionService.php
├── app/service/LearningReminderService.php
├── app/service/MessageService.php                         # 新提醒 kind
├── app/controller/learner/NotificationController.php      # 新资源类型/可用性
├── app/scheduled/handler/LearningReminderHandler.php
├── app/model/LearnerReminderEvaluation.php
├── app/route.php
├── database/migrations/20260904000002_learning_action_loop.php
├── database/seeds/ScheduledTaskSeeder.php                 # 新评估任务
└── tests/{LearningAction,LearningReminder}*Test.php

apps/web/src/
├── api/learningAction.ts
├── views/home/HomeView.vue                                # 登录后主行动
└── views/me/StudentCenterView.vue                         # 提醒资源跳转

apps/web/tests/{HomeView,LearningAction}*.test.ts

packages/contracts/src/
├── learningAction.ts
├── notification.ts                                        # kind/resource 扩展
└── __tests__/learningAction.test.ts
```

**Structure Decision**: `LearningActionService` 只负责从现有事实计算候选、稳定排序和目标资源解析；`LearningReminderService` 负责四类规则、节流、勿扰、每日上限和 `MessageService` 投递；`LearningReminderHandler` 只做批量遍历和任务上下文汇总。`HomeService`/公开 `/home` 不承载个性化结果，学习端在登录后单独读取 next-action。前端只保存当前响应用于展示，不复制课程进度、访问权或提醒状态。

## Complexity Tracking

无宪章违规项。本计划刻意不引入下一步持久化表、推荐模型、Redis 业务缓存、独立队列或每日计数表。

## Phase 0 产出

见 [research.md](./research.md)：记录 endpoint 边界、计算与持久化边界、单表节流模型、通知契约、调度频率/重试、订单 15 分钟截止事实和时间窗口定义。

## Phase 1 产出

| 产物 | 路径 |
|------|------|
| 数据模型 | [data-model.md](./data-model.md) |
| 学习端/API 契约 | [contracts/learner-action-loop.md](./contracts/learner-action-loop.md) |
| 验证指南 | [quickstart.md](./quickstart.md) |

## 实现阶段建议（供 `$speckit-tasks`）

1. Foundation：迁移、`LearnerReminderEvaluation` 模型、共享 Zod 契约和通知资源枚举扩展。
2. 主行动：`LearningActionService`、学习端 endpoint、固定候选排序、降级响应及 API 测试。
3. 提醒：四类规则、单表节流/每日上限/勿扰、scheduled handler、种子任务及 API 测试。
4. 学习端闭环：首页登录态加载主行动、行动目标跳转、消息中心新资源跳转和失效说明。
5. 回归验收：跨设备完成后重新读取、并发评估、重复登录、实时提示不可用、Compose 构建和 quickstart 剧本。

## 关键风险与缓解

| 风险 | 缓解 |
|------|------|
| 现有订单只有 15 分钟生命周期，无法表达“提前一天”业务语义 | 统一复用 `OrderService` 的 15 分钟截止推导并在消息展示准确时间；真正长订单另立订单生命周期规格 |
| 多个规则同时命中导致噪音 | 每条规则每名学员只选一个最紧急候选；锁学员行后按规则优先级最多投递 3 条，超出的记录为 `daily_cap` 且不批量补发 |
| 评估重跑造成重复消息或未读计数 | MySQL 唯一事件键 + `MessageService` 确定性幂等键；成功投递和计数更新沿现有消息服务路径 |
| 生成后课程/订单/优惠券失效 | 下一步读取和消息列表都重新解析资源；不可用时返回原因或服务端给出的列表降级入口 |
| 旧页面覆盖新进度 | `ProgressService` 继续以单调数据库写入为准；完成后客户端重新 GET next-action，不提交任何客户端状态 |

**Phase 1 复查**：设计仍通过全部宪章门禁；唯一持久化对象是提醒评估状态，下一步、学习节奏和地图进度均由现有事实实时计算。
