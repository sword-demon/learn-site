# Lessons Learned

> 从最近 5 次提交（`f47cccd` → `0e72fbc` → `211e78a` → `a611094` → `e44a4ca`）提炼的具体工程教训。

---

## Lesson 1: Fail Fast — 在数据进入应用前就拒绝坏输入

**What happened in the code:**
在 `e44a4ca` 中，`apps/admin/src/views/students/CourseStudentView.vue` 把路由参数 `courseId` 从一个宽松的 `Number(route.params.id)` 改成了一个显式守卫的 computed：

```ts
const courseId = computed(() => {
  const raw = route.params.id;
  if (raw === undefined || raw === null || Array.isArray(raw)) return null;
  const id = Number(raw);
  return Number.isFinite(id) && id > 0 ? id : null;
});
```

随后在 `reload()`、`revoke()`、`resetProgress()` 三个调用点都加了 `if (courseId.value === null) return;`；模板标题也从 `课程 {{ courseId }}` 改为 `课程 {{ courseId ?? '—' }}`。`a611094` 中的 `CheckinService::assertActiveLearner()` 是同一原则的服务端版本——任何进入写路径的 learner 都先经过账户状态校验，未通过直接抛 `BusinessException`。

**The principle at work:**
**Fail Fast** + **Defensive Programming at the trust boundary**。坏数据（缺失/非数字/负数/数组型参数）不应当在调用栈深处才表现为 `NaN`、SQL 报错或 UI 显示 `课程 NaN`，而应在最早可能的地方显式返回 `null` 并短路所有下游动作。`assertActiveLearner` 的位置选择尤为关键——它放在 `create()` 的第一行而不是分散到每个写操作，意味着新增任何写入方法都自动复用同一道闸门。

**Why it matters:**
如果不做这道守卫，`Number(route.params.id)` 在缺失参数时会返回 `NaN`，会被作为数字传到 API 触发后端 400，而用户看到的是 `课程 NaN · 学员名单` 这种只有事故现场才有的文案。把 `null` 明确表达为「不该继续」的状态，让 watcher 的判断条件从 `watch(courseId, …)` 收紧成「仅当路由仍然是 `course-students` 且 id 合法才刷新」（避免离路由时仍触发 reload）。服务端那道闸门则把 "学员被禁用" 这类业务约束一次性集中，避免每个 controller 重复实现。

**Takeaway for next time:**
每当从 `route.params`、`URLSearchParams`、`localStorage`、`sessionStorage` 拿值时，都套上同一形状的 guard：undefined/null/数组 → null，转换后 `Number.isFinite` 且业务上下界合法。把 guard 抽到 computed/ref，**而不是**每个调用点都重复 `if (!isNaN(id))`——这是 single-source-of-truth，下游所有分支读同一个 `null`。

---

## Lesson 2: 用设计系统替代浏览器原生弹窗——但保留可访问性语义

**What happened in the code:**
`0e72fbc` 把 `CourseStudentView.vue` 里的 `prompt()` 与 `confirm()` 全部替换为 `ElMessageBox.prompt()` / `ElMessageBox.confirm()`：

```ts
// before
const input = prompt(`撤销 ${row.login} 的免费授权，请填写原因：`);
if (input === null) return;
const reason = input.trim();
if (!reason) { errorMsg.value = '请填写撤销原因。'; return; }

// after
try {
  const out = await ElMessageBox.prompt(..., {
    inputValidator: (value) => (value.trim() ? true : '请填写撤销原因'),
    confirmButtonText: '撤销',
    cancelButtonText: '取消',
    type: 'warning',
  });
  reason = out.value.trim();
} catch { return; }
```

注意三件事：(1) 校验从「本地判空 + 手动 errorMsg」下沉到 `inputValidator`，UI 自己出红字；(2) 取消通过 `try/catch` 表达而不是 `=== null`；(3) `type: 'warning'` 让破坏性操作得到视觉警告。`f47cccd` 在 `ReviewModerateView.vue` 上做了同样方向的工作——把自造的 `.badge`、`.notice`、`<nav class="pager">` 替换为 `el-tag`、`el-alert`、`el-empty`、`el-pagination`、`el-skeleton`、`el-card #header`，并补回 `:title`、`aria-pressed`、`focus-visible` 等可访问性属性。

**The principle at work:**
**Principle of Least Surprise** + **Replace Custom with Library When the Library Already Exists**。浏览器 `prompt/confirm` 在每个浏览器里长相不一、被很多 ad blocker 拦截、不可样式化、不可国际化、且在 Electron 容器里偶尔阻塞事件循环。`ElMessageBox` 是项目已装的依赖（管理端/学习端都用 `Element Plus`），复用它的成本是零，而用户感知到的"破坏性操作的视觉警告"立刻到位。

**Why it matters:**
`prompt/confirm` 的 `null` 哨兵和设计系统的"取消抛错"是两种不同的取消语义，迁移时如果保留 `if (input === null)` 判断就会误吞掉"输入校验失败"的异常。把"取消 = catch 空异常"做成约定后，未来所有弹窗（不只是这两个）都按这一种方式接入新逻辑，例如加载态、键盘 Esc、点遮罩关闭，全部由 `catch` 一并覆盖。

