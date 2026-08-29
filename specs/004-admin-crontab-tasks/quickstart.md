# Quickstart 验证指南：管理后台自动任务管理

目标：在实现完成后，验证 [spec.md](./spec.md) 五条用户故事与 [contracts/admin-scheduled-tasks.md](./contracts/admin-scheduled-tasks.md) 行为契约。实现细节见后续 `tasks.md`（`$speckit-tasks` 生成）。

## 前置

```bash
docker compose up -d --build
docker compose exec api php vendor/bin/phinx migrate
docker compose exec api php vendor/bin/phinx seed:run
```

- 管理端 `${ADMIN_PORT:-8081}` 可访问
- 超级管理员或已授予 `scheduled_task.manage` 的账号
- 确认 `config/process.php` 含 `scheduled_tasks_runner`，且 **无** `notification_cleanup` 独立进程（避免重复清理）

## 自动化门禁（实现后执行）

```bash
# API
docker compose exec api composer test
docker compose exec api composer stan

# 契约
pnpm --filter @learn-site/contracts test

# 管理端
pnpm --filter @learn-site/admin lint
pnpm --filter @learn-site/admin typecheck
pnpm --filter @learn-site/admin test
pnpm --filter @learn-site/admin build
```

**预期新增测试**（实现阶段）:
- `apps/api/tests/ScheduledTaskExpressionTest.php` — 校验、最短间隔、next_run_at
- `apps/api/tests/ScheduledTaskControllerTest.php` — CRUD、权限、手动触发
- `apps/api/tests/ScheduledTaskRunnerTest.php` — 执行日志、skip、失败不崩进程
- `apps/admin/tests/ScheduledTaskListView.test.ts`
- `apps/admin/tests/ScheduledTaskRunLogView.test.ts`

---

## 人工验收剧本

### 1. 查看任务列表（US1）

1. 管理员登录 → 侧栏「自动任务」。
2. 列表含「学员消息收件箱过期清理」：表达式 `0 30 3 * * *`、启用、有 `next_run_at`。
3. 无 `scheduled_task.manage` 账号访问 `/scheduled-tasks` → 403 或无权限页。

**通过**: 字段与种子数据一致；无权限零泄露（SC-007）。

### 2. 表单配置与表达式预览（US2）

1. 编辑清理任务 → 将表达式改为 `0 0 4 * * *`。
2. 点击「预览下次执行」→ 显示合法 `next_run_at`。
3. 输入 `invalid cron` → 预览/保存均报错。
4. 输入 `*/1 * * * * *`（每秒）→ `MIN_INTERVAL_VIOLATION`。
5. 保存成功 → 列表表达式与 `next_run_at` 更新。
6. 停用任务 → 保存后 30 秒内 runner 不再产生新的 `schedule` 类型日志。

**通过**: SC-001、SC-002。

### 3. 自动执行（US3）

1. （测试环境）临时改为 `0 */1 * * * *` 并启用。
2. 等待 2–3 个分钟周期。
3. 执行日志出现 `trigger_type=schedule`、`status=success` 记录，次数与周期一致（±10s）。

**通过**: SC-003。

### 4. 执行日志查询（US4）

1. 打开「执行日志」Tab。
2. 筛选任务 = 清理任务、结果 = 失败（若无则先制造一次失败或跳过）。
3. 设置时间范围 → 结果在范围内。
4. 打开失败/跳过详情 → 可见 `error_message` 与 `context`。

**通过**: SC-004；列表首屏 ≤2s（体感）。

### 5. 手动触发（US5）

1. 任务列表点击「立即执行」。
2. 30 秒内日志出现 `trigger_type=manual` 记录。
3. 表达式与启用状态未变。
4. 执行中再次点击 → `409 TASK_ALREADY_RUNNING` 或等价提示。

**通过**: SC-006。

---

## 回归：通知清理仍有效

1. 手动触发清理任务一次。
2. 确认 `learner_notifications` 中 61 天前测试数据被删、59 天前保留（复用 003 测试数据准备方式）。
3. `audit_log` 仍可有 `notification.cleanup`（业务审计）；`scheduled_task_runs` 有对应 run 行。

---

## 故障排查

| 现象 | 检查 |
|------|------|
| 任务从不自动执行 | `docker compose logs api` 中 runner 进程；`enabled=1`；表达式六段式 |
| 保存后调度未变 | 等待 ≤30s reload；或 `docker compose restart api` |
| 重复清理 | 确认仅一个 runner 进程且无遗留 `notification_cleanup` |
| 预览与实跑不一致 | 服务器时区；表达式是否已保存 |

---

## 相关文档

- [data-model.md](./data-model.md) — 表结构与种子
- [research.md](./research.md) — runner 架构与重叠策略
- [contracts/admin-scheduled-tasks.md](./contracts/admin-scheduled-tasks.md) — API 契约
