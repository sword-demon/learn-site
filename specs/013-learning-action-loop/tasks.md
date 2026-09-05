---
description: "学习行动循环的依赖有序实施任务"
---

# Tasks: 学习行动循环

**Input**: 设计文档来自 `/specs/013-learning-action-loop/`

**Prerequisites**: [plan.md](./plan.md)、[spec.md](./spec.md)、[research.md](./research.md)、[data-model.md](./data-model.md)、[contracts/learner-action-loop.md](./contracts/learner-action-loop.md)、[quickstart.md](./quickstart.md)

**Tests**: 规格为每个用户故事定义了独立测试，以下测试任务必须先于对应实现任务编写，并在实现前确认失败。

**Organization**: 任务按用户故事组织；`[P]` 只表示不同文件且不存在未完成依赖的任务可以并行。

## Phase 1: Setup（共享测试准备）

**目的**：为各故事提供可重复的领域夹具，不改变生产状态模型。

- [X] T001 [P] 在 `apps/api/tests/LearningActionLoopFixtures.php` 提供仅供测试使用的学员、已发布课程/课节、访问权、进度、地图、收藏、订单、优惠券和消息夹具，并支持固定 `Asia/Shanghai` 时间窗口

---

## Phase 2: Foundational（阻塞性基础）

**目的**：完成提醒评估记录、共享契约和订单截止时间事实；本阶段完成前不得开始用户故事实现。

**⚠️ CRITICAL**：所有用户故事依赖本阶段。

- [X] T002 [P] 为 `learner_reminder_evaluations` 编写迁移约束测试，覆盖唯一事件键、状态枚举、索引、外键和 `down` 回滚行为，文件为 `apps/api/tests/LearnerReminderEvaluationMigrationTest.php`
- [X] T003 [P] 先编写 next-action 与通知资源字段的失败契约测试，覆盖 `LearnerNextActionDTO`、`learning_reminder`、`resource_path` 和资源不可用原因，文件为 `packages/contracts/src/__tests__/learningAction.test.ts`
- [X] T004 [P] 编写订单截止时间复用测试，确认现有 `created_at + 15 分钟` 规则在订单超时和提醒窗口中得到同一结果，文件为 `apps/api/tests/OrderExpiryTest.php`
- [X] T005 [P] 创建 `learner_reminder_evaluations` 的 Phinx `up/down` 迁移，包含字段、唯一键、索引、外键和字符集约束，文件为 `apps/api/database/migrations/20260904000002_learning_action_loop.php`
- [X] T006 [P] 创建继承 `support\think\Model` 的提醒评估模型并声明表名、可写字段和状态语义，文件为 `apps/api/app/model/LearnerReminderEvaluation.php`
- [X] T007 [P] 实现并导出 next-action 及通知资源的共享 Zod schema，更新 `packages/contracts/src/learningAction.ts`、`packages/contracts/src/notification.ts` 和 `packages/contracts/src/index.ts`
- [X] T008 在 `apps/api/app/service/OrderService.php` 抽出可被订单超时处理和提醒服务共同调用的截止时间计算，保持既有订单状态转换不变

**Checkpoint**：迁移可前向执行和回滚，think-orm 模型可用，共享契约测试通过，订单截止时间只有一个服务端推导来源。

---

## Phase 3: User Story 1 - 登录后获得唯一下一步行动（Priority: P1）🎯 MVP

**目标**：登录学员通过服务端实时事实获得一个稳定、可解释且可访问性已校验的主行动。

**独立测试**：为同一学员准备进行中的授权课程、地图下一步和新收藏课程，验证 `GET /api/learner/v1/me/next-action` 只返回一个稳定主行动；未登录返回 `401`，目标失效时返回替代行动或明确不可用结果。

### Tests for User Story 1

- [X] T009 [P] [US1] 为 `LearningActionService` 编写固定优先级、同级稳定排序、空结果和依赖失败降级测试，文件为 `apps/api/tests/LearningActionServiceTest.php`
- [X] T010 [P] [US1] 为 next-action controller 编写鉴权、`Cache-Control: private, no-store`、响应字段和资源访问边界测试，文件为 `apps/api/tests/LearningActionControllerTest.php`
- [X] T011 [P] [US1] 为学习端首页编写只突出一个行动、显示标题/原因/目标和降级状态的组件测试，文件为 `apps/web/tests/LearningActionHome.test.ts`

### Implementation for User Story 1

