---
description: "Task list for 017-learning-fact-funnel"
---

# Tasks: 学习事实漏斗

**Input**: Design documents from `/specs/017-learning-fact-funnel/`

**Prerequisites**: plan.md, spec.md, research.md, data-model.md, contracts/, quickstart.md

**Tests**: 依据 CLAUDE.md「新增视图/service/composable 必须同 commit 提交对应 vitest/phpunit 测试」, 每个用户故事带 TDD 测试任务. 测试写在实现前并先跑红.

**Organization**: 按 user story 分阶段. P1 先于 P2. 顺序 US1 → US2 → US3 → US5 → US4. 每个故事可独立验证.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: 可并行 (不同文件, 无未完成依赖)
- **[Story]**: 所属 user story (US1~US5)
- 文件路径相对仓库根

---

## Phase 1: Setup (Shared Infrastructure)

**Purpose**: 契约进共享包, 不引入新依赖, 不改进度写入

- [X] T001 把 `specs/017-learning-fact-funnel/contracts/learningFactFunnel.ts` 落到 `packages/contracts/src/learningFactFunnel.ts` (disclaimer / pending 文案 / stages 元组与规格一致)
- [X] T002 [P] 在 `packages/contracts/src/index.ts` 追加 `export * from "./learningFactFunnel";`
- [X] T003 [P] 在 `packages/contracts/src/__tests__/learningFactFunnel.test.ts` 钉死: 默认 query `window_days=30` `source=all`; 非法 window/source 失败; disclaimer 字面量; `in_window_label` / `window_elapsed_label` 不含「流失」「弃学」

**Checkpoint**: 契约可测, 尚无 API / 页面

---

## Phase 2: Foundational (Blocking Prerequisites)

**Purpose**: 只读入口、权限、数据范围闸门、空壳 DTO. 完成前不要开始 US 业务口径

**⚠️ CRITICAL**: 完成前不要开始任何 US 的阶段计算

- [X] T004 在 `apps/api/app/route.php` 管理端课程组增加 `GET /courses/{id}/learning-funnel` → `LearningFactFunnelController::show`; 中间件保持 `AdminAuth` + `Authorize`
- [X] T005 [P] 在 `apps/api/app/middleware/Authorize.php` 把 `GET /api/admin/v1/courses/{id}/learning-funnel` 映射为 `course_student.view` (与课程学员列表同权, 不新增权限码)
- [X] T006 [P] 在 `apps/api/app/controller/admin/LearningFactFunnelController.php` 落薄 `show(Request, string $id)`: 解析 `window_days` / `source`, 调 service, `ApiResponse::ok`; 非法 query → `VALIDATION_FAILED`; 不写 audit
- [X] T007 在 `apps/api/app/service/LearningFactFunnelService.php` 落 `show(int $staffId, int $courseId, int $windowDays, string $source)`: 第一行数据范围闸门 (与课程学员相同); 超范围 `NOT_FOUND`; 合法时返回契约形状的零值 DTO (`disclaimer` 已填, stages 四人 0, `generated_at` 用 `toIso8601`); 禁止 Redis; 禁止写任何业务表
- [X] T008 [P] 在 `apps/admin/src/api/learningFactFunnel.ts` 用 `@learn-site/contracts` 的 `LearningFactFunnelDTO` 封装 `GET /courses/{id}/learning-funnel`
- [X] T009 在 `apps/admin/src/router/index.ts` 增加 `/courses/:id/learning-funnel`, `permission: 'course_student.view'`, 组件 `LearningFactFunnelView.vue`; 在 `apps/admin/src/views/catalog/LearningFactFunnelView.vue` 放空壳 (标题 + disclaimer 位 + `el-empty`)
- [X] T010 [P] 在 `apps/api/phpunit.xml` 登记 `tests/LearningFactFunnelServiceTest.php` 与 `tests/LearningFactFunnelRouteTest.php`

**Checkpoint**: 有权限的管理员能打开空报告; 范围外课程与学员列表同样拒绝; 进度写入代码未被引用

---

## Phase 3: User Story 1 - 管理员按课程查看学习事实漏斗 (Priority: P1) 🎯 MVP

