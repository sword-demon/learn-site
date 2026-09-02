# Implementation Plan: 课程发布通知, 意见反馈与激活码兑换

**Branch**: `010-course-notify-feedback-codes` | **Date**: 2026-09-02 | **Spec**: [spec.md](./spec.md)

**Input**: Feature specification from `/specs/010-course-notify-feedback-codes/spec.md`

**Note**: 全栈功能 — `apps/api` 扩展发布副作用, 激活码与反馈服务; `apps/admin` 课程下激活码/反馈页; `apps/web` 消息 kind, 兑换入口, 课程反馈; `packages/contracts` 共享 DTO. 实时通道复用既有 **webman/push** + **008 redis-queue fan-out**, 不新增 Composer 主依赖.

## Summary

课程进入已发布后, 自动向全体在册学员 fan-out `course_published` 收件箱消息 (可跳转课程详情, 在线走 webman/push), HTTP 发布与全体写入解耦. 管理端按课批量生成一次性激活码, 学员兑换后获得与支付成功同等的课程访问权. 有访问权的学员可提交私密富文本意见反馈, 管理员按课处理. 三者均走 service 私有方法 + `writeAudit`, 时区 `Asia/Shanghai`.

## Technical Context

**Language/Version**: PHP 8.4 (Webman 2.2); TypeScript 5.x strict; Vue 3.5; Node.js 22 LTS

**Primary Dependencies**:
- 后端既有: `webman/think-orm`, `webman/push`, `webman/redis-queue:~2.1`, Phinx, `HtmlSanitizer`, `JobDispatcher`, `UnreadCounterService`, `EntitlementService`, `NotificationDispatchService`, `NotificationFanOutExecutor`
- 管理端/学习端既有: Vue 3, Element Plus, Pinia, Axios, Zod, `@wangeditor/editor` (学习端复用 `CheckinPlanEditor`)
- 无新 Composer/npm 主依赖

**Storage**: MySQL 8 — 扩展 `notification_dispatches` (`type`, `resource_*`), `course_entitlements.source`; 新表 `activation_code_batches`, `activation_codes`, `course_feedbacks`. Redis 仅复用 008 队列/未读计数 + 既有 `RateLimit` 键, 不把激活码名额放进 Redis.

**Testing**: PHPUnit (`CoursePublishNotifyTest`, `ActivationCodeTest`, `CourseFeedbackTest`); Vitest (StudentCenterView kind, RedeemView, CourseDetailView 反馈/兑换, admin 两页); `packages/contracts` Vitest

**Target Platform**: Docker Compose `api` + `admin` + `web`; push `:3131` / `:3232`; queue consumer 随 api 进程

**Project Type**: Monorepo — `apps/api` + `apps/admin` + `apps/web` + `packages/contracts`

**Performance Goals**:
- SC-001: 发布确认 p95 ≤ 5s (不含等 fan-out)
- SC-002: 1 万学员投递 ≤ 5 分钟, 期间消息接口错误率 < 1%
- SC-003: 在线学员消息落库后 p90 ≤ 5s 看到未读变化
- 兑换/反馈常规规模 ≤ 2s 服务端往返

**Constraints**:
- think-orm 唯一 ORM; 令牌隔离; 审计走 service `writeAudit`
- 发布失败补投不得回滚课程状态
- 激活码明文只在生成响应出现; 哈希入库
- 意见反馈不得进入 `reviews`
- `EntitlementService::grant` 对已有授权的幂等 **不得** 用于兑换耗码
- H6: `/me/redeem` 的 tab 由 `route.path` 派生

**Scale/Scope**: 6 个用户故事, 29 条 FR; 预计新增/修改 ~55 源文件; 1 张迁移 (多表+枚举扩展)

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

