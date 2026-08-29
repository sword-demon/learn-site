# Research: 003-admin-notifications

## R1: 实时推送技术选型

**Decision**: 采用官方插件 `webman/push`（Pusher 协议兼容），学员端订阅私有频道 `private-learner-{learnerId}`，服务端通过 `Webman\Push\Api::trigger()` 推送 `notification` 事件。

**Rationale**:
- 用户明确要求使用 [webman/push](https://www.workerman.net/doc/webman/plugin/push.html)；与 Webman 同栈，无需额外消息中间件。
- 私有频道 + 鉴权路由可满足 FR-015（仅本人可订阅）；插件自带心跳与断线重连。
- 宪章禁止把 Redis 用于业务缓存/队列，但 push 插件内部用 Redis 维护连接状态属于基础设施，不构成业务缓存违规。

**Alternatives considered**:
| 方案 | 弃用原因 |
|------|----------|
| 纯 HTTP 轮询 | 无法满足 SC-002（5 秒内感知新消息），服务端压力大 |
| Server-Sent Events 自研 | 需自建连接管理与鉴权，重复造轮子 |
| 引入独立 MQ（Redis 队列） | 宪章明确不得提前引入消息队列 |

**集成要点**:
- WebSocket 默认 `3131`，HTTP 推送 API 默认 `3232`；Compose 中由 `api` 容器暴露，学习端 Nginx 反代 `wss`（生产）或开发环境直连 `ws://api:3131`。
- 鉴权：扩展 `config/plugin/webman/push/route.php`，校验学员 access token 与频道 `private-learner-{id}` 中 `id === account_id`。
- 事件载荷保持精简：`{ id, kind, title, unread_count }`；详情仍走 REST 拉取（FR-014 兜底）。

---

## R2: 定时清理技术选型

**Decision**: 采用 `workerman/crontab`，在独立进程 `app/process/NotificationCleanup.php` 中注册每日任务，批量删除 `learner_notifications` 中 `created_at < NOW() - INTERVAL 2 MONTH` 的行。

**Rationale**:
- 用户明确要求 [workerman/crontab](https://www.workerman.net/doc/webman/components/crontab.html)。
- 与 Webman 进程模型一致，无需宿主机 crontab。
- 分批 `DELETE ... LIMIT 500` 避免长事务锁表（FR-019）。

**Alternatives considered**:
| 方案 | 弃用原因 |
|------|----------|
| MySQL EVENT | 不可版本化、与迁移/Compose 契约脱节 |
| 宿主机 cron 调 artisan 式脚本 | 违反「容器即运行契约」 |
| 同步清理（发送时顺带删） | 无法保证固定周期、逻辑分散 |

**调度**: `0 30 3 * * *`（每日 03:30:00）；清理结果写入 `audit_log`（`action=notification.cleanup`）。

**保留策略**: 仅清理 `learner_notifications`；`notification_dispatches` 后台发送记录长期保留（规格假设）。

---

## R3: 数据模型 — 扩展 vs 新建收件箱

**Decision**: 扩展既有 `learner_notifications` 表，新增 `kind` 值 `announcement`、`internal_message`；新增 `dispatch_id` 外键关联 `notification_dispatches`。后台发送记录独立表 `notification_dispatches` + 站内信收件人表 `notification_dispatch_recipients`。

**Rationale**:
- 已有 `MessageService::emit`、`NotificationController`、`MessagesView` 与 Zod 契约；复用可最小化学习端改动。
- 统一收件箱满足 FR-009（系统通知与运营通知同列表）。
- `dispatch_id` 使学员条目可追溯到后台发送记录，支撑运营核对。

**Alternatives considered**:
| 方案 | 弃用原因 |
|------|----------|
| 完全独立 `admin_messages` 表 | 学习端需合并两列表，重复已读/未读逻辑 |
| 仅 `notification_dispatches` 无 per-learner 行 | 无法表达个人已读状态 |

**kind 列迁移**: 将 MySQL `ENUM` 改为 `VARCHAR(32)`（与 `harden_learner_notifications` 对 `resource_type` 的处理一致），避免反复 `ALTER ENUM`。

---

## R4: 公告广播投递策略

**Decision**: 同步分块 fan-out（chunk 200–500 学员 ID）：每块批量 `INSERT` `learner_notifications`，随后对该块在线学员 `trigger` 推送；管理端 API 在 fan-out 完成后返回 `201`（目标 ≤1000 学员时满足 SC-001/SC-007）。

**Rationale**:
- 单站点个人运营场景学员规模可控；分块插入 + 推送可在 30 秒/5 分钟内完成。
- 避免引入异步队列；若未来规模增大，可在 `notification_dispatches` 增加 `status=pending|completed` 与后台 worker 扩展，首版不实现。

**Alternatives considered**:
| 方案 | 弃用原因 |
|------|----------|
| 仅推送不写 per-learner 行 | 离线学员无法拉取历史、无法标记已读 |
| 单条 INSERT 循环 | 1000 学员性能差，难达 SC-007 |

**在册学员定义**: `accounts.status = 'active'` 且存在 `learners` 行（与 `LearnerController` 列表 `status=active` 一致）。

---

## R5: 未读角标 API

**Decision**: 新增 `GET /api/learner/v1/messages/unread-count`，返回 `{ count: number }`；推送事件同步携带 `unread_count` 供客户端增量更新。

**Rationale**:
- FR-012 要求导航未读数；避免每次拉全量列表。
- 与现有 `/messages` 分页列表解耦，LearnerLayout 挂载时轻量请求。

**Alternatives considered**:
| 方案 | 弃用原因 |
|------|----------|
| 扩展 `GET /me` | 耦合账号资料与消息状态，缓存失效复杂 |
| 仅依赖推送计数 | 推送不可用时角标不准（违反 FR-014） |

---

## R6: 管理端 UI 与权限

**Decision**: 新增权限码 `notification.manage`（发送 + 列表查询）；侧栏「通知管理」入口 `/notifications`；列表页 + 发送抽屉/页（类型切换公告/站内信）。

**Rationale**:
- 规格假设单一权限码；与现有 `PermissionSeeder` insert-only 模式一致。
- 站内信收件人选择复用学员列表搜索 API（`GET /learners?status=active`），需 `learner.view` 或合并到 `notification.manage`——首版要求 `notification.manage` 即可发送站内信（发送页内嵌学员选择，不强制额外权限）。

**正文格式**: 首版纯文本（`textarea`），标题 ≤200 字、正文 ≤10000 字；满足 FR-003 且避免新富文本编辑器依赖。

---

## R7: 审计记录

**Decision**: 管理员发送写入 `audit_log`：`action=notification.send`，`target_type=notification_dispatch`，`target_id=dispatch.id`，`payload_json` 含 `type`、`recipient_count`。清理任务写入 `action=notification.cleanup`。

**Rationale**: 对齐 `CourseStudentService` 现有 `audit_log` 用法；满足 FR-020。不扩展 `moderation_logs`（该表专用于评价审核）。

---

## R8: Docker / 网络

**Decision**: `compose.yaml` 为 `api` 服务增加 expose `3131`、`3232`；`docker/web` 与 `docker/admin` Nginx 增加可选 `location` 将 `/app/{app_key}` WebSocket 反代到 `api:3131`（学习端生产路径）。本地开发可通过 `VITE_PUSH_URL` 指向 `ws://localhost:3131`。

**Rationale**: HTTPS 页面需 `wss` 同源反代（webman 文档 wss 代理模式）；保持学习端不直连跨域 WebSocket。