- [X] T012 [US1] 实现从访问权、课程进度、地图、收藏、订单、优惠券和未读资源消息构造候选并按固定规则稳定排序的服务，文件为 `apps/api/app/service/LearningActionService.php`
- [X] T013 [US1] 实现受学习端鉴权保护的 `GET /api/learner/v1/me/next-action` controller，并在 `apps/api/app/route.php` 注册路由和私有无缓存响应
- [X] T014 [US1] 在 `apps/web/src/api/learningAction.ts` 调用 next-action 接口并使用 `ApiResponse(LearnerNextActionDTO).parse(data)` 运行时校验，禁止 Axios 泛型或类型断言绕过契约
- [X] T015 [US1] 在 `apps/web/src/views/home/HomeView.vue` 接入登录后的主行动展示、原因说明、服务端目标跳转、不可用状态和服务端降级入口；不复制进度、访问权或地图状态

**Checkpoint**：US1 可单独演示；刷新、重复登录和不同设备在相同服务端输入下只得到同一个主行动。

---

## Phase 4: User Story 2 - 完成学习后更新下一步（Priority: P1）

**目标**：课节或课程完成后立即依据最新服务端进度重新计算下一步，并在跨设备场景保持一致。

**独立测试**：完成含两个课节的课程第一节后，下一步转向第二节；完成课程后转向地图下一门有效课程或其他候选；设备 B 刷新后不得看到设备 A 之前的旧行动。

### Tests for User Story 2

- [X] T016 [P] [US2] 编写完成课节、课程完成、地图推进、无访问权跳过和并发旧状态提交测试，文件为 `apps/api/tests/LearningActionProgressRefreshTest.php`
- [X] T017 [P] [US2] 编写学习端收到权威进度结果后重新请求 next-action、跨设备刷新和完成后不使用本地旧状态的测试，文件为 `apps/web/tests/LearningActionProgressRefresh.test.ts`

### Implementation for User Story 2

- [X] T018 [US2] 在 `apps/api/app/service/LearningActionService.php` 确保每次重算重新读取 `ProgressService` 写入的进度、访问权和地图事实，跳过已下架、已归档或无权的下一目标
- [X] T019 [US2] 在 `apps/web/src/views/learn/LessonView.vue` 对完成成功响应立即调用 `fetchNextAction` 并替换当前主行动；不得从 `last_lesson_id`、进度百分比或本地地图推断下一步

**Checkpoint**：US1 和 US2 均可独立验证；完成结果仍由 `ProgressService` 的既有事务和单调规则负责，next-action 不接受客户端状态回写。

---

## Phase 5: User Story 3 - 从固定事件提醒进入相关行动（Priority: P1）

**目标**：四类固定事件写入既有学习端消息中心，并返回服务端重新校验的资源路径或降级入口。

**独立测试**：分别制造收藏课程超过 24 小时未开始、待支付订单进入 24 小时、优惠券进入 3 个自然日和连续 7 个自然日未学习，运行评估后验证消息 kind、原因、资源和点击结果。

### Tests for User Story 3

- [X] T020 [P] [US3] 编写四类提醒规则的命中窗口、候选选择和事件结束条件测试，文件为 `apps/api/tests/LearningReminderRuleTest.php`
- [X] T021 [P] [US3] 编写课程、课节、地图、订单和优惠券资源的本人校验、状态复核、路径和失效降级测试，文件为 `apps/api/tests/LearnerNotificationResourceTest.php`
- [X] T022 [P] [US3] 编写学习端消息列表展示 `learning_reminder`、原因、资源入口和不可用说明的组件测试，文件为 `apps/web/tests/StudentCenterReminder.test.ts`

### Implementation for User Story 3

- [X] T023 [US3] 实现收藏未开始、订单临期、优惠券临期和长期未学习四类候选评估及规则/原因码，文件为 `apps/api/app/service/LearningReminderService.php`
- [X] T024 [US3] 实现每 5 分钟批量 200 名学员的提醒 handler，并在 `apps/api/database/seeds/ScheduledTaskSeeder.php` 注册 `learner.reminder.evaluate` 任务
- [X] T025 [US3] 让 `MessageService` 以既有消息收件箱和确定性幂等键写入 `learning_reminder`，并在 `apps/api/app/controller/learner/NotificationController.php` 按当前学员重新解析资源可用性、`resource_path` 和不可用原因
- [X] T026 [US3] 在 `apps/web/src/api/notifications.ts` 使用更新后的 Zod 消息契约解析资源字段，保留既有未读/已读和错误处理边界
- [X] T027 [US3] 在 `apps/web/src/views/me/StudentCenterView.vue` 增加提醒标题、原因、资源跳转和失效说明；GET 消息不自动标记已读，点击后仍走原有资源鉴权

**Checkpoint**：US3 可以不依赖首页推荐单独运行；离线生成的提醒可在消息中心读取，Push/实时提示不是消息事实来源。

