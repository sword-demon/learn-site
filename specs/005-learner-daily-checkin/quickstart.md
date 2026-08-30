# Quickstart 验证指南：学员每日签到与每日计划

目标：在实现完成后，验证 [spec.md](./spec.md) 四条用户故事与 [contracts/](./contracts/) 行为契约。实现细节见后续 `tasks.md`（`/speckit-tasks` 生成）。

## 前置

- Compose 栈已启动：

```bash
docker compose up -d --build
docker compose exec api php vendor/bin/phinx migrate
docker compose exec api php vendor/bin/phinx seed:run
```

- 管理端 `${ADMIN_PORT:-8081}`、学习端 `${WEB_PORT:-8080}` 可访问
- 测试账号：超级管理员（或已授予 `checkin.manage`）；至少 2 名 active 学员

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
- `apps/api/tests/DailyCheckinTest.php` — 签到、重复拒绝、隔离、删除后重签、净化
- `apps/admin/tests/CheckinListView.test.ts` — 列表筛选与删除确认
- `apps/web/tests/DailyCheckinDialog.test.ts` — 弹窗显示/关闭/签到成功
- `apps/web/tests/CheckinListView.test.ts` — 分页与空态
- `packages/contracts/src/__tests__/dailyCheckin.test.ts` — Zod 契约

---

## 人工验收剧本

### 1. 学员当日签到（US1）

1. 学员 A 登录学习端，确认当日未签到。
2. 在签到弹窗中输入富文本计划（加粗、列表），提交。
3. 弹窗关闭；刷新页面不再弹出。
4. 再次调用签到 API 或尝试从列表页「去签到」→ 提示「今日已签到」。
5. 打开「每日签到」列表页，见刚提交的记录，富文本格式正确。

**通过**: 每自然日仅一条记录；空计划被拒绝。

### 2. 进入学习端弹窗（US2）

1. 学员 B 当日未签到，打开学习端首页 → 3 秒内出现签到弹窗。
2. 关闭弹窗不签到，同标签页浏览其他页面 → 不再弹出。
3. 关闭浏览器标签，重新打开学习端 → 弹窗再次出现。
4. 学员 A（已签到）登录 → 任意页面均不弹窗。

**通过**: 与 FR-005–FR-007 一致。

### 3. 学员签到列表（US3）

1. 为学员 A 预置或连续多日签到 ≥15 条。
2. 打开 `/me/checkins`（或等价路由）→ 倒序分页正确。
3. 学员 B 登录 → 仅见自己的记录，不见 A 的计划内容。

**通过**: 跨学员泄露率为 0。

### 4. 管理端查询与删除（US4）

1. 管理员登录 → 侧栏「签到管理」→ 列表见全部学员记录。
2. 按学员 A 筛选 → 仅 A 的记录；设置日期范围 → 结果正确。
3. 打开详情 → 见完整富文本计划。
4. 删除学员 A 今日记录 → 确认后列表消失。
5. 学员 A 重新进入学习端 → 签到弹窗再次出现；可重新签到。

**通过**: 删除后可重签；无 `checkin.manage` 账号访问被拒。

### 5. 边界

| 场景 | 预期 |
|------|------|
| 未登录访问 `/me/checkins` | 跳转登录 |
| 提交含 `<script>` 的计划 | 净化后安全存储与展示 |
| 并发双 POST 同日签到 | 仅一条成功 |
| 23:59 与次日 00:01 各签一次 | 各计不同 `checkin_date` |

---

## API 快速探测（curl）

```bash
# 学员令牌 $LEARNER_TOKEN
curl -s -H "Authorization: Bearer $LEARNER_TOKEN" \
  http://localhost:8787/api/learner/v1/checkins/today | jq

curl -s -X POST -H "Authorization: Bearer $LEARNER_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"plan_html":"<p>今日学完第1章</p>"}' \
  http://localhost:8787/api/learner/v1/checkins | jq

# 管理端令牌 $ADMIN_TOKEN
curl -s -H "Authorization: Bearer $ADMIN_TOKEN" \
  "http://localhost:8787/api/admin/v1/checkins?page=1&limit=10" | jq
```

---

## 相关文档

- 数据模型：[data-model.md](./data-model.md)
- 学习端契约：[contracts/learner-daily-checkin.md](./contracts/learner-daily-checkin.md)
- 管理端契约：[contracts/admin-daily-checkin.md](./contracts/admin-daily-checkin.md)
- 研究决策：[research.md](./research.md)
