# Research: 004-admin-crontab-tasks

## R1: 调度运行时架构

**Decision**: 用单一自定义进程 `ScheduledTaskRunner`（`config/process.php` 注册）替代现有 `notification_cleanup` 进程；进程 `onWorkerStart` 从 `scheduled_tasks` 表加载启用任务，为每条任务 `new Crontab($expression, $callback)`；进程内维护 `task_id → Crontab` 映射，配置变更时 `destroy()` 旧实例并重建。

**Rationale**:
- 用户要求基于既有 `workerman/crontab`；与 003 通知清理已用组件一致。
- `Crontab::destroy()` / `remove()` 支持动态卸载，无需重启整个 API 即可应用新表达式（在 runner 进程内）。
- 宪章「容器即运行契约」：调度仍在 `api` 容器内，不依赖宿主机 cron。

**Alternatives considered**:
| 方案 | 弃用原因 |
|------|----------|
| 保留每任务独立 process 文件 | 无法表单配置；每增任务需改代码与 `process.php` |
| Redis 队列 + worker 消费 | 宪章禁止提前引入消息队列 |
| Redis pub/sub 通知 runner 重载 | 宪章将 Redis 限定为令牌/验证码；协调信号宜避免扩展 Redis 用途 |
| 宿主机 cron 调 PHP 脚本 | 违反容器即运行契约 |

**配置同步**: runner 每 **30 秒** 比对 `scheduled_tasks` 的 `MAX(updated_at)` 与本地缓存；变化则全量 `reload()`。管理员保存后最多 30 秒内生效；进程重启立即全量加载。

**同进程阻塞**: workerman 文档指出同进程多 Crontab 非异步、长任务会阻塞同进程其它定时器。首版任务量少（1–3 条），清理任务分批 `LIMIT 500`；若未来任务增多，可将敏感任务拆到独立 process（宪章允许）。

---

## R2: 表达式校验与下次执行时间

**Decision**: 服务端统一使用 `Workerman\Crontab\Parser::isValid()` 做语法校验；下次执行时间由 `ScheduleExpressionService::nextRunAt($expression, $from)` 实现——自 `$from` 起按秒递增扫描（上限 366 天），对每个候选时刻调用 `Parser::parse()` 判断是否命中；预览 API 与保存校验共用。

**Rationale**:
- `Parser` 已随 `workerman/crontab` 安装，与运行时调度器语法一致。
- `Parser::parse()` 仅返回当前分钟内的命中秒，无直接 `nextRun` API；扫描实现简单、无新依赖。

**Alternatives considered**:
| 方案 | 弃用原因 |
|------|----------|
| `dragonmantank/cron-expression` | 五段式为主，与六段式秒级语法不一致 |
| 仅前端校验 | 违反契约优先；可被绕过 |
| 仅 `isValid` 不预览 | 不满足 FR-005 / SC-001 |

**最短间隔**: 保存与预览时计算「未来 24 小时内前两次命中」间隔；若小于 **60 秒** 则拒绝（`MIN_INTERVAL_VIOLATION`）。六段式表达式若秒位为 `*` 或步长 < 60，通常会被拦截。

**表达式规范**: 首版强制 **六段式**（`sec min hour day month week`），与现有 `NotificationCleanup` 的 `0 30 3 * * *` 及 workerman 文档一致；五段式在 `isValid` 虽可能通过，保存时统一要求 6 段（减少歧义）。

---

## R3: 任务类型与处理器注册

**Decision**: 代码侧 `ScheduledTaskHandlerRegistry` 注册 `handler_code` → `ScheduledTaskHandler` 实现；首版仅 `notification.cleanup`（调用既有 `NotificationCleanupService::purgeExpired()`）。数据库 `scheduled_tasks.handler_code` 必须命中注册表；种子插入默认清理任务行。

**Rationale**:
- 满足 FR-003（预注册、禁止任意命令）。
- 复用 003 已实现的清理逻辑与 `audit_log` 写入；本模块增加 **执行日志** 层，不替代 `audit_log` 业务审计。

**Alternatives considered**:
| 方案 | 弃用原因 |
|------|----------|
| 管理员填写 shell 命令 | 严重安全风险 |
| 动态 PHP 脚本上传 | 同上 |
| 仅硬编码无 DB | 无法满足表单配置与日志查询 |

