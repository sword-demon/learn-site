# CLAUDE.md

> 项目级 Claude Code 指令。继承 `~/.claude/CLAUDE.md` 的通用工作流,叠加本项目特有的硬规则。

## 工作流编排

### 1. 规划节点默认
- 对于任何非琐碎任务(3+ 步骤或架构决策),进入规划模式
- 如果事情偏离轨道,立即停止并重新规划 - 不要继续推进
- 使用规划模式进行验证步骤,而不仅仅是构建
- 提前编写详细规范以减少歧义

### 2. 子代理策略
- 自由使用子代理以保持主上下文窗口干净
- 将研究、探索和并行分析卸载到子代理
- 对于复杂问题,通过子代理投入更多计算资源
- 每个子代理一个策略,以实现专注执行

### 3. 自我改进循环
- 完成一组提交后,运行 `/lesson-learned` 技能回看 diff
- 把可复用的工程教训追加到 `tasks/lessons.md`
- 新增的硬规则从 takeaway 一行提取进本文"项目硬规则"段
- 无情地迭代这些教训,直到错误率下降

### 4. 完成前验证
- 永远不要在证明其有效前标记任务完成
- 在相关时,比较主分支和你的更改之间的行为差异
- 问自己:"一位资深工程师会批准这个吗?"
- 运行测试,检查日志,演示正确性

### 5. 追求优雅(平衡)
- 对于非琐碎更改:暂停并问"是否有更优雅的方式?"
- 如果修复感觉 hacky:"知道我现在知道的一切,实现优雅解决方案"
- 对于简单、明显的修复跳过此步 - 不要过度工程
- 在呈现前挑战自己的工作

### 6. 自主 bug 修复
- 当给出 bug 报告时:直接修复它。不要要求手把手指导
- 指向日志、错误、失败测试 - 然后解决它们
- 用户无需进行零上下文切换
- 去修复失败的 CI 测试,而无需被告知如何做

## 任务管理

1. **首先规划**:将计划写入 `tasks/todo.md`,包含可检查项
2. **验证计划**:在开始实施前检查
3. **跟踪进度**:边做边标记项目完成
4. **解释更改**:每个步骤的高级摘要
5. **文档结果**:向 `tasks/todo.md` 添加审查部分
6. **捕获教训**:每组 commit 后追加到 `tasks/lessons.md`

## 核心原则

- **简单优先**:使每个更改尽可能简单。影响最小代码。
- **无懒惰**:找到根因。没有临时修复。资深开发者标准。
- **最小影响**:更改应仅触及必要内容。避免引入 bug。

## 规则

- 涉及任何库的 API、版本特性时,必须先用 web search 查官方文档,不能凭记忆回答
- 不确定某个模型/框架的最新特性时,主动搜索后再作答
- 所有回复使用中文,英文标点替换中文标点,中文/英文/数字/字母/标点之间合理分开

## 项目硬规则(从 lessons.md 提炼)

### H1. 信任边界处显式守卫(Fail Fast)
- `route.params`、`URLSearchParams`、`localStorage`、`sessionStorage` 取值,统一形状:undefined/null/数组 → null,转换后 `Number.isFinite` 且业务上下界合法
- 把 guard 抽到 `computed/ref`,不要每个调用点重复 `if (!isNaN(id))`
- 服务端闸门(如 `assertActiveLearner`)放在 service 公共写方法的第一行,不允许调用者跳过

### H2. 用设计系统替代浏览器原生弹窗
- 新代码出现 `prompt(` / `confirm(` / `alert(` 时,改用 `ElMessageBox.prompt/confirm` 或同等组件库
- 取消语义统一用 `try/catch` 而非 `=== null`
- 校验下沉到 `inputValidator`,UI 自带红字提示
- 破坏性操作必须加 `type: 'warning'`
- 自造的 `.badge` / `.notice` / `<nav class="pager">` 等组件优先用 `el-tag` / `el-alert` / `el-pagination` 替代

### H3. Service 层用私有方法 + audit 钉死业务规则
- 常量(时区、长度上限、错误码)跨方法复用 → 立刻变 `const`
- 会在多个公开方法出现的格式化/清洗/脱敏 → 立刻抽 `private`
- 副作用(审计、通知、发推送)做成调用约定,不允许调用者跳过
- 时区统一 `Asia/Shanghai`,日期输出走单一 `toIso8601` 方法

### H4. 测试与功能同 commit
- 新增视图/service/composable 必须同 commit 提交对应 vitest/phpunit 测试
- commit 默认粒度 = 一个可独立回滚的改动,而不是一个工作日

### H5. 短生命周期状态走 composable
- 弹窗/抽屉这类依赖 session 状态的 UI,放进 composable,挂载自动建立、卸载自动撤销
- `v-if="session.loggedIn"` 与 composable 内部 `watch(loggedIn)` 必须同步

### H6. 多 tab/多步骤视图:URL 派生 + Proxy Ref + inject 必退订
- 多 tab 视图的 activeTab 永远 `computed(() => MAP[route.path] ?? DEFAULT)`,不存本地 ref;切 tab 用 `router.replace(path)`,URL 与 UI 单一真理
- `<el-tabs v-model>` 需要 writable ref,computed 不能直接 v-model,用 `ref + watch(computed, sync)` 双层转发(不把 computed 改成 ref 然后 effect 双写)
- `inject` 拿到的 composable 一律有退订路径:`afterSuccess`/`subscribe`/`addEventListener` 存 unsub 在 `onMounted` 调、`onBeforeUnmount` 撤销,否则连续切 tab 会泄漏监听器
- 多 tab 测试:直接改 `route.path` 触发 computed 重算,不要模拟 `<el-tab-pane>` click(happy-dom 事件冒泡不稳定)
- 重复 ≥3 次且只有单消费者之前不抽组件:6 个 inline `<section>` 比 `<TabSection :data="...">` 更易读

## 项目特定信息

- **技术栈**: `apps/admin` Vue+Element Plus 管理端,`apps/web` Vue+Element Plus 学习端,`apps/api` PHP/ThinkPHP,`packages/contracts` 共享类型
- **测试**: vitest(`apps/{admin,web}/tests/`), phpunit(`apps/api/tests/`)
- **时区**: 全栈统一 `Asia/Shanghai`,不要写 `date('Y-m-d')` 直取,走 service 内的 `todayDate()/nowDatetime()`
- **域词汇**: 见 `CONTEXT.md`(`学员`/`后台用户`/`超级管理员`/`普通员工`/`管理员` 等)
- **迁移**: 含数据库迁移的更新,除重建 `api` 外还需执行 `make migrate`(`README.md` 有完整指引)
- **审计**: 所有管理端写操作必须通过 service 私有方法 `writeAudit()` 写 `audit_log`,不要在 controller 里散写