| 门禁 | 结论 | 说明 |
|------|------|------|
| I. 容器即运行契约 | PASS | 无新进程; 复用 api 容器内 push + redis-queue |
| II. 稳定兼容且可复现 | PASS | 无新运行时依赖 |
| III. 契约优先与端到端类型安全 | PASS | 扩展 `notification.ts` / `courseStudent.ts` / `catalog` 相关; 新增 activation/feedback Zod |
| IV. 数据变更安全可追溯 | PASS | 单次 Phinx 迁移; ENUM 扩展有 down; 审计日志 |
| V. 质量、安全与可运维性内建 | PASS | PHPUnit/Vitest 同 commit; 兑换限流; HTML 白名单 |
| VI. 令牌鉴权 | PASS | 管理/学习令牌隔离; push 频道仍绑学员 account_id |
| Redis 使用边界 | PASS | 队列/未读/RateLimit 均在 008 与既有中间件授权范围内; 码库存 MySQL |
| 双前端独立构建 | PASS | admin/web 各自页面与测试 |

**Phase 1 复查**: `NotificationDispatchService` 仍是 fan-out 唯一入口; `ActivationCodeService` / `CourseFeedbackService` 集中校验与审计; `CourseService::publishCourse` 只编排「改状态 + 触发投递」. Controller 薄层. 无并行 ORM, 无第二套推送, 无 Eloquent.

## Project Structure

### Documentation (this feature)

```text
specs/010-course-notify-feedback-codes/
├── spec.md
├── plan.md
├── research.md
├── data-model.md
├── quickstart.md
├── contracts/
│   ├── notifications.md
│   ├── admin-activation-codes.md
│   ├── learner-activation-codes.md
│   ├── admin-course-feedback.md
│   └── learner-course-feedback.md
└── checklists/
    └── requirements.md
```

### Source Code (repository root)

```text
apps/api/
├── database/
│   ├── migrations/20260902000001_course_notify_feedback_codes.php
│   └── seeds/PermissionSeeder.php          # + activation_code.manage, course_feedback.manage
├── app/
│   ├── controller/
│   │   ├── admin/
│   │   │   ├── CourseController.php        # publish 传入 staffId
│   │   │   ├── NotificationController.php  # + retry, type 筛选
│   │   │   ├── ActivationCodeController.php
│   │   │   └── CourseFeedbackController.php
│   │   └── learner/
│   │       ├── ActivationCodeController.php
│   │       └── CourseFeedbackController.php
│   ├── service/
│   │   ├── CourseService.php               # publish 后 sendCoursePublished
│   │   ├── NotificationDispatchService.php # sendCoursePublished + retryFanOut
│   │   ├── NotificationFanOutExecutor.php  # 复制 resource_*; course_published 收件人=全体在册
│   │   ├── EntitlementService.php          # source=activation_code
│   │   ├── CourseStudentService.php        # source 筛选
│   │   ├── ActivationCodeService.php
│   │   └── CourseFeedbackService.php
│   ├── model/CourseEntitlement.php         # isRevocable 不变
│   ├── middleware/
│   │   ├── Authorize.php
│   │   └── RateLimit.php                   # redeem 规则
│   └── route.php
└── tests/
    ├── CoursePublishNotifyTest.php
    ├── ActivationCodeTest.php
    └── CourseFeedbackTest.php

apps/admin/
├── src/
│   ├── api/activationCodes.ts
│   ├── api/courseFeedback.ts
│   ├── api/notifications.ts                # + retry, fan_out 字段
│   ├── layouts/AdminMenu.ts                # 不新增顶栏; 从课程进
│   ├── router/index.ts                     # /courses/:id/activation-codes|feedback
│   └── views/
│       ├── catalog/CourseActivationCodesView.vue
│       ├── catalog/CourseFeedbackView.vue
│       └── notifications/NotificationListView.vue  # type=course_published
└── tests/
    ├── CourseActivationCodesView.test.ts
    └── CourseFeedbackView.test.ts

apps/web/
├── src/
│   ├── api/activationCodes.ts
│   ├── api/courseFeedback.ts
│   ├── router/index.ts                     # /me/redeem
│   ├── views/me/StudentCenterView.vue      # kind + TAB_BY_PATH redeem
│   ├── views/catalog/CourseDetailView.vue  # 兑换入口 + 反馈 tab
│   └── views/catalog/AccessGate.vue        # 可选: 付费未授权时引导兑换
└── tests/
    ├── StudentCenterView.test.ts           # kind 标签与跳转
    ├── RedeemTab.test.ts
    └── CourseFeedbackSubmit.test.ts

packages/contracts/src/
├── notification.ts                         # + course_published
├── adminNotification.ts                    # + type/resource/fan_out
├── courseStudent.ts                        # source + activation_code
├── activationCode.ts                       # 新增
├── courseFeedback.ts                       # 新增
└── index.ts

CONTEXT.md                                  # 领域词扩展
```

