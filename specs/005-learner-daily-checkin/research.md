# Research: 005-learner-daily-checkin

## R1: 数据模型 — 单表 + 唯一约束

**Decision**: 新建表 `learner_daily_checkins`，字段含 `learner_id`、`checkin_date`（DATE）、`plan_html`（TEXT）、`checked_in_at`（DATETIME）；`UNIQUE (learner_id, checkin_date)` 保证每学员每自然日一条。

**Rationale**:
- 规格 FR-001/FR-004 要求按自然日唯一签到；数据库唯一约束比应用层判断更可靠，并发下可捕获重复插入并返回「今日已签到」。
- 将 `checkin_date` 存为 DATE（非时间戳）避免时区换算歧义；`checked_in_at` 保留实际签到时刻供列表展示。
- 每日计划与签到记录 1:1，无需拆表。

**Alternatives considered**:

| 方案 | 弃用原因 |
|------|----------|
| 仅存 `checked_in_at` 用日期函数判重 | 跨时区与索引效率差，边界日易出错 |
| Redis 记录当日签到 | 无法支撑历史列表与后台查询，违反持久化要求 |
| 软删除 + 唯一约束冲突 | 删除后需允许重签；硬删除更简单，符合 FR-016 |

---

## R2: 自然日与时区

**Decision**: 服务端以 `Asia/Shanghai` 计算「今日」`checkin_date`；`config/app.php` 或 Webman 默认时区与部署一致。客户端不参与日期判定。

**Rationale**:
- 规格假设明确站点统一时区；与现有 PHP 部署惯例一致。
- `checkin_date` 在写入时由服务端 `date('Y-m-d')`（或 Carbon `today('Asia/Shanghai')`）生成，客户端传日期不可信。

**Alternatives considered**:

| 方案 | 弃用原因 |
|------|----------|
| 客户端传 `checkin_date` | 可被篡改，跨时区学员体验不一致 |
| UTC 自然日 | 与中国大陆学员认知不符 |

---

## R3: 富文本输入与净化

**Decision**:
- **学习端**：新增 `apps/web/src/components/CheckinPlanEditor.vue`，基于 admin `ContentEditor.vue` 精简（保留加粗/斜体/列表/链接；首版不含图片/视频上传）。
- **服务端**：`CheckinService` 调用既有 `HtmlSanitizer::sanitize()`；净化后 HTML 经 `hasRichHtml()` 等价逻辑（去标签后非空）校验；正文上限 **10000 字符**（净化后）。
- **展示**：学习端列表与详情用既有 `MarkdownRenderer.vue`（`v-html`）渲染；管理端详情同理。

**Rationale**:
- 规格 FR-003 要求与站点其他富文本安全策略一致；`HtmlSanitizer` 已在课程简介、站点资料使用。
- 学习端目前无 WangEditor 组件；复制精简版比引入跨 app 共享包改动面更小（宪章「最小抽象」）。
- 每日计划不需要媒体上传，精简工具栏降低实现与攻击面。

**Alternatives considered**:

| 方案 | 弃用原因 |
|------|----------|
| 纯 textarea | 不满足富文本需求 |
| 抽取 `packages/ui` 共享 ContentEditor | 首版过度工程；两 app 独立构建无迫切复用压力 |
| 完整复制 ContentEditor 含上传 | 学员侧无对应上传 API，增加权限与安全评审 |

---

## R4: 当日签到状态 API

**Decision**: 新增 `GET /api/learner/v1/checkins/today`，返回 `{ checked_in: boolean, record?: LearnerCheckinDTO }`。弹窗逻辑与列表页均复用此接口或列表首条缓存。

**Rationale**:
- FR-005 要求进入学习端时判断当日是否已签到；轻量专用接口避免拉全量历史列表。
- 与 `GET /messages/unread-count`（003）模式一致：布局级 composable 挂载时请求一次。

**Alternatives considered**:

