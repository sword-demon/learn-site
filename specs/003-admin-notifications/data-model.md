# Data Model: 003-admin-notifications

## 概览

```text
notification_dispatches (1) ──< notification_dispatch_recipients (N)  [仅站内信]
         │
         │ dispatch_id (nullable)
         ▼
learner_notifications (N) ──> accounts / learners
```

- **发送记录**（`notification_dispatches`）：管理员一次发送操作，长期保留。
- **发送收件人**（`notification_dispatch_recipients`）：站内信目标学员；公告无行（`recipient_mode=all`）。
- **学员收件箱**（`learner_notifications`）：每学员一条可见消息 + 已读状态；2 个月后由定时任务清理。

---

## notification_dispatches

管理员发送记录。

| 字段 | 类型 | 规则 |
|------|------|------|
| id | BIGINT UNSIGNED PK | 自增 |
| type | ENUM(`announcement`,`internal_message`) | 公告 / 站内信 |
| title | VARCHAR(200) | 非空 |
| body | TEXT | 非空 |
| sender_staff_id | BIGINT UNSIGNED | FK → `accounts.id`（后台账号） |
| recipient_mode | ENUM(`all`,`selected`) | 公告=`all`；站内信=`selected` |
| recipient_count | INT UNSIGNED | 发送时实际投递人数；公告=当时 active 学员数 |
| created_at | TIMESTAMP | 发送完成时间 |

**索引**: `(type, created_at DESC)`、`(sender_staff_id, created_at DESC)`

**校验**:
- `title` 长度 1–200；`body` 长度 1–10000
- `type=announcement` ⇒ `recipient_mode=all`
- `type=internal_message` ⇒ `recipient_mode=selected` 且 `recipient_count >= 1`

**状态**: 首版发送即终态，无 `draft` / `revoked`。

---

## notification_dispatch_recipients

站内信目标学员（公告不写入此表）。

| 字段 | 类型 | 规则 |
|------|------|------|
| dispatch_id | BIGINT UNSIGNED | FK → `notification_dispatches.id` ON DELETE CASCADE |
| learner_id | BIGINT UNSIGNED | FK → `accounts.id` |

**主键**: `(dispatch_id, learner_id)`

**索引**: `(learner_id)` — 可选，首版查询以 dispatch 为主

---

## learner_notifications（扩展）

既有表扩展字段与 `kind` 取值。

| 字段 | 变更 | 规则 |
|------|------|------|
| kind | 扩展 | 新增 `announcement`、`internal_message`；保留 `question_update`、`progress_reset`、`entitlement_revoked` |
| dispatch_id | 新增，NULLABLE | FK → `notification_dispatches.id` ON DELETE SET NULL；系统事件为 NULL |
| kind 列类型 | ENUM → VARCHAR(32) | 与后续扩展对齐 |

**既有字段**（不变）: `learner_id`, `title`, `body`, `payload_json`, `resource_type`, `resource_id`, `idempotency_key`, `read_at`, `created_at`

**索引**（新增/保留）:
- `(learner_id, read_at)` — 未读查询
- `(learner_id, created_at DESC)` — 列表分页
- `(dispatch_id)` — 按发送记录追溯
- `(created_at)` — 清理任务范围扫描
- UNIQUE `(learner_id, idempotency_key)` — 幂等（系统事件）；运营消息使用 `dispatch_id:learner_id` 作为 key

**清理规则**: `DELETE WHERE created_at < NOW() - INTERVAL 2 MONTH`（分批 LIMIT）

---

## 客户端 DTO 映射（逻辑实体）

### AdminNotificationDispatchDTO（后台列表/详情）

| 字段 | 来源 |
|------|------|
| id | `notification_dispatches.id` |
| type | `type` |
| title | `title` |
| body | `body`（详情） |
| sender_staff_id | `sender_staff_id` |
| sender_login | JOIN `accounts.login` |
| recipient_summary | 派生：公告→「全体学员」；站内信→「{recipient_count} 名学员」 |
| recipient_count | `recipient_count` |
| created_at | `created_at` |
| recipients | 详情页：站内信返回 `{ id, login, display_name }[]`（分页可选） |

### LearnerNotificationDTO（扩展）

在既有契约上扩展：

| 字段 | 变更 |
|------|------|
| kind | 增加 `announcement` \| `internal_message` |
| dispatch_id | 可选 `number \| null` |

### UnreadCountDTO

| 字段 | 类型 |
|------|------|
| count | `number`（≥0） |

---

## 状态转换

### 学员收件箱条目

```text
[创建] --> unread (read_at IS NULL)
[POST /messages/{id}/read] --> read (read_at = NOW())  # 幂等
[清理任务: created_at 超 2 个月] --> 物理删除
```

### 发送流程

```text
[管理员提交]
  --> 校验标题/正文/收件人
  --> INSERT notification_dispatches
  --> [站内信] INSERT notification_dispatch_recipients
  --> 分块 SELECT active learner_ids
  --> 分块 INSERT learner_notifications (idempotency_key = '{dispatch_id}:{learner_id}')
  --> 分块 Push trigger(private-learner-{id}, 'notification', payload)
  --> INSERT audit_log
  --> 返回 dispatch DTO
```

---

## 与现有系统关系

| 系统 | 关系 |
|------|------|
| `MessageService::emit` | 系统事件继续写入 `learner_notifications`；发送后调用 `PushNotificationService::notifyLearner` |
| `NotificationController` | 扩展 kind 标签；新增 `unreadCount` action |
| `MessagesView` | 扩展 `kindLabel`；可选监听 push 刷新列表/角标 |
| `LearnerLayout` | 展示未读角标 |
| `PermissionSeeder` | 新增 `notification.manage` |
| `audit_log` | 发送与清理审计 |

---

## 权限与可见性

| 操作 | 权限 |
|------|------|
| 发送公告/站内信、查看发送记录 | `notification.manage` |
| 学员列表（站内信选人） | `notification.manage`（首版发送页内嵌，不额外要求 `learner.view`） |
| 读取本人收件箱 / 未读数 / 标记已读 | 学员令牌 |
| 订阅 push 私有频道 | 学员令牌 + 频道 id 匹配 |
