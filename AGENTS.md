# Learn Site

## Agent skills

### Issue tracker

Issues live as markdown under `.scratch/<feature>/`. See `docs/agents/issue-tracker.md`.

### Triage labels

Default five roles, strings equal names (`needs-triage`, `needs-info`, `ready-for-agent`, `ready-for-human`, `wontfix`). See `docs/agents/triage-labels.md`.

### Domain docs

Single-context: root `CONTEXT.md` plus `docs/adr/`. See `docs/agents/domain.md`.

### Lessons learned

每次完成一组提交后,用 `/lesson-learned` 技能回看 diff,把可复用的工程教训追加到 `tasks/lessons.md`。在 PR / commit 描述或交接文档中引用 lessons 时使用相对路径 `tasks/lessons.md`。新增约定类规则时,把「Lesson X」的 takeaway 一行提取进 `CLAUDE.md` 的硬规则段,确保下次 commit 前能被自动加载。

### Webman 后端开发

`apps/api` 使用 Webman 框架与 `webman/think-orm`,不是 ThinkPHP 框架。按 `composer.json` / `composer.lock` 中的 `workerman/webman-framework` 核实框架;目录名不能单独作为依据。相关开发、诊断和验证使用 `/webman-development`,加载当前平台的技能副本。

### 执行与完成

- 普通测试、格式或工具失败时,先自主诊断、修复并重试;只暂停依赖失败结果的步骤。缺少必要权限、关键输入或无法自行解决的外部条件时,说明具体阻塞并请求协助。
- 自检、确认事实及等待工具结果不等于请求用户批准。明确审批仍须遵守;同一对象、操作、环境和范围未变且未撤回的已有批准可复用。
- 复用用户已选的工作流与已批准计划。Spec Kit 任务以 `specs/<feature>/tasks.md` 为唯一进度来源,`tasks/todo.md` 只引用它;子技能结束后继续原任务,以原目标及验收项判断完成。
- PHP、Composer 和后端验证通过项目 Makefile / Docker Compose 执行,不要求宿主机安装 PHP。执行前核实连接的是本地开发或测试环境;此约定不授权生产或未知数据环境操作。
- 源码更新后重建对应镜像:运行服务使用 `make rebuild-api` / `make rebuild-admin` / `make rebuild-web`;后端测试先执行 `docker compose -f compose.yaml -f compose.test.yaml --profile test build api-test`,再运行 `make test-api`。已对相同源码构建的镜像无需重复构建。
