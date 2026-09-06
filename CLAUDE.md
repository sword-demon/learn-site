# CLAUDE.md

个人运营单站点课程学习产品（学习端 + 管理端），前后端分离 monorepo。

## 技术栈

- API: PHP 8.4 + Webman 2.2 + ThinkORM + Phinx
- 前端: Vue 3 + Vite + TypeScript + Element Plus + Pinia（管理端）/ Tailwind（学习端）
- 共享: `@learn-site/contracts`（Zod Schema）
- 数据: MySQL 8.4 + Redis 7.4
- 运行: Docker Compose（推荐 OrbStack）

## 项目结构

- `apps/api/` — Webman REST API（学员端 + 管理端）
- `apps/web/` — 学习端 SPA
- `apps/admin/` — 管理端 SPA
- `packages/contracts/` — 前后端共享 Zod 契约
- `docker/` — 各服务 Dockerfile 与 Nginx 配置
- `specs/<feature>/` — 功能规格、API 契约、验收 quickstart
- `docs/adr/` — 架构决策记录
- `docs/agents/` — Agent 工作流（issue-tracker、triage-labels、domain）
- `ops/backup/` — 备份/迁移/恢复脚本
- `tasks/lessons.md` — 工程教训沉淀
- `CONTEXT.md` — 领域词汇表（学员 / 后台用户 / 访问权 等）

## 域词汇

学员 ≠ 后台用户；课程访问权 ≠ 授权；激活码 ≠ 优惠券；评价 ≠ 课程意见反馈。
术语统一以 `CONTEXT.md` 为准，规格/spec/对话统一沿用。

<important if="你需要构建、运行、测试或重建本项目任何服务">

所有命令走 Makefile（封装 Docker Compose）。宿主机直接 `php start.php` 或 `pnpm dev` 不构成验收。

| 命令 | 用途 |
|---|---|
| `make bootstrap` | 复制 `.env` + 构建启动 + 迁移 + 种子 + 健康检查 |
| `make up` / `make down` | 启停栈（`down` 保留卷；日常停栈不要 `down -v`） |
| `make rebuild-api` / `rebuild-web` / `rebuild-admin` / `rebuild-all` | 改源码后必做；`make restart` 仅重启不重构建，无效 |
| `make migrate` / `make seed` | 含迁移的更新在 `rebuild-api` 后执行；种子写入权限码 |
| `make test` / `test-api` / `test-web` / `test-fmt` | 测试套件 |
| `make test-e2e` / `test-perf` / `e2e-down` | 独立 Compose project 跑 Playwright / 性能冒烟，跑完 `e2e-down` 收卷 |
| `make lint` / `make typecheck` / `make phpstan` | 前端 Lint / vue-tsc / 后端 PHPStan |
| `make sh-api` / `make logs SERVICE=api` | 进入 api 容器 / 跟日志 |
| `make backup BACKUP_DIR=...` / `rehearse-restore` | 备份 MySQL+uploads / 隔离 project 恢复演练 |

源码路径 → 必须重建的服务：

- `apps/web/` → `make rebuild-web`
- `apps/admin/` → `make rebuild-admin`
- `apps/api/` → `make rebuild-api`
- `packages/contracts/` → `make rebuild-all`（API 引用新字段也要 rebuild-api）

`compose.test.yaml` 的 `frontend-test` / `api-test` 是 CI 一次性测试容器，不用于日常预览。
</important>

<important if="你在编写、修改或审查 apps/api 下任何 PHP 代码">

`apps/api` 是 **Webman + think-orm**，不是 ThinkPHP / Laravel / PHP-FPM。
开发、诊断、容器运行约定统一遵循 `AGENTS.md`「Webman 后端开发」与「执行与完成」段；任何 webman 修改前先按 `/webman-development` skill 的「开始前检查」核对 `composer.json` / `composer.lock` 的 `workerman/webman-framework` 版本与既有约定，不要按默认 PHP-FPM 模型推断。
</important>

<important if="你从 route.params / URLSearchParams / localStorage / sessionStorage 读取并交给下游使用">

所有 trust boundary 取值统一形状：

- 缺失/null/数组 → 返回 `null`
- 数字转换后 `Number.isFinite` 且业务上下界合法才返回；否则 `null`
- guard 抽到 `computed`/`ref`，不要每个调用点重复 `if (!isNaN(id))`
- 模板显示用 `{{ id ?? '—' }}`，绝不允许 `课程 NaN`
- 服务端闸门（如 `assertActiveLearner`）放在 service 公共写方法第一行，不允许调用者跳过
</important>

<important if="你要写新的弹窗 / 抽屉 / 对话框 / 自造徽章 / 自造分页组件">

禁止 `prompt(` / `confirm(` / `alert(`，禁止自造 `.badge` / `.notice` / `<nav class="pager">`。
改用 `ElMessageBox.prompt/confirm`、`el-tag`、`el-alert`、`el-pagination`、`el-empty`、`el-skeleton`、`el-card #header` 等已装依赖
（管理端和学习端都用 Element Plus）。

