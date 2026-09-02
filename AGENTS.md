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

`/webman-development` 技能针对基于 Workerman 的常驻内存多进程 Webman 框架。**仅当检测到 webman 框架时调用**(`composer.json` / `composer.lock` 出现 `workerman/webman-framework`,或代码命中 `start.php` / `app/` / `config/process.php` / `config/route.php` 等 webman 约定路径),再通过 Skill 工具加载 `.claude/skills/webman-development/SKILL.md`。当前项目 `apps/api` 是 ThinkPHP,不要触发;若未来某子包引入 webman,新工作开始前先跑 `composer show | grep webman` 确认,再决定是否加载。