| 方案 | 弃用原因 |
|------|----------|
| 扩展 `GET /me` | 耦合账号资料与签到状态 |
| 仅依赖 POST 失败得知已签 | 无法驱动「已签不弹窗」的读路径 |

---

## R5: 签到弹窗与会话内关闭

**Decision**:
- Composable `useDailyCheckinPrompt()` 在 `LearnerLayout` 中于 `session.loggedIn` 为真时初始化。
- 流程：`GET /checkins/today` → 若 `checked_in` 则结束；若 `sessionStorage.getItem('checkin_dismissed_' + todayDate)` 存在则结束；否则 `showDialog = true`。
- 用户关闭弹窗未签到：`sessionStorage.setItem('checkin_dismissed_' + todayDate, '1')`。
- `todayDate` 使用 API 返回的站点日期字符串（或响应头/字段 `server_date`），避免客户端本地日期与服务器不一致。

**Rationale**:
- 满足 FR-006（同会话不重复打扰）与 FR-007（签到后不再弹）。
- `sessionStorage` 在标签页关闭后清除，满足「新会话再次提醒」。
- 弹窗组件 `DailyCheckinDialog.vue`：`el-dialog` + `CheckinPlanEditor` + 提交调用 `POST /checkins`。

**Alternatives considered**:

| 方案 | 弃用原因 |
|------|----------|
| Pinia 仅存内存 | 刷新页面会重复弹，不符合「会话」语义 |
| localStorage | 跨会话不提醒，完成率下降 |
| 每次路由切换都弹 | 体验极差 |

---

## R6: 管理端模块与权限

**Decision**:
- 权限码 `checkin.manage`（列表 + 详情 + 删除）。
- 侧栏「签到管理」→ `/checkins`；`CheckinListView.vue` + 详情抽屉。
- `Authorize::MAP` 增加 `'/api/admin/v1/checkins' => 'checkin.manage'`。
- 筛选：`learner_id`、`date_from`、`date_to`；列表 JOIN `learners`/`accounts` 取昵称与脱敏手机号。

**Rationale**:
- 与 `notification.manage`、`scheduled_task.manage` 单一权限码模式一致。
- 规格 FR-012–FR-015；删除需确认步骤用 `ElMessageBox.confirm`。

---

## R7: 审计日志

**Decision**: 写入既有 `audit_log` 表：
- 学员签到成功：`action=checkin.create`，`actor_id=learner_id`，`target_type=learner_daily_checkin`，`target_id=record.id`
- 管理员删除：`action=checkin.delete`，`actor_id=staff_id`，`payload_json` 含 `learner_id`、`checkin_date`

**Rationale**: 对齐 `NotificationDispatchService`、`CourseStudentService` 既有模式；满足 FR-017。不使用 `moderation_logs`（专用于评价审核）。

---

## R8: 删除语义

**Decision**: 物理删除 `learner_daily_checkins` 行；删除后该 `(learner_id, checkin_date)` 组合可再次插入，学员 `GET /checkins/today` 返回 `checked_in: false`，弹窗逻辑恢复。

**Rationale**: 规格 FR-016 与假设明确；硬删除最简单，无历史版本需求。

---

## R9: 学员账号状态

**Decision**: 仅 `accounts.status = 'active'` 且存在 `learners` 行的学员可 `POST /checkins`；停用学员请求返回 `403 ACCOUNT_DISABLED`。历史记录管理端可查；停用后不可新增。

**Rationale**: 与「在册学员」定义（003 通知模块）一致；规格边界场景。

---

## R10: 列表分页与摘要

**Decision**:
- 学习端 `GET /checkins`：`page`/`limit`（默认 20，最大 100），`ORDER BY checkin_date DESC`。
- 管理端 `GET /checkins`：同上 + 筛选；列表项含 `plan_summary`（剥离 HTML 后前 120 字）减少载荷。
- 详情（管理端 `GET /checkins/{id}`）返回完整 `plan_html`。

**Rationale**: 复用 `NotificationController` 分页模式；满足 SC-004/SC-005。