---

## Phase 6: User Story 4 - 在低噪音边界内接收提醒（Priority: P1）

**目标**：统一处理事件去重、72 小时节流、收藏/长期未学习周期、每日 3 条上限、勿扰时段、并发评估和失败重试。

**独立测试**：同一学员同时命中四类规则，重复运行评估并跨越 `22:00–08:00`，验证消息不重复、每日最多 3 条、优先保留顺序正确、勿扰不主动触达且次日不洪峰补发。

### Tests for User Story 4

- [X] T028 [P] [US4] 编写唯一事件键、72 小时节流、收藏关系一次、长期未学习每周一次和事件状态转换测试，文件为 `apps/api/tests/LearningReminderThrottleTest.php`
- [X] T029 [P] [US4] 编写学员行锁并发评估、每日 3 条上限、管理员/必要系统消息排除、勿扰时段和失败重试测试，文件为 `apps/api/tests/LearningReminderConcurrencyTest.php`

### Implementation for User Story 4

- [X] T030 [US4] 在 `apps/api/app/service/LearningReminderService.php` 以学员行锁和事务串行化评估，使用唯一事件键、`last_sent_at`、`suppressed_at` 和 `send_count` 实现节流、周期规则、每日上限、低优先级跳过和状态记录
- [X] T031 [US4] 在 `apps/api/app/scheduled/handler/LearningReminderHandler.php` 实现 `Asia/Shanghai` 勿扰窗口、批次不重入、失败可重试、Push 失败不回滚消息以及结构化任务结果汇总

**Checkpoint**：US3 和 US4 均可独立验收；同一事件重复评估不会增加消息或未读计数，管理员消息不受自动提醒限额影响。

---

## Phase 7: User Story 5 - 理解并完成统一循环（Priority: P2）

**目标**：把行动解释、提醒资源、学习结果和下一步更新串成一条不复制客户端状态的完整路径。

**独立测试**：从一条提醒进入课程、完成课节、刷新首页和消息中心，验证消息读取状态、课程/地图进度、下一步和资源说明各自准确；服务端依赖失败时只显示已确认的降级结果。

### Tests for User Story 5

- [X] T032 [P] [US5] 编写提醒到课程、课程完成到 next-action、资源失效、学员隔离和服务端降级的 API 集成测试，文件为 `apps/api/tests/LearningActionLoopIntegrationTest.php`
- [X] T033 [P] [US5] 编写首页行动、消息资源点击、完成后刷新、解释文本和实时提示不可用降级的学习端集成测试，文件为 `apps/web/tests/LearningActionLoop.test.ts`

### Implementation for User Story 5

- [X] T034 [US5] 在 `apps/web/src/views/home/HomeView.vue`、`apps/web/src/views/me/StudentCenterView.vue` 和 `apps/web/src/views/learn/LessonView.vue` 串联“查看原因→进入资源→提交权威结果→重新读取下一步”的用户路径，并保留加载、错误和不可用状态
- [X] T035 [US5] 在 `apps/api/app/service/LearningActionService.php` 和 `apps/api/app/controller/learner/NotificationController.php` 统一解释字段、资源失效降级和本人权限复核语义，确保服务端失败时客户端不猜测课程、进度、访问权或提醒状态

**Checkpoint**：完整学习行动循环可端到端完成；不存在第二套 next-action、课程进度、访问权、地图进度或消息未读状态。

---

## Phase 8: Polish & Cross-Cutting Concerns

**目的**：同步设计文档、执行 Compose 质量门禁和完成安全/性能验收。

- [X] T036 复核实现与 `specs/013-learning-action-loop/plan.md`、`research.md`、`data-model.md` 和 `contracts/learner-action-loop.md` 一致，补齐实际字段、迁移回滚和失败语义
- [X] T037 按 `specs/013-learning-action-loop/quickstart.md` 执行 `make test`、`make phpstan`、`make verify-migrations` 和 `make verify-runtime-boundaries`，记录失败原因和修复结果
- [ ] T038 在独立 Compose project 中按 `specs/013-learning-action-loop/quickstart.md` 执行 `make test-e2e`、跨设备旅程和必要的 `make test-perf`，完成后执行 `make e2e-down`
  - 备注：Admin E2E `4/4` 通过；Web 注册 smoke 因既有 `getByLabel('图形验证码')` 同时命中输入框和刷新按钮失败；`make test-perf` 尚未执行。
