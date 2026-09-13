# HANDOFF — learn-site

> **更新时间**: 2026-09-13
> **分支**: `main`,与 `origin/main` 同步(HEAD `bcea699`)
> **工作目录**: `/Volumes/MOVESPEED/ai-coding/learn-site/`
> **权威约定**: 先读根目录 `AGENTS.md`(容器执行、issue tracker、lessons、webman 技能等)与 `.specify/memory/constitution.md`

---

## 1. 仓库现状

学习站 monorepo(`apps/api` Webman + `apps/web` 学员端 + `apps/admin` 管理端 + `packages/contracts` Zod 契约)。`specs/` 下 17 个特性全部实现完毕,进度以各 `specs/<feature>/tasks.md` 为唯一来源(勿在本文档重复勾选状态):

| 特性 | 状态 |
|---|---|
| 001–015(建站 → 支付 → 学习闭环 → 运维) | 全部完成,细节见各自 tasks.md 与 git 历史 |
| **016-course-distribution(课程分销)** | **94/95**,唯一未勾 T091(见 §3) |
| **017-learning-fact-funnel(学习事实漏斗)** | 37/37 完成,随全量门禁验证通过 |

最近一轮验证(2026-09-12/13)全绿:`make test-api`(580 测试 + phpstan)、`make test-web`(web 106 + admin 189 个 vitest + lint + typecheck + 双端生产构建)、`make test-e2e`(Playwright 6 条)、`make rebuild-all` 后 api/web/admin 容器 healthy。

## 2. 刚结束的工作:016 课程分销

- 任务与验收: `specs/016-course-distribution/tasks.md`;合规场景矩阵: `specs/016-course-distribution/quickstart.md`;工程教训: `tasks/lessons.md` Lesson 5/6
- 落地提交: `f24f837`(后端+14 测试)、`f6dbb82`(管理端)、`f627e94`(学习端守卫)、`cda2d77`(测试超时)、`bb14be4`/`3ec931c`(017 相关修复)、`e6068b3`(文档回写)、`bcea699`(精简 + README)
- 由测试暴露并已修复的 3 个实现 bug:绑定成功后未回写 `share_visits.bound_learner_id`;课程覆盖写了不存在的 `updated_by` 列且 think-orm `update()` 静态语义导致读到旧值(改 `save()`);CSV 导出 `referrer_masked_phone` 列错输出被推荐人手机号
- 精简(已在 `bcea699`):佣金列表与 override 列表 N+1 批量化;`voidByAdmin` 去除对通用 `audit_log` 的重复写入;合规常量收敛到 `DistributionConfigService`;截断审计补订单号

## 3. 遗留与下一步

1. **T091(016 唯一未勾)**: quickstart 12 条 SC 的"手工记录结果"。SC-003/007/009/010/011 已有自动化测试证据(`LevelCapHardLimitTest` / `OrderRefundVoidCommissionTest`+e2e / `ExportCsvMaskingTest`+`LearnerPlaintextLeak` / `DistributionDisableFlowTest`+`DistributionRouteGuard` / `CommissionReplayTest`);真正缺的只有 **SC-012 可用性观察**(quickstart 自标注"不可自动化",需邀请 3+1 名管理员实操并记录通过率)。需要人工组织,agent 只能协助整理记录。
2. **无其他已知缺口**。新工作从新 `specs/<feature>`(走 speckit 流程)或 `.scratch/<feature>/` issue tracker 开始。

## 4. 环境与工作流要点(下一 agent 必读)

