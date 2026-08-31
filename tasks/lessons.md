# Lessons Learned

> 从最近 5 次提交（`f47cccd` → `0e72fbc` → `211e78a` → `a611094` → `e44a4ca`）提炼的具体工程教训。

---

## Lesson 4: 多 tab 视图合并——path 派生 + Proxy Ref + 自带注销

**What happened in the code:**
`f4026df`（Commit 9, "StudentCenterView 6 me/* 合并"）把 6 个独立的视图（`MyLearningView` / `FavoritesView` / `MyOrdersView` / `MessagesView` / `CheckinListView` / `AccountView`，合计 783 行）合并成一个 1053 行的单文件 `StudentCenterView.vue`，顶部全局 STREAK + heatmap，`<el-tabs>` 切 6 个 inline `<section v-if="activeTab === 'X'">`。关键三段：

```ts
// ① H1 activeTab 由 route.path 单一守卫点派生
const TAB_BY_PATH: Record<string, TabKey> = {
  '/me/learning': 'learning',
  '/me/favorites': 'favorites', /* … */
};
const activeTab = computed<TabKey>(
  () => TAB_BY_PATH[route.path] ?? 'learning',
);

// ② el-tabs v-model 需要 writable ref，computed 不能直接 v-model
const activeTabProxy = ref<TabKey>(activeTab.value);
watch(activeTab, (v) => { activeTabProxy.value = v; });
function onTabChange(name: TabPaneName): void {
  if (typeof name === 'string') gotoTab(name as TabKey);
}

// ③ inject 拿到的 composable 自带注销
let checkinUnsub: (() => void) | undefined;
onMounted(() => {
  if (checkinPrompt) {
    checkinUnsub = checkinPrompt.afterSuccess(() => {
      void loadCheckins();
      void loadStreak();
    });
  }
});
onBeforeUnmount(() => { checkinUnsub?.(); });
```

6 个老 view 的 `data-action="rejoin"` / `data-action="remove-favorite"` / `data-read-id` 全部保留——这意味着 `ElementPlusControlsAudit` 跑过的所有 data-testid 仍然生效，老 router-link 调用点（`PageHeader` / `LearnerLayout` / `CheckoutView` / `AccessGate` 共 9 处）零修改。测试合并成单一 `StudentCenterView.test.ts`（385 行，11 个 `describe`），通过 `vi.hoisted` mock 4 个 API + 2 个 store + vue-router + push + login + MarkdownRenderer，并用一个 reactive `route` 触发 `computed` 重算，路径改变 → section 切换。

**The principle at work:**
**Single Source of Truth (H1)** + **Push instead of Pull (路由驱动 UI 状态)** + **Composable 注销契约 (H5)** + **Ponytail "Rule of Three 反应用"**。

- `activeTab` 不存为本地 ref，而是从 `route.path` 派生——这是 Lesson 1 的延伸：URL 是最外层的 trust boundary，单一 guard `TAB_BY_PATH[route.path] ?? 'learning'` 让所有"切到哪个 tab"的语义都从同一行代码出发，避免组件内部 ref 和 URL 不同步。
- `<el-tabs v-model>` 需要 writable ref，但 `activeTab` 是 computed——Proxy ref + watch 是这种"读派生的、写派生的"模式的通用解。ElMessageBox 的取消语义（Lesson 2）也用了同一招式：状态来自派生，副作用来自 callback。
- `checkinUnsub` 在 `onBeforeUnmount` 调用，是 Lesson "短生命周期状态自带注销" 的强制版本——`afterSuccess` 必须成对出现"订阅 → 退订"，否则连续切 tab 会泄漏监听器。
- Ponytail "Rule of Three 反应用"：6 个 view 都是"fetch + 状态 + empty + error + 列表"五段式，但是只出现一次使用方（这一个综合页），所以**不抽** `<TabSection>`、`<StreakBanner>`、`<EmptyState>`——YAGNI 优先于 DRY。

**Why it matters:**
不把 `activeTab` 派生自 `route.path`，就会出现"用户复制 URL 给朋友打开是 tab X，但页面显示 tab Y"的脱节；tab click 和 URL 改变需要两次 source-of-truth 同步，任何一处遗漏都会产生状态污染。`<el-tabs v-model>` 强写 ref 而 route 是 computed，直接 v-model 编译报错，proxy-ref 双层转发是这条技术债的标准偿付。`checkinUnsub` 不撤销：每次进入 `/me/checkins` 都会再挂一个监听器，切 6 次后 `loadCheckins` 被调用 6 次，loading 闪烁。**测试也得跟着合并**——5 个老 test 各自 stub 一遍 6 个 API 切 tab 是冗余的，单个 `vi.hoisted` mock + reactive route 一套搞定，5→11 测试覆盖更广、代码更少。

**Takeaway for next time:**
- **多 tab / 多步骤视图合并**：第一行永远是 `const activeTab = computed(() => MAP[route.path] ?? DEFAULT)`，写方法用 `router.replace(path)`，**而不是** 把 tab 状态存为本地 ref。
- **`v-model` 与 `computed` 冲突**：永远先看能否 `v-model="ref"` + `watch(computed, sync)`，不要把 `computed` 改成 `ref` 然后用 `effect` 双写。
- **inject 拿到的 composable 必须有退订路径**：`afterSuccess` / `subscribe` / `addEventListener` 一律存 unsub 在 `onMounted` 调、`onBeforeUnmount` 撤销。
- **重复 ≥3 次之前不抽组件**：6 个 inline `<section>` 比 `<TabSection :data="...">` 更易读，前提是它们只在同一个文件里被消费。