弹窗约定：

- 取消语义统一 `try { ... } catch { return; }`，不要 `=== null`
- 校验下沉到 `inputValidator`，UI 自带红字
- 破坏性操作必须 `type: 'warning'`
- 重复 ≥3 次且只有一个消费者之前不抽组件
</important>

<important if="你在新建或重构 service 层（apps/api/app/service 或类似业务封装）">

- 跨方法复用的常量（时区、长度上限、错误码）立刻变 `private const`
- 会在多个公开方法出现的格式化 / 清洗 / 脱敏立刻抽 `private`（`maskPhone` / `sanitizePlan` / `summarizePlan` / `toIso8601` 等）
- 时区统一 `'Asia/Shanghai'`，日期/时间输出走单一 `todayDate()` / `nowDatetime()` / `toIso8601()`，不写 `date('Y-m-d')` 直取
- 副作用（审计、通知、发推送）做成调用约定（`writeAudit()` 私有方法），不允许调用者跳过
- 写路径第一行先做业务闸门（账户状态 / 乐观锁 / 重复键），用 `Db::transaction` + `->lock(true)`
</important>

<important if="你在新建视图 / composable / service / 公共方法">

- 新增视图/service/composable 必须同 commit 提交对应 vitest/phpunit 测试
- commit 默认粒度 = 一个可独立回滚的改动，而不是一个工作日
- 测试驱动：`tests/` 目录与源码并列（`apps/{admin,web}/tests/`、`apps/api/tests/`），覆盖率目标 ≥ 80%
- 契约字段必须从 `packages/contracts` 引用，禁止前端手写 DTO 类型
</important>

<important if="你要写依赖 session 状态的弹窗 / 抽屉 / 监听器">

- 放进 composable：挂载自动建立、卸载自动撤销
- `v-if="session.loggedIn"` 与 composable 内部 `watch(loggedIn, …, { flush: 'sync' })` 必须同步
- `inject` 拿到的 composable（`afterSuccess` / `subscribe` / `addEventListener`）一律存 unsub 在 `onMounted` 调、`onBeforeUnmount` 撤销，避免切 tab 泄漏监听器
</important>

<important if="你在写多 tab / 多步骤视图（一个路由下多个 section）">

- activeTab 永远 `computed(() => TAB_BY_PATH[route.path] ?? DEFAULT)`，不存本地 ref
- 切 tab 用 `router.replace(path)`，URL 与 UI 单一真理
- `<el-tabs v-model>` 需要 writable ref，computed 不能直接 v-model → 用 `ref + watch(computed, sync)` 双层转发，**不要**把 computed 改成 ref 后用 effect 双写
- 测多 tab 视图时直接改 `route.path` 触发 computed 重算，不要模拟 `<el-tab-pane>` click（happy-dom 事件冒泡不稳定）
- 重复 ≥3 次且只有一个消费者之前不抽组件
</important>

<important if="你在写队列 dispatcher / consumer 或修改 MySQL 软删表唯一约束">

- 请求内创建的 dispatcher / consumer 必须显式传递已有业务服务，只有独立 worker 入口才允许回退到全局容器
- MySQL 软删表的 active 唯一性不能仅依赖包含 nullable `deleted_at` 的复合唯一索引；用生成列或等价的非空唯一键，并用重复写入测试验证
- 乐观锁版本字段必须 **strictly greater than** 旧值（`max(now, old + δ)`，不要 `now()`），测试须覆盖「同秒双写」fixture
</important>

<important if="你在管理端写任何变更（创建 / 更新 / 删除 / 重置）">

所有写操作必须经过 service 私有方法 `writeAudit()` 写 `audit_log`，**不要**在 controller 里散写。审计是「忘不掉的副作用」，不是可选装饰。
</important>

<important if="你刚刚完成一组 commit 或修复了一个被点名的 bug">

跑 `/lesson-learned` 回看 diff，把可复用的工程教训追加到 `tasks/lessons.md`。
新增约定类规则时，把 takeaway 一行提取成本文件下一条 `<important if>` 块，确保下次 commit 前自动加载。
引用 lessons 时使用相对路径 `tasks/lessons.md`。
</important>

<important if="你计划启动一个非琐碎的实现 / 重构 / 跨文件改动">

- 复杂任务先用项目现有任务清单（Spec Kit 用 `specs/<feature>/tasks.md`，其他用 `tasks/todo.md`），没有现成清单的再自建
- 实施前自核范围、依赖、验收项；已有批准复用，不把"自检 / 确认事实 / 等待工具结果"当作请求用户批准
- 普通测试 / 工具失败时自主诊断、修复、重试；只暂停依赖失败结果的步骤
- 子技能结束后继续原任务，以原目标和验收项判断完成
- 完成前必跑测试 / 检查日志 / 比较主分支行为差异；问自己"一位资深工程师会批准这个吗"
- 涉及只读转写入、生产/未知数据环境、破坏性 Git 操作、宪章修订前必须确认授权
</important>