**Goal**: 默认 30 日、全部来源下展示四阶段人数与转化率, 试看访客不进队列, 文案是事实转化

**Independent Test**: 夹具 10 / 6 / 4 / 2 (访问权 / 打开 / 有效进度 / 完成) 打开漏斗数字一致; 无访问权的试看不出现在任何阶段; 零访问权显示空状态且不把对照区数字填进漏斗

### Tests for User Story 1 ⚠️ 写在前, 跑红

- [X] T011 [P] [US1] 在 `apps/api/tests/LearningFactFunnelServiceTest.php` 写: 10/6/4/2 子集不变量; 无访问权试看不进 `entitled`; 零访问权四阶段为 0; `of_cohort_rate` / `of_previous_rate` 分母为 0 时为 null; disclaimer 为契约字面量
- [X] T012 [P] [US1] 在 `apps/api/tests/LearningFactFunnelRouteTest.php` 写: `GET /api/admin/v1/courses/{id}/learning-funnel` 注册且中间件含 `AdminAuth` + `Authorize`
- [X] T013 [P] [US1] 在 `apps/admin/tests/LearningFactFunnelView.test.ts` 写: 渲染四阶段人数与 disclaimer; 四阶段全 0 时 `el-empty`; 文案不含「提升了完成率」「带来了学习效果」

### Implementation for User Story 1

- [X] T014 [US1] 在 `apps/api/app/service/LearningFactFunnelService.php` 实现 `entitled` / `first_opened` / `valid_progress` / `completed` (口径见 `data-model.md`: 生效后时间戳, `opened_at` 回退 `created_at`, 有效进度 = `completed=1`); 后一阶段 ⊆ 前一阶段
- [X] T015 [US1] 在 `apps/api/app/controller/admin/LearningFactFunnelController.php` 把 service 结果原样放入 `ApiResponse::ok`, 时间 ISO-8601
- [X] T016 [US1] 在 `apps/admin/src/views/catalog/LearningFactFunnelView.vue` 用 `el-card` / `el-statistic` 展示四阶段、转化率与服务端 `disclaimer`; 禁止自造 badge
- [X] T017 [US1] 在 `apps/admin/src/views/students/CourseStudentView.vue` 增加进入 `/courses/:id/learning-funnel` 的文字按钮 (Element Plus)

**Checkpoint**: US1 可独立演示 MVP

---

## Phase 4: User Story 2 - 观察窗口内把尚未开始与窗口内未转化分开 (Priority: P1)

**Goal**: 窗口 7/30/90, 进行中未打开 ≠ 流失; 窗口结束后才算窗口内未转化

**Independent Test**: 刚生效未打开的学员只在 `pending.in_window`; 窗口已过仍未打开的只在 `pending.window_elapsed`; 切换窗口后人数重算且不出现「流失」「弃学」

### Tests for User Story 2 ⚠️ 写在前, 跑红

- [X] T018 [P] [US2] 在 `apps/api/tests/LearningFactFunnelServiceTest.php` 追加: 默认 30 日; 非法 `window_days` 由控制器拒绝; `in_window + window_elapsed + first_opened = entitled`; 标签字面量符合契约
- [X] T019 [P] [US2] 在 `apps/admin/tests/LearningFactFunnelView.test.ts` 追加: 窗口切换请求 `window_days`; 展示「窗口进行中、尚未开始」与「窗口内未转化」; 无「流失」「弃学」

### Implementation for User Story 2

- [X] T020 [US2] 在 `apps/api/app/service/LearningFactFunnelService.php` 按 `created_at + window_days` (Asia/Shanghai 自然日) 划分 pending; `generated_at` 为计算时刻
- [X] T021 [US2] 在 `apps/admin/src/views/catalog/LearningFactFunnelView.vue` 用 `el-radio-group` 或 `el-select` 切换 7/30/90, 展示 pending 两行与当前窗口说明

**Checkpoint**: US2 可独立验证窗口口径

---

## Phase 5: User Story 3 - 试看、订单和发布触达与漏斗分开展示 (Priority: P1)