---

## Also worth noting: 测试 fixture 共享 + `route.path` 改写触发 computed

**In the code:**
`StudentCenterView.test.ts` 把 5 个老 view 的 fixture 提到顶层（`learningFixture`、`favoritesFixture`、`ordersFixture`、`messagesFixture`、`emptyCheckinsFixture`、`profileFixture`），每次 `beforeEach` 给 mock 设同一组数据。`setPath('/me/messages')` 改 `routerApi.route.path` 后 `await flushPromises()`，`activeTab` computed 重算 → `<section v-if="messages">` 出现，比点 `<el-tab-pane>` 标签更确定（happy-dom 里 Element Plus 内部 click 事件不一定冒泡到外部 listener）。

**The principle:** 测试 fixture 不在 `describe` 块内散布，提到模块顶层后被多次复用——这是 Ponytail "Code is read more than written" 的测试版本。

**Takeaway:** 测多 tab 视图时，与其模拟 UI 点击触发 tab 切换，不如直接改 mock 的 `route.path` 让 computed 重算。简单、确定、不依赖 Element Plus 内部事件冒泡。

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

---

## Lesson: 乐观锁必须严格 > 上次 updated_at，DATETIME 秒级精度会"同秒双写"踩坑

**What happened in the code:**
`d0419ce` 在 `BannerService` 加 `expected_updated_at` 乐观锁：`UPDATE ... WHERE id = ? AND deleted_at IS NULL AND updated_at = ?`。配合 `nextUpdatedAt()` 写入"下一时刻"——但第一版实现是 `max($now, $currentTime)`，当 wall clock 还停在同一秒，`format('Y-m-d H:i:s')` 返回的字符串与原值完全相同，DB 行 `updated_at` 也就没动；下一次 PATCH 用同一个 `expected_updated_at` 时 WHERE 子句永远命中，409 CONFLICT 永远不抛。`testStaleUpdateReturnsConflictWithoutChangingTheBanner` 在真实 PHPUnit 跑里直接翻红 2 个用例才暴露出来。

**The principle at work:**
**乐观锁的本质是"严格单调递增"**——版本字段必须 *strictly greater than* 上次值，不能 *equal* 上次值。而 DATETIME 列秒级精度天然只到秒，sub-second 写入会被截断，所以"now() vs currentTime"的简单比较不够，必须 `max($now, $currentTime + 1s)` 强制推进一秒。同类陷阱还有 TIMESTAMP 在 MySQL 5.6 之前的秒级精度、`updated_at` 被 `SET` 回旧值的 ETL 误操作。

**Why it matters:**
这种 bug 在本地开发（每条请求间隔数秒）几乎永远不触发，只在测试 fixture 连续写、或 CI 在同秒并发两条 PATCH 时才暴露。一旦上线，遇到的是"两个管理员同时改同一条轮播图，第二个人的修改静默成功覆盖第一人"——比乐观锁失败抛 409 危险得多。

**Takeaway for next time:**
任何依赖"严格大于"语义的版本字段（`updated_at` / `version` / `etag`）：
1. 写入方法必须保证新值 **strictly greater than** 旧值（用 `max(now, old + δ)`，不要用 `now()`）；
2. 测试必须包含「同秒双写」fixture：两条 PATCH 用同一个 `expected_updated_at`，第二条必须返回 409；
3. 数据库列精度 ≤ 写入最小间隔时，把"最小间隔"写进 service 常量（`+1 second` / `+1 ms`），不要靠 wall clock 的偶然差。

---

## Lesson: 加 quality gate 前必须先把"被门控的资源"修干净

**What happened in the code:**
Commit 15 计划在 `compose.test.yaml` 加两条新 gate：(a) `composer validate --strict && composer lint` 到 api-test command；(b) `prettier --check src tests` 到 frontend-test command（从只 check `src` 扩到 `src tests`）。改动一行 YAML，看起来无伤大雅。落地前才验证：(a) 测试镜像 `FROM php-ext` 不 COPY composer binary，跑起来 `sh: composer: not found`；(b) 当前 `apps/web/tests` 有 27 个文件、`apps/admin/tests` 有 26 个文件不通过 prettier check。把这两条加上去不会"加强 CI"，只会"立刻让 CI 100% 红"。

**The principle at work:**
**门控的本质是「拒绝不达标状态」**。在大部分资源还不达标时打开门，要么门永远关着（CI 永远挂）、要么被无声 bypass（`|| true` / `--no-verify`），两者都让门控失去意义。打开门前必须满足：(1) 门控检查的工具在执行环境里存在；(2) 当前所有被门控的资源都已经达标，或者伴随的 commit 把它们都修干净；(3) 门控失败时的报错信息能定位到具体失败的文件/规则。

**Why it matters:**
"加一行配置就完事" 的 quality gate 是高风险 diff——它不会让既有功能坏掉（不碰业务逻辑），但会让 CI 立刻挂，且挂得很"对"（真的是不达标）。维护者看到红 build 第一反应是 revert 配置，于是这次 gate 就被悄悄回滚了。Prettier 在仓库首次大规模落地、lint baseline 重建、Docker 镜像缺工具，都是同一类"前置条件未达成"。

**Takeaway for next time:**
plan quality-gate commit 时三问：(1) 命令依赖的 binary 在目标 image 里装了吗？(2) 当前被门控的全部文件都已经合规吗？没有就先有一个独立的 "make resources compliant" commit。(3) 三个不要混在一起：先修 Dockerfile / 先跑 format / 再 flip gate——三个独立 commit，每个独立可回滚，符合 H4。