- **不在宿主机装 PHP/Node**: 一切验证走 Makefile / Docker Compose(见 AGENTS.md)。
- **api-test 镜像不挂载源码**: 改动 PHP 代码后必须先 `docker compose -f compose.yaml -f compose.test.yaml --profile test build api-test`,再跑测试,否则跑的是旧代码。
- **测试库 = 开发库**: 靠事务回滚隔离(`Db::startTrans()`/`rollback`);事务外提交的残留行会污染计数断言,需在 `setUp()` 内预清理(参照 `DistributionConfigServiceTest::setUp`)。
- **phpunit 只跑 `apps/api/phpunit.xml` testsuites 里登记的文件**: 新增测试必须登记,否则 `make test-api` 根本不执行它。
- **快速过滤回路**: `docker compose -f compose.yaml -f compose.test.yaml --profile test run --rm --entrypoint "" api-test sh -c 'php vendor/bin/phpunit --filter X'`;`make test-api` = phpunit + phpstan(phpunit 失败会短路 phpstan)。
- **路由**集中在 `apps/api/app/route.php`(非 config/route.php);**契约**在 `packages/contracts/src/`(Zod,两端强校验),契约变更后 `make rebuild-all`。
- **分销域约定**: 订单 succeeded / 退款窗口结束 / 退款是仅有的三个事件源,佣金钩子挂在既有状态迁移上,**禁止新 cron/MQ 兜底**;审计唯一入口 `DistributionAuditService::record`;合规硬约束 level_cap ≤ 3 三层防护(validator / DB CHECK / 测试);金额一律 int cents;库内时间 `Asia/Shanghai` DATETIME,API 出参 ISO-8601;手机号一律 `maskPhone()` 脱敏,CSV/导出/审计不得出现明文。
- 管理端权限点在 `apps/api/database/seeds/PermissionSeeder.php`(distribution.config / reconcile / audit)。

## 5. Suggested skills

> 实际执行前用 Skill tool 调用,不要直接动手。以下均为本环境真实可用的技能名。

| 场景 | skill |
|------|-------|
| 继续/启动 speckit 特性实现(按 specs/<feature>/tasks.md) | `speckit-implement` |
| 新特性规划(tasks/plan 生成) | `speckit-tasks` / `speckit-plan` |
| Webman 后端开发、诊断、验证 | `webman-development` |
| 新视图/service/composable 测试先行 | `tdd` |
| 提交前代码评审 / 坏味道扫描 | `code-review` / `smell` |
| 一组提交后回看 diff 沉淀 lessons | `lesson-learned` |
| bug 定位 | `diagnosing-bugs` |
| 会话交接 | `handoff` |

## 6. 不要做的事

- 不引入 `illuminate/database`(Constitution 禁止,栈是 webman + think-orm)
- 不新增 enum,用 string literal union(PHP 与 TS 皆然)
- 不为分销/订单一致性引入新 cron、MQ 或定时兜底
- 不绕过 `DistributionConfigValidator` 直写配置,不绕过 `DistributionAuditService::record` 散写审计
- 不在 controller 直接写 SQL;走模型或 `support\think\Db`
- 不在宿主机跑 PHP/Node 作为交付证据;一律容器内验证
- 不重新生成 `specs/*/tasks.md`(除非用户明确要求 `/speckit-tasks`)
- 不 hardcode secret / token / phone / password;日志不落敏感字段(FR-093)
- 测试镜像变更后不重复构建已同源码的镜像(AGENTS.md 约定)

## 7. 关键参考路径

- 工作区约定: `AGENTS.md`;宪法: `.specify/memory/constitution.md`;agent 工作流: `docs/agents/`
- 016 分销: `specs/016-course-distribution/{spec,plan,research,data-model,quickstart}.md`、`contracts/`、`tasks.md`
- 017 漏斗: `specs/017-learning-fact-funnel/`(tasks.md 37/37)
- 工程教训: `tasks/lessons.md`;进行中事项: `tasks/todo.md`(仅引用 specs,不重复状态)
- 契约: `packages/contracts/src/`(envelope.ts + catalog/distribution 等域文件)
- 分销测试样例模板: `apps/api/tests/CommissionSettleTest.php`(链路/订单夹具写法)与 `apps/api/tests/DistributionConfigServiceTest.php`(共享库预清理写法)