**Goal**: 对照区与四阶段分区; 订单成功不是完成课程; 触达不是首次打开

**Independent Test**: 5 笔支付成功 + 3 人完成课程 + 仅试看登录学员 + 一封 `course_published` 消息; 对照区与漏斗数字独立, 不能加总进阶段

### Tests for User Story 3 ⚠️ 写在前, 跑红

- [X] T022 [P] [US3] 在 `apps/api/tests/LearningFactFunnelServiceTest.php` 追加: 试看学员不进 `entitled`; `orders.succeeded` 与 `completed` 可不等; `publish_reach.recipient_count` 与 `entitled` 分区; `trial.note` / `orders.note` / `publish_reach.note` 为契约字面量
- [X] T023 [P] [US3] 在 `apps/admin/tests/LearningFactFunnelView.test.ts` 追加: 三个对照区与漏斗分区渲染; 完成课程读 stages 而非 orders.succeeded

### Implementation for User Story 3

- [X] T024 [US3] 在 `apps/api/app/service/LearningFactFunnelService.php` 实现 `trial` (登录学员、预览课节、打开时无生效访问权)、`orders` (至少 succeeded)、`publish_reach` (`type=course_published`)
- [X] T025 [US3] 在 `apps/admin/src/views/catalog/LearningFactFunnelView.vue` 用独立 `el-card` 展示试看 / 订单 / 发布触达, 使用 DTO `note`, 不提供「合计进漏斗」控件

**Checkpoint**: 商业动作与学习结果在同一页可分开读

---

## Phase 6: User Story 5 - 看报告时不影响学员正在记录的学习 (Priority: P1)

**Goal**: 漏斗只读; Progress / Entitlement / Order 写路径不调用漏斗服务; 页面标明非实时

**Independent Test**: 源码断言写路径无漏斗引用; 管理员刷新报告时学员仍能按既有规则提交有效进度

### Tests for User Story 5 ⚠️ 写在前, 跑红

- [X] T026 [P] [US5] 在 `apps/api/tests/LearningFactFunnelIsolationTest.php` 读 `ProgressService.php` / `EntitlementService.php` / `OrderService.php` 源码, 断言不含 `LearningFactFunnelService`
- [X] T027 [P] [US5] 在 `apps/admin/tests/LearningFactFunnelView.test.ts` 追加: 展示 `generated_at` 或「非实时」说明

### Implementation for User Story 5

- [X] T028 [US5] 确认 `apps/api/app/service/ProgressService.php`、`EntitlementService.php`、`OrderService.php` 不 import 漏斗服务; GET 不写业务表、不用 Redis
- [X] T029 [US5] 在 `apps/admin/src/views/catalog/LearningFactFunnelView.vue` 展示 `generated_at` (ISO-8601) 与非实时说明; 刷新只调 GET

**Checkpoint**: 写路径隔离可自动证明

---

## Phase 7: User Story 4 - 按访问权来源拆开同一门课的漏斗 (Priority: P2)

**Goal**: `source=all|free|purchase|activation_code`; 之和等于全部; 无因果文案

**Independent Test**: 已知三来源人数, 筛选后只含该来源, 全部 = 三者之和, UI 无「付费带来完成」

### Tests for User Story 4 ⚠️ 写在前, 跑红

- [X] T030 [P] [US4] 在 `apps/api/tests/LearningFactFunnelServiceTest.php` 追加: `all.entitled = free+purchase+activation_code`; `source=purchase` 不含 free; 先生效前试看不算支付成功来源的首次打开
- [X] T031 [P] [US4] 在 `apps/admin/tests/LearningFactFunnelView.test.ts` 追加: 来源切换带 `source` query; 文案无「付费带来完成」「发放提升学习」

### Implementation for User Story 4

- [X] T032 [US4] 在 `apps/api/app/service/LearningFactFunnelService.php` 按 `course_entitlements.source` 过滤; `all` 时满足求和不变量
- [X] T033 [US4] 在 `apps/admin/src/views/catalog/LearningFactFunnelView.vue` 增加来源筛选, 请求 `source` query