**参数**: `notification.cleanup` 首版参数 JSON：`{ "batch_size": 500 }`（可选，范围 1–2000）；保留月数仍由 `NotificationCleanupService` 固定 2 个月（规格：业务规则不在本模块改）。

**下线处理器**: 若 `handler_code` 在注册表不存在，列表标 `handler_status=unavailable`，表单只读，禁止启用。

---

## R4: 执行重叠与并发策略

**Decision**: 每个 `task_id` 在 runner 进程内用 `running` 标志；自动调度触发时若已在执行，**跳过本次**并写入 `scheduled_task_runs` 一条 `status=skipped`、`error_message=「上次执行尚未结束」`（满足规格「记录跳过」假设）。

**Rationale**:
- 避免同任务并发双倍清理或重复副作用。
- workerman 同进程已非并行，标志位足够。

**手动触发**: 与自动调度相同互斥；并发手动触发若已在跑，返回 `409 TASK_ALREADY_RUNNING` 或仍排队——首版 **拒绝并提示**（更简单）。

**手动与自动重叠**: 各自独立判断 `running`；若自动触发时手动正在跑则 skip；反之亦然。两条日志分别记录（规格假设）。

---

## R5: 执行日志与审计

**Decision**: 新表 `scheduled_task_runs` 存每次运行；`trigger_type` 枚举 `schedule` | `manual`。成功/失败/跳过均落库。管理员更新配置、手动触发写 `audit_log`（`scheduled_task.update`、`scheduled_task.run`）；任务执行完成写 run 行 + 可选 `audit_log`（`scheduled_task.completed`）仅失败时写 audit 以减负——首版 **run 表为主**，audit 记录配置变更与手动触发。

**Rationale**:
- FR-008 要求完整执行日志；`audit_log` 不适合高频分页查询。
- 对齐 `NotificationDispatchService` 对 `notification.send` 的 audit 用法。

---

## R6: 管理端 UI 与权限

**Decision**: 权限码 `scheduled_task.manage`；侧栏「自动任务」`/scheduled-tasks`；列表页 + 编辑对话框；子路由或 Tab「执行日志」`/scheduled-tasks/runs`。

**Rationale**: 与 `notification.manage`、`AdminMenu` 模式一致；单一权限码符合规格假设。

**表单**: `el-input` 表达式 + `el-switch` 启用 + 参数表单项（按 handler 元数据）；「校验 / 预览下次执行」按钮调用 validate API；保存调用 PATCH。

---

## R7: 从 NotificationCleanup 迁移

**Decision**:
1. 删除 `config/process.php` 中 `notification_cleanup` 条目。
2. 新增 `scheduled_tasks_runner` 进程。
3. Phinx 种子：插入 `handler_code=notification.cleanup`、默认表达式 `0 30 3 * * *`、`enabled=1`。
4. 删除或空置 `app/process/NotificationCleanup.php`（逻辑迁至 handler）。

**Rationale**: FR-013 要求纳入可管理体系；避免双进程重复调度同一清理。

---

## R8: API 与契约

**Decision**: 管理端 REST 前缀 `/api/admin/v1/scheduled-tasks`；Zod 契约 `packages/contracts/src/adminScheduledTask.ts`。

**端点摘要**:
- `GET /scheduled-tasks` — 列表
- `GET /scheduled-tasks/{id}` — 详情
- `PATCH /scheduled-tasks/{id}` — 更新表达式、enabled、params
- `POST /scheduled-tasks/validate-expression` — 校验 + next_run_at
- `POST /scheduled-tasks/{id}/run` — 手动触发
- `GET /scheduled-tasks/runs` — 日志列表（筛选 task_id、status、时间）
- `GET /scheduled-tasks/runs/{id}` — 日志详情

**Rationale**: 首版不开放 `POST` 创建任务（任务由种子 + 代码注册）；仅更新已注册任务实例。

---

## R9: 测试策略

**Decision**:
- PHPUnit: 表达式校验、next run、PATCH 权限、手动触发、run 日志写入、handler 执行失败路径、skip 重叠。
- Vitest: 列表/表单/日志页、validate 预览交互。
- 集成: 修改表达式为 `0 */1 * * * *`（每分钟第 0 秒）在测试环境观察 2–3 个周期（可 `@group slow` 或 mock runner）。

**Rationale**: 对齐宪章 V；SC-003 需要可重复验证。
