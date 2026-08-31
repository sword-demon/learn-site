# API 十万级规模扩展 (008)

日期: 2026-09-01
范围: `apps/api` 队列、Token 索引、读缓存、部署调参。规格: `specs/008-api-scale-100k/`。

## 计划

- [x] Phase 1 PR-1: ext-event、worker/pool、reusePort（tasks T001–T005）
- [x] Phase 2 PR-2: webman/redis-queue 骨架（T006–T010）
- [x] Phase 3 PR-3: 公告异步 fan-out + 迁移（T011–T017）— MVP
- [x] Phase 4 PR-4: Token 索引 kick（T018–T022）— MVP
- [x] Phase 5 PR-5: 未读 Redis 计数（T023–T030）
- [x] Phase 6 PR-6: 首页读缓存（T031–T034）
- [x] Phase 7 PR-7: 支付/定时入队（T035–T040）
- [x] Phase 8 PR-8: health、perf 脚本、web Nginx 媒体直出（T041–T045）

## 审查

（实施完成后填写）

---

# 学习端导航栏重排

日期: 2026-08-30
范围: `apps/web` 顶栏. 截图问题是登录后 7 个菜单和品牌, 账户挤在一行, 中文被拆开折行.

## 计划

- [x] 顶栏改成两行: 品牌 + 工具在上, 主导航独占下一行
- [x] 菜单文字 `nowrap`, 长昵称截断, 窄屏仍走汉堡菜单
- [x] 补 LearnerLayout 结构测试和 CSS 契约
- [x] 桌面 / 平板 / 手机宽度核对, 登录页顶栏不受影响

## 审查

根因是 `.masthead-inner` 一行塞品牌, 7 个菜单, 账户, 且菜单未 `nowrap`, 中文被拆行.

改成学园两行顶栏后, 1180 / 768 菜单单行; 720 以下汉堡; 420 以下只留印章和图标. 登录页仍是单行品牌. `LearnerLayout.test.ts` 4 项通过. Playwright 截过 1440 / 1180 / 1024 / 768 / 560 / 375 和夜读, 地图页激活态.

---

# 完善领域文档

日期: 2026-08-24
范围: 按 `/domain-modeling` 对齐 `CONTEXT.md` 与 `docs/adr/`. 对照 `specs/001-personal-learning-site/` 与当前代码. 不改业务代码.

## 计划

- [x] 重写 `CONTEXT.md`: 按身份/两端/登录/组织/内容/交易/学习/互动分组; 去掉 Redis/JWT/TTL 等实现细节; 补齐规格关键实体
- [x] 新增 ADR (仅满足难回滚 + 非显然 + 真实取舍): 不透明令牌可吊销, 地图不授权, 数据范围跟课程当前部门, 问答对已授权学员公开
- [x] 更新 `docs/agents/domain.md` 的 ADR 示例
- [x] 审查: 记录规格与代码用词漂移, 不在本次改表结构

## 取舍 (本次不做 ADR)

- Fake 支付适配器: 规格写明可替换, 易回滚
- 站内消息本期不做接口: 易回滚, 术语仍进词表
- 课节状态列名 `enabled|disabled` vs 规格 `archived`: 记录矛盾, 不在文档任务里改迁移
- 已完成不得回退 / 权限点种子目录: 已写入词条, 不足以单开 ADR

## 审查

词表以规格关键实体和已实现行为为准. 实现细节 (Redis TTL, JWT, Session) 从 `CONTEXT.md` 挪到 ADR-0003.

规格与代码仍不一致、文档不替代码改名的地方:

| 概念 | 规格 / 词表 | 当前代码 | 处理 |
|---|---|---|---|
| 章节/课节生命周期 | 归档 (`active`/`archived`) | `enabled`/`disabled` | 词表用归档; 代码列名未改 |
| 教师展示名称 | `teacher_display_name` | `teacher_name` | 词表用教师展示名称 |
| 优惠窗口字段 | `sale_starts_at` | `sale_start_at` | 词表不收录列名 |
| 课程访问权表 | `course_entitlements` | Phase 6 未落地; 非试看课节对未授权一律拒绝 | 词条保留, 不假装表已存在 |
| 消息 | 领域概念存在; 规格写明本期不做读写接口 | 学习端有 `MessagesView` 壳 | 词条保留 |

## 审查 2 (2026-08-24): 代码评审 25 项