**Structure Decision**: 发布通知不新开 Message 管道, 只扩展 dispatch 类型并由 fan-out 复制课程资源. 激活码与反馈按课挂路由, 各自一个 service, 不塞进 `CourseService` (避免发布/目录编辑与兑换/工单混在一起). 学习端兑换是 H6 新 tab, 反馈留在课程详情, 避免再拆一个学员中心 tab.

## Complexity Tracking

无宪章违规项, 本节留空.

## Phase 0 产出

见 [research.md](./research.md) (R1–R10: fan-out 复用, webman/push, 补投, 码哈希, entitlement 第三源, 并发与限流, 反馈隔离, 权限, H6 挂载, CONTEXT).

## Phase 1 产出

| 产物 | 路径 |
|------|------|
| 数据模型 | [data-model.md](./data-model.md) |
| 通知扩展 | [contracts/notifications.md](./contracts/notifications.md) |
| 管理端激活码 | [contracts/admin-activation-codes.md](./contracts/admin-activation-codes.md) |
| 学习端兑换 | [contracts/learner-activation-codes.md](./contracts/learner-activation-codes.md) |
| 管理端反馈 | [contracts/admin-course-feedback.md](./contracts/admin-course-feedback.md) |
| 学习端反馈 | [contracts/learner-course-feedback.md](./contracts/learner-course-feedback.md) |
| 验证指南 | [quickstart.md](./quickstart.md) |

## 实现阶段建议 (供 `$speckit-tasks`)

1. **Foundation**: 迁移, 权限种子, contracts Zod, CONTEXT.md 词条
2. **US1 发布通知**: `sendCoursePublished`, fan-out 复制 resource, publish 编排, 学习端 kind, 管理端列表/retry
3. **US2+US3 激活码**: `ActivationCodeService` + admin/web 兑换与名单 source
4. **US4+US5 反馈**: `CourseFeedbackService` + 课程详情提交 + 管理端处理
5. **US6 入口抛光**: `/me/redeem` H6 tab, 详情购买区兑换, 消息标签
6. **Polish**: 审计, 限流测试, 并发兑换, 发布入队失败不回滚, quickstart 走查

## 风险与缓解

| 风险 | 缓解 |
|------|------|
| 发布 API 因入队失败而 500, 课已发布 | 捕获队列异常, dispatch=failed, HTTP 仍成功 |
| `grant()` 幂等吞掉「已有授权仍耗码」 | 兑换在 grant 之前查 active, 有则抛错 |
| ENUM 变更在已有库失败 | 迁移显式 `MODIFY`/`changeColumn` 含旧值+新值, 集成测试读源 |
| 学习端多 tab 状态漂移 | `/me/redeem` 只加 `TAB_BY_PATH`, 测试改 `route.path` |
| 反馈 XSS | 只存 `HtmlSanitizer` 输出; 前端 v-html 注明服务端已消毒 |
| 激活码明文二次泄露 | 响应 `codes[]` 不写审计详情; 列表只有 prefix/suffix |