**Takeaway for next time:**
看到 `prompt(` / `confirm(` / `alert(` 在新代码里出现，先确认项目是否已装 Element Plus / Naive UI / shadcn 等组件库；装了就用 `MessageBox`，没装就把它装上而不是新写一个 `<dialog>`。删除代码比新增组件更 lazy（对应 ponytail 第 5/6 阶）。

---

## Lesson 3: Service 层用「私有方法 + audit_log」把业务规则一次性钉死

**What happened in the code:**
`a611094` 新增的 `apps/api/app/service/CheckinService.php`（382 行）展示了清晰的职责拆分：

- 公开方法只做编排：`create()` 调用 `assertActiveLearner → sanitizePlan → Db::transaction → writeAudit`。
- 私有方法按单一职责拆：`sanitizePlan`、`hasVisibleText`、`plainText`、`maskPhone`、`summarizePlan`、`shapeLearnerRecord`、`shapeAdminListItem`、`shapeAdminDetail`、`normalizePagination`、`todayDate`、`nowDatetime`、`toIso8601`、`writeAudit`、`isDuplicateKey`。
- 时区集中在 `private const TIMEZONE = 'Asia/Shanghai'`，所有日期/时间输出都走 `toIso8601()`。
- 所有变更（创建、重复拒绝、删除）都通过 `writeAudit()` 写 `audit_log`，把审计变成"忘不掉的副作用"而不是可选装饰。

并发安全体现在两处：(1) `create()` 先尝试插入，用 `isDuplicateKey` 捕 `PDOException` 后转为业务异常并补写一条 `checkin.duplicate_rejected` 审计；(2) `deleteForAdmin()` 用 `Db::transaction` + `->lock(true)` 取行后再删。

**The principle at work:**
**Single Responsibility Principle**（每个私有方法做一件事）+ **Encapsulation / Information Hiding**（`MAX_PLAN_LENGTH`、`TIMEZONE` 都封死在 service 内部）+ **Rule of Three 的逆运用**：当一个规则（"审计、时区、脱敏、长度限制"）会在三个公开方法里出现，**现在**就抽出私有方法，而不是等第三次重复。

**Why it matters:**
签名脱敏（`maskPhone`）、HTML 清洗（`sanitizePlan`）、摘要生成（`summarizePlan`）这些都极容易散到 controller / view / DTO 里——一旦散开，"学员手机号在哪里被脱敏" 这个问题就再也答不上来。集中到 service 之后：管理端列表、详情、API 响应、未来的导出 CSV 全部自动合规。同理 `todayDate()/nowDatetime()` 双胞胎方法把"哪个时区算今天"这件事钉死在一个常量，未来如果要换时区只需要改一行而不是 grep 所有 `date('Y-m-d')`。

**Takeaway for next time:**
新建 service 时问自己三个问题：(1) 这段业务里有几个常量会跨方法复用（时区、长度上限、错误码）？立刻变 `const`；(2) 有几段格式化/清洗逻辑会在多个公开方法里出现？现在就抽 `private`；(3) 哪些副作用（审计、通知、发推送）是「忘不掉」的？做成构造函数或调用约定，不允许调用者跳过。

---

## Also worth noting: Composable 抽取 + 「短生命周期状态」自带注销

**In the code:**
`a611094` 新增 `apps/web/src/composables/useDailyCheckinPrompt.ts`（84 行），把"登录态变化 → 检查今日签到 → 显示/隐藏弹窗 → sessionStorage 记录今日已忽略"这一段状态机从 `LearnerLayout.vue` 抽出来。它内部 `watch(loggedIn, …, { flush: 'sync' })` 在登出时同步清理 `sessionStorage` 的 dismiss 标记、关闭弹窗、重置 ref——这就是为什么这个 composable 没有"忘记登出后还在弹窗"这类 bug。

**The principle:** 当一个交互的"显示与否"完全由 session 状态派生，把它放进一个 composable 让其随组件挂载自动建立、随组件卸载自动撤销；不要把状态散到路由组件的 `data()` 里。

**Takeaway:** `v-if="session.loggedIn"` 与 composable 内部 `watch(loggedIn)` 是同一份真理的两端——保持它们同步就避免了「该弹的时候不弹 / 不该弹的时候还在弹」的脏状态。

---

## Also worth noting: 测试跟着新视图/新 service 一起落地

**In the code:**
`a611094` 在新增 `CheckinService` 的同时给了 366 行的 `DailyCheckinTest.php`；新增 `CheckinListView.vue` 的同时给了 96 行的 `CheckinListView.test.ts`；新增 `useDailyCheckinPrompt` 的同时给了 109 行的 `DailyCheckinPrompt.test.ts`。`e44a4ca` 把 lesson-learned 技能本身加入 `skills-lock.json`，让流程自举。

**The principle:** 测试不是"完成功能后补的作业"，而是"功能能否被声明完成"的判定条件。把测试和功能放进同一个 commit 才能让 `git revert` 一次回到干净状态。

**Takeaway:** PR / commit 拆分的默认粒度是「一个可独立回滚的改动」，而不是「一个工作日」。当一个 commit 同时包含「功能代码」和「对应测试」，它就具备了独立回滚的能力；否则就要靠"配对的另一个 commit"来撤销。