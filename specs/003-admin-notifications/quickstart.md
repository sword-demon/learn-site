# Quickstart 验证指南：管理后台通知与学员消息中心

目标：在实现完成后，验证 [spec.md](./spec.md) 五条用户故事与 [contracts/](./contracts/) 行为契约。实现细节见后续 `tasks.md`（`$speckit-tasks` 生成）。

## 前置

- Compose 栈已启动且包含 push 端口（`3131`/`3232`）:

```bash
docker compose up -d --build
docker compose exec api php vendor/bin/phinx migrate
docker compose exec api php vendor/bin/phinx seed:run
```

- 管理端 `${ADMIN_PORT:-8081}`、学习端 `${WEB_PORT:-8080}` 可访问
- 测试账号：超级管理员（或已授予 `notification.manage`）；至少 2 名 active 学员

## 自动化门禁（实现后执行）

```bash
# API
docker compose exec api composer test
docker compose exec api composer stan

# 契约
pnpm --filter @learn-site/contracts test

# 管理端
pnpm --filter @learn-site/admin lint
pnpm --filter @learn-site/admin typecheck
pnpm --filter @learn-site/admin test
pnpm --filter @learn-site/admin build

# 学习端
pnpm --filter @learn-site/web lint
pnpm --filter @learn-site/web typecheck
pnpm --filter @learn-site/web test
pnpm --filter @learn-site/web build
```

**预期新增测试**（实现阶段）:
- `apps/api/tests/NotificationDispatchTest.php` — 公告/站内信发送、权限、收件人隔离
- `apps/api/tests/NotificationCleanupTest.php` — 2 个月清理边界
- `apps/api/tests/NotificationPushAuthTest.php` — 私有频道鉴权
- `apps/admin/tests/NotificationListView.test.ts`
- `apps/web/tests/MessagesView.test.ts` — 扩展 kind 标签与未读角标
- `apps/web/tests/PushNotifications.test.ts` — composable 降级逻辑

---

## 人工验收剧本

### 1. 发送公告（US1）

1. 管理员登录后台 → 侧栏「通知管理」→「发送公告」。
2. 填写标题与正文，确认发送。
3. 列表出现新记录：类型「公告」、目标「全体学员」、发送时间正确。
4. 学员 A 已登录学习端：5 秒内导航「消息」出现未读角标；打开消息页见未读公告。
5. 学员 B 未登录：登录后打开消息页，见同一公告且为未读。
6. 无 `notification.manage` 的账号访问 `/notifications` → 403 或重定向无权限页。

**通过**: 步骤 2–5 与 spec 验收场景一致。

### 2. 发送站内信（US2）

1. 管理员 → 发送站内信 → 选择学员甲、乙（不选丙）。
2. 发送成功，列表显示「2 名学员」。
3. 学员甲、乙消息列表各出现未读站内信；学员丙列表无此条。
4. 学员甲标记已读 → 未读样式消失，角标减 1。

**通过**: 非收件人零泄露（SC-004）。

### 3. 后台查询（US3）

1. 在通知列表按类型筛选「公告」→ 仅公告。
2. 设置时间范围 → 结果在范围内。
3. 打开站内信详情 → 见正文与收件人摘要。

### 4. 学员消息中心与实时推送（US4）

1. 学员登录，导航显示未读数（有未读时）。
2. 保持页面打开，管理员发送新站内信 → 5 秒内角标更新。
3. 消息列表未读项有左边框或等效样式（与现有 `MessagesView` 一致）。
4. 断网或禁用 WebSocket 后刷新消息页 → 列表仍完整（FR-014 兜底）。

### 5. 过期清理（US5）

1. 在测试库插入 `created_at` 为 61 天前与 59 天前的 `learner_notifications` 各若干条。
2. 触发清理进程（或等待 cron）:

```bash
docker compose exec api php start.php reload
# 测试环境可手动调用 NotificationCleanupService::runOnce()
```

3. 确认 61 天前记录已删除，59 天前保留。
4. 清理期间学员仍可打开消息列表。

### 6. 边界场景

| 场景 | 操作 | 预期 |
|------|------|------|
| 空收件人 | 站内信不选学员提交 | 422，不创建记录 |
| 空标题/正文 | 提交 | 422 |
| 跨学员已读 | 学员 A 用 B 的消息 id 调 read | 404 |
| 无学员公告 | 空库发公告 | 创建 dispatch，`recipient_count=0` |
| 系统通知共存 | 触发问答回复 + 收公告 | 同列表，kind 标签不同 |

---

## API 快速探测（curl）

```bash
# 管理端登录后
curl -s -H "Authorization: Bearer $ADMIN_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"title":"测试公告","body":"正文"}' \
  http://localhost:8787/api/admin/v1/notifications/announcements

# 学员未读数
curl -s -H "Authorization: Bearer $LEARNER_TOKEN" \
  http://localhost:8787/api/learner/v1/messages/unread-count
```

---

## 与规格成功标准对照

| 成功标准 | 验证方式 |
|----------|----------|
| SC-001 | 剧本 1：发送 30 秒内完成 |
| SC-002 | 剧本 1/4：在线 5 秒内角标更新 |
| SC-003 | 剧本 4：消息页 2 秒内首屏 |
| SC-004 | 剧本 2：丙不可见 |
| SC-005 | 重复 mark read 返回 200 |
| SC-006 | 剧本 5：61/59 天边界 |
| SC-007 | 压测或 1000 学员种子数据发送公告 |
| SC-008 | 人工可用性走查 |

---

## 相关文档

- 数据模型: [data-model.md](./data-model.md)
- 管理端契约: [contracts/admin-notifications.md](./contracts/admin-notifications.md)
- 学习端契约: [contracts/learner-notifications.md](./contracts/learner-notifications.md)
- 技术调研: [research.md](./research.md)
