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