- [X] T039 对 `apps/api/app/service/LearningActionService.php`、`apps/api/app/service/LearningReminderService.php`、`apps/api/app/controller/learner/NotificationController.php`、`apps/web/src/api/learningAction.ts` 和 `packages/contracts/src/` 做最终安全/契约审查，确认无越权资源、客户端状态副本、秘密或调试配置泄漏，并运行 `git diff --check`

---

## Dependencies & Execution Order

### Phase Dependencies

- **Setup（Phase 1）**：无依赖；T001 可立即执行。
- **Foundational（Phase 2）**：依赖 T001；T002-T004 可并行，测试确认后 T005-T008 完成基础能力。
- **User Story 1（Phase 3）**：依赖 Foundational；T009-T011 可并行，随后按 T012 → T013 → T014 → T015 实现。
- **User Story 2（Phase 4）**：依赖 US1；T016-T017 可并行，随后完成 T018-T019。
- **User Story 3（Phase 5）**：仅依赖 Foundational，可与 US1 并行；T020-T022 可并行，随后完成 T023-T027。
- **User Story 4（Phase 6）**：依赖 US3；T028-T029 可并行，随后完成 T030-T031。
- **User Story 5（Phase 7）**：依赖 US1、US2、US3、US4；T032-T033 可并行，随后完成 T034-T035。
- **Polish（Phase 8）**：依赖所有目标用户故事完成；T036 后执行 T037-T039。

### User Story Dependencies

- **US1（P1）**：Foundational 后可开始；不依赖其他用户故事，是 MVP。
- **US2（P1）**：依赖 US1 的 next-action 服务和学习端 API，但继续复用既有 `ProgressService`，不新增客户端进度写回。
- **US3（P1）**：Foundational 后可与 US1 并行；提醒写入既有消息中心，不依赖首页展示。
- **US4（P1）**：依赖 US3 的四类规则和投递路径，再增加节流、每日上限与勿扰边界。
- **US5（P2）**：依赖前四个故事，验证完整循环和降级一致性。

### Within Each User Story

- 测试任务先于实现任务，并在实现前确认失败。
- 模型/迁移和共享契约先于服务；服务先于 controller/前端调用；UI 集成最后完成。
- 同一文件的任务按编号顺序执行，跨文件且无未完成依赖的任务才标 `[P]`。
- 每个 Checkpoint 都必须能独立运行对应故事的测试和手工验收。

## Parallel Example: User Story 1

```text
T009 apps/api/tests/LearningActionServiceTest.php
T010 apps/api/tests/LearningActionControllerTest.php
T011 apps/web/tests/LearningActionHome.test.ts
```

## Parallel Example: User Story 3

```text
T020 apps/api/tests/LearningReminderRuleTest.php
T021 apps/api/tests/LearnerNotificationResourceTest.php
T022 apps/web/tests/StudentCenterReminder.test.ts
```

## Parallel Example: User Story 4

```text
T028 apps/api/tests/LearningReminderThrottleTest.php
T029 apps/api/tests/LearningReminderConcurrencyTest.php
```

## Implementation Strategy

### MVP First（只交付 User Story 1）

1. 完成 Phase 1：测试夹具。
2. 完成 Phase 2：迁移、模型、契约和订单截止时间事实。
3. 完成 Phase 3：唯一主行动及学习端首页展示。
4. 在 `apps/api/tests/` 和 `apps/web/tests/` 独立验证 US1，再决定是否进入提醒工作。

### Incremental Delivery

1. Foundation 完成后先交付 US1，证明“服务端事实 → 一个可解释行动”。
2. 增加 US2，证明“完成学习 → 跨设备刷新下一步”。
3. 增加 US3，证明四类事件可进入既有消息资源。
4. 增加 US4，证明提醒不会制造重复、夜间噪音或补发洪峰。
5. 增加 US5 和 Phase 8，完成完整闭环及 Compose 验收。

### Parallel Team Strategy

Foundation 完成后，US1 与 US3 可以由不同执行者并行；US2 继续由 US1 执行者推进，US4 等待 US3，US5 等待前四个故事。任何共享文件（尤其 `LearningActionService.php`、`LearningReminderService.php`、`HomeView.vue` 和 `StudentCenterView.vue`）仍须按任务编号串行合并。

## Notes

- 所有任务均使用 `- [ ] Txxx` 清单格式；用户故事任务带有对应 `[USn]` 标签，Setup、Foundational 和 Polish 不带故事标签。
- `[P]` 只表示文件和依赖允许并行，不代表可以绕过测试先行或共享文件的顺序要求。
- 不新增推荐模型、next-action 状态表、Redis 业务键、独立收件箱或前端学习状态副本。
- 订单提醒沿用 `created_at + 15 分钟`；真正长时订单的“提前一天”语义需另立订单生命周期规格。