日期: 2026-08-24
范围: `/code-review` A=2 (`apps/api/app/` 全量), 颗粒度 full, 规格源 spec.md + data-model + contracts/. 25 项按 A 实 bug / B 死代码 / C 重构 / D 漂移 重新分桶.

### 已执行 (B 类)

- [x] B1: 删 `apps/api/app/process/Monitor.php` (298 行 webman 3rd-party 脚手架, 命名空间 `app\process` 不匹配)
- [x] B2: 删 `apps/api/app/middleware/StaticFile.php` (42 行, 命名空间 `app\middleware` 不匹配)
- [x] B3: 删 `apps/api/app/functions.php` (4 行空壳)

### 跳过 (改完会破契约或前端, 移到 D 类漂移)

- [ ] **D5**: `route.php:207` `/moderation-logs` 别名. 规格 `contracts/admin-api.md:61` 显式要求该端点 (`audit.view` 权限). 保留.
- [ ] **D6**: `learner/AuthController::register` (route.php:12). 规格 `contracts/learner-api.md:9` 明确要求. 前端 `apps/web/src/api/learner.ts:80` 已消费. 保留.
- [ ] **D7**: `learner/NotificationController` + `/me/notifications` 路由 + `MessageService::emit` 的 `learner_notifications` 写入. `tasks.md:270-272` 标 T083-T085 跳过但实际已落地 — 是 spec/code drift. 不删, 留待统一处理.
- [ ] **D8**: `DashboardController::summary` 三个 null 字段 (`unanswered_questions`/`pending_reviews`/`broken_map_steps`). `apps/admin/src/views/dashboard/DashboardView.vue:173-175` 前端 mock 同步消费. 不删.
- [ ] **D9**: `ReviewService` 限制 16 vs 注释与规格写 3. 真值待业务确认 (3 太严, 16 太松).

### 可立刻做 (C 类便宜 refactor, 单文件, 无回归测试 seam 也能加)

- [x] **C5**: `StaffService.php:196-198` — 同事务内 `password_hash` 比对永远 true, 死代码. 删 if 保留 `$account->save()`.
- [x] **C7**: `RoleService.php:128-133` — if/else 两分支执行同样 `Role::where('id', $id)->update($patch)`. 删 if/else 保留单行.
- [x] **C12**: `ReviewService.php:290` 魔法数 16 → `private const MAX_REPLY_DEPTH = 16`. (注意规格写 3, 见 D9.)

### 真 bug (A 类, 无 red seam — finding noted)

按 diagnosing-bugs 纪律: A 类修必须先有 red-capable 测试. 当前 `apps/api/tests/` 全部是配置/结构断言, think-orm 无 in-memory 替身, 修真 DB 行为需要 docker 内 phpunit 跑 think-orm (用户已说自行跑测试).

| ID | 位置 | 缺陷 | 红 seam |
|---|---|---|---|
| A1 | `EntitlementService::revoke` | 不带 `source='free'` 守卫, 可撤销 purchase 授权 (违反 FR-023 "支付成功产生的访问权不得取消") | 无 — 需真 DB |
| A2 | `EntitlementService::viewerAuthorized` line 56-61 | `\Throwable` 静默吞所有异常, Redis 故障 → 用户看似匿名 (Standards §V) | 无 |
| A3 | `QuestionController::findThread` | 泄露 `author_staff_id` 给学员 (FR-045) | 无 |
| A4 | `Authorize::MAP` `/api/admin/v1/permissions` → `org.role` | 路由错配, 应为独立权限码 (Spec C1) | 单元测试可写 |
| A5 | 注册端点 `captcha_answer` 字段名 | 契约要求 `captcha` (Spec C4) | 集成测试可写 |
| A6 | `Logger::scrub` 仅顶层脱敏, 嵌套泄露 | 违反 FR-093 | 单元测试可写 |
| A7 | `learner/AuthController` captcha 在 phone 校验前消耗 | DoS 风险 (FR-091) | 单元测试可写 |

### 下一步建议 (给用户)

A 类中有 A4/A6/A7 可在用户跑 docker 之前补单元测试 (mock seam 足够). A1/A2/A3 必须真 DB, 等用户跑 docker 时才能验证. C5/C7/C12 是无副作用小改, 可现在合.

未做: 不改迁移、契约或 PHP. 下次改目录状态时, 应把代码列名收到「归档」, 而不是把词表改成「停用课节」.