**Checkpoint**: 来源拆分可独立演示

---

## Phase 8: Polish & Cross-Cutting Concerns

**Purpose**: 范围、无有效课节、可选索引、清单核对

- [X] T034 [P] 在 `apps/api/tests/AuthorizeLeakTest.php` 增加 `GET /api/admin/v1/courses/42/learning-funnel` → `course_student.view`
- [X] T035 [P] 在 `apps/api/app/service/LearningFactFunnelService.php` 对无有效课节设 `no_effective_lesson=true`, 后三阶段为 0, 不把结构问题标成窗口内未转化
- [X] T036 跳过覆盖索引: 单课聚合在 PHPUnit 夹具下足够快, 未新增 `20260911000002_learning_fact_funnel_indexes.php`
- [X] T037 按 `specs/017-learning-fact-funnel/quickstart.md` 跑 contracts / `make test-api` 相关文件 / admin vitest; 改 contracts 后 `make rebuild-all`

**Checkpoint**: 可按 quickstart V1–V6 验收

---

## Dependencies & Execution Order

### Phase Dependencies

- **Setup (Phase 1)**: 无依赖
- **Foundational (Phase 2)**: 依赖 Setup; **阻塞全部 US**
- **US1 (Phase 3)**: 依赖 Foundational — MVP
- **US2 (Phase 4)**: 依赖 US1 的四阶段人数 (同一 service, 扩展 pending)
- **US3 (Phase 5)**: 依赖 US1 的进入队列口径 (对照区不得填进阶段)
- **US5 (Phase 6)**: 依赖 Foundational (隔离测试); 可与 US2/US3 并行
- **US4 (Phase 7)**: 依赖 US1; P2
- **Polish (Phase 8)**: 依赖已交付的 US

### User Story Dependencies

- **US1 (P1)**: Foundational 之后即可, 不依赖其他故事 — **MVP**
- **US2 (P1)**: 扩展 US1 service/UI, 可单独测窗口
- **US3 (P1)**: 扩展对照区, 可单独测分区
- **US5 (P1)**: 不改口径, 只锁写路径与非实时展示
- **US4 (P2)**: 扩展 source 过滤

### Within Each User Story

- 测试先写并先红
- Service 口径先于 UI
- 故事完成后再提高优先级下一项

### Parallel Opportunities

- T001 完成后 T002 / T003 可并行
- T004 后 T005 / T006 / T008 / T010 可并行
- 每个 US 的测试任务标 [P] 的可并行
- Foundational 完成后 US5 隔离测试可与 US2/US3 实现并行 (不同测试文件)

---

## Parallel Example: User Story 1

```bash
# 测试并行:
Task: "LearningFactFunnelServiceTest.php 10/6/4/2"
Task: "LearningFactFunnelRouteTest.php 路由与中间件"
Task: "LearningFactFunnelView.test.ts 四阶段与 disclaimer"

# 实现顺序:
Task: "LearningFactFunnelService.php 四阶段口径"
Task: "LearningFactFunnelController.php 返回 DTO"
Task: "LearningFactFunnelView.vue + CourseStudentView 入口"
```

---

## Implementation Strategy

### MVP First (User Story 1 Only)

1. Phase 1 Setup
2. Phase 2 Foundational
3. Phase 3 US1
4. **STOP**: 用 quickstart V1 验收后再做窗口 / 对照区

### Incremental Delivery

1. Setup + Foundational
2. US1 四阶段 MVP
3. US2 窗口与尚未开始
4. US3 试看 / 订单 / 发布触达
5. US5 写路径隔离 (可提前插入)
6. US4 来源拆分
7. Polish

### Parallel Team Strategy

Foundational 完成后: A 做 US1→US2, B 做 US5 隔离测试, C 准备 US3 对照区夹具.

---

## Notes

- [P] = 不同文件且无未完成依赖
- 不改 `ProgressService` 热路径, 不引入 Redis, 不新建漏斗业务表
- 文案只来自契约字面量
- 提交粒度: 一个可独立回滚的故事或 Setup/Foundational
- 跳过 T036 必须在 PR 说明查询已可接受
