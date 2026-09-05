# Quickstart：学习行动循环

**Feature**：`013-learning-action-loop`

**目标**：验证登录后唯一主行动、四类固定提醒、提醒节流与勿扰、资源权限复核，以及完成学习后的跨设备更新。字段和响应以 [学习端/API 契约](./contracts/learner-action-loop.md) 为准，数据边界以 [data-model.md](./data-model.md) 为准。

## 前置条件

- macOS 已启动 OrbStack，仓库根目录可执行 Docker Compose v2。
- 已准备 `.env`，并能通过 `make bootstrap` 启动 API、学习端、管理端、MySQL 和 Redis。
- 已有至少两个 active learner 账号，并能登录学习端；一个账号用于跨设备验收。
- 已准备一门已发布课程，包含至少两个可学习课节；另准备一门已发布但尚未开始的课程。
- 验收数据能够覆盖 active entitlement、课程进度、已开始学习地图、收藏、待支付订单、未使用优惠券和既有消息。
- 时间窗口场景使用测试时钟或测试夹具固定服务端时间，不依赖真实等待 7 天；所有时间均按 `Asia/Shanghai` 判断。

订单临期沿用当前订单生命周期的 `created_at + 15 分钟` 截止时间，因此新建待支付订单会立即落入 24 小时临期窗口。真正具有“提前一天”含义的长时订单不属于本特性验收范围。

## 启动与迁移

实现完成后，在仓库根目录执行：

```bash
make rebuild-all
make migrate
make seed
curl -sf http://localhost:8787/health
```

预期 API 健康检查返回成功；迁移创建 `learner_reminder_evaluations`，并注册 `learner.reminder.evaluate` 定时任务。日常修改后必须重建对应镜像，`make restart` 不会更新镜像内的 PHP 或静态文件。

定义手工探测变量：

```bash
export API_BASE_URL="${API_BASE_URL:-http://localhost:8787}"
export LEARNER_TOKEN="登录学习端后取得的学员访问令牌"
export ADMIN_TOKEN="登录管理端后取得的管理员访问令牌"
```

## 场景 1：登录后只有一个主行动

1. 未携带令牌请求主行动：

   ```bash
   curl -i "$API_BASE_URL/api/learner/v1/me/next-action"
   ```

   预期返回 `401` 和 `UNAUTHENTICATED`，不返回匿名学员的空状态或个性化数据。

2. 学员携带令牌请求主行动：

   ```bash
   curl -sS \
     -H "Authorization: Bearer $LEARNER_TOKEN" \
     "$API_BASE_URL/api/learner/v1/me/next-action" | jq '.data'
   ```

   预期 `action` 最多一个，且包含 `type`、`target.path`、`title`、`reason`、`availability` 和 `generated_at`。响应的 `reason` 应能直接解释当前规则，例如“继续上次未完成的课节”；`target.path` 由服务端生成。

3. 同时准备多个候选并重复刷新。预期固定按订单、优惠券、继续课节、未读资源消息、地图下一步、收藏课程、浏览入口的优先级选择；同一优先级按契约规定的相关时间和稳定资源键决胜。不同浏览器、重复登录和刷新不得改变相同输入下的行动目标。

4. 检查缓存边界：

   ```bash
   curl -sSI \
     -H "Authorization: Bearer $LEARNER_TOKEN" \
     "$API_BASE_URL/api/learner/v1/me/next-action"
   ```

   预期该个性化响应不会被公开首页缓存，至少不能以公共缓存复用不同学员的结果。

## 场景 2：完成课节后重新计算下一步

1. 在学习端或通过既有课节接口打开第一个课节，确保服务端记录 `opened_at`。
2. 完成第一个课节。Markdown/PDF 可使用以下既有进度接口，`LESSON_ID` 替换为已授权课节：

   ```bash
   export LESSON_ID="第一个课节的 ID"
   curl -sS -X POST \
     -H "Authorization: Bearer $LEARNER_TOKEN" \
     -H 'Content-Type: application/json' \
     -d '{"content_type":"markdown","position_seconds":0,"completed":true}' \
     "$API_BASE_URL/api/learner/v1/lessons/$LESSON_ID/progress" | jq '.data'
   ```

3. 立即重新请求 `GET /api/learner/v1/me/next-action`。预期返回第二个有效未完成课节；若课程已完成，则返回地图的下一有效步骤或下一个固定优先级候选。
4. 在设备 B 使用同一学员账号刷新并请求同一接口。预期设备 B 看到与设备 A 相同的最新学习结果和下一步，旧页面不能让进度回退。两个设备同时提交完成时，完成结果和主行动仍应幂等。
5. 在生成行动后撤销访问权、下架课程或归档课节，再点击旧目标。预期服务端重新校验并返回替代行动或明确不可用说明，不展示受限内容。

## 场景 3：四类自动提醒

使用测试夹具或既有业务入口，为同一学员准备以下四种状态：

| 规则 | 测试状态 | 预期资源 |
|---|---|---|
| `favorite_not_started` | 已发布课程仍被收藏，收藏后已超过 24 小时，且没有学习记录 | 课程详情或课程目录 |
| `order_expiring` | 本人订单状态为 pending，截止时间仍未到且在 24 小时内 | 本人订单后续支付或订单列表 |
| `coupon_expiring` | 本人优惠券未使用、未锁定、未作废、仍适用，距截止不超过 3 个自然日 | 优惠券列表或确定的结算入口 |
| `learning_inactive` | 最近一次有效学习行为已超过 7 个上海自然日，且仍有可执行课程、地图步骤或收藏课程 | 当前可执行的学习目标或课程目录 |

运行已注册的定时任务。可以在管理端「自动任务」页面手动运行，也可以先查询任务 ID，再调用既有管理端接口：

```bash
export TASK_ID="$({
  curl -sS -H "Authorization: Bearer $ADMIN_TOKEN" \
    "$API_BASE_URL/api/admin/v1/scheduled-tasks" |
    jq -r '.data.items[] | select(.handler_code == "learner.reminder.evaluate") | .id' |
    head -n 1
})"

curl -sS -X POST \
  -H "Authorization: Bearer $ADMIN_TOKEN" \
  "$API_BASE_URL/api/admin/v1/scheduled-tasks/$TASK_ID/run" | jq
```

读取学习端消息：

```bash
curl -sS \
  -H "Authorization: Bearer $LEARNER_TOKEN" \
  "$API_BASE_URL/api/learner/v1/messages?limit=100" |
  jq '.data.items[] | select(.kind == "learning_reminder") |
      {id, kind, title, resource_type, resource_id, resource_path,
       resource_available, resource_unavailable_reason, payload, read}'
```

每类规则应最多生成一条与事件资源对应的 `learning_reminder`，消息带有可理解的标题、原因、规则码、目标路径和生成时间。点击消息后，资源仍需按当前学员身份、课程状态、订单状态、优惠券状态和访问权重新校验。

## 场景 4：重复评估、每日上限和勿扰

### 重复评估与节流

1. 在没有改变资源状态的情况下连续运行两次 `learner.reminder.evaluate`。
2. 再次读取消息和未读计数：

   ```bash
   curl -sS -H "Authorization: Bearer $LEARNER_TOKEN" \
     "$API_BASE_URL/api/learner/v1/messages/unread-count" | jq '.data.count'
   ```

   预期相同学员、规则和事件键不新增消息、不重复增加未读数；评估记录可以进入 `throttled`，但 `send_count` 只统计真实消息。

### 每日最多 3 条

1. 让同一学员同时命中四类规则，并确保当天尚未产生自动提醒。
2. 运行评估任务并读取消息。
3. 预期最多生成 3 条自动提醒，保留顺序为订单临期、优惠券临期、收藏待开始、长期未学习；被跳过的规则当天不在后续批次集中补发。管理员消息和必要系统消息不计入这 3 条，也不得被删除或覆盖。

### 勿扰时段

使用固定服务端时间分别验证 `21:59`、`22:00`、次日 `07:59` 和 `08:00`：

- `22:00–08:00` 运行评估时，普通自动提醒不写入未读消息，不触发主动 Push，并留下可解释的 `quiet_hours` 评估结果。
- `08:00` 后重新评估仍满足条件时，只发送当日剩余额度允许的消息。
- 顺延发送不能把前一晚多种提醒一次性补发到超过每日上限。

## 场景 5：资源失效和实时提示不可用

1. 先生成一条带资源的自动提醒，再将关联订单支付、优惠券使用或过期，取消收藏，或下架关联课程。
2. 重新读取消息并点击目标。预期返回 `resource_available=false` 和面向学员的不可用说明，或服务端确认的列表降级路径；不能重新发起已完成支付、使用已失效优惠券，也不能泄露他人资源。
3. 在浏览器中阻断 Push/WebSocket 连接或使用无实时提示的测试环境，打开消息中心并调用消息列表接口。预期已生成的提醒、未读状态和资源跳转仍准确，实时提示失败不触发重复评估或重复消息创建。

## 自动化门禁

在 Compose 环境中执行：

```bash
make test
make phpstan
make verify-migrations
make verify-runtime-boundaries
make test-e2e
make e2e-down
```

预期覆盖以下结果：

- API 测试覆盖主行动固定排序、未登录拒绝、访问权复核、降级响应、四类规则、唯一事件键、并发评估、每日上限和勿扰。
- 共享契约与学习端测试覆盖 `LearnerNextActionDTO`、`learning_reminder` 消息字段、资源可用性、完成后重新读取和实时提示降级。
- `make test` 同时通过 PHP 格式检查、前端 Prettier、ESLint、TypeScript、Vitest 和两个前端生产构建。
- `make test-e2e` 在独立 Compose project 中验证登录、学习、消息和跨页面旅程；验收结束用 `make e2e-down` 清理独立测试卷，不删除日常 Compose 数据卷。

## 验收清单

- [ ] 未登录不返回个性化主行动。
- [ ] 登录后只突出一个主行动，原因和资源路径来自服务端响应。
- [ ] 完成课节后 5 秒内通过重新读取看到下一步，跨设备结果一致。
- [ ] 四类提醒均能进入当前可校验的资源或降级入口。
- [ ] 同事件重复率为 0%，每日自动提醒不超过 3 条。
- [ ] `22:00–08:00` 普通提醒主动触达率为 0%，且次日不发生洪峰补发。
- [ ] 资源失效、权限变化和实时提示失败均不产生死链、越权内容或重复未读数。
- [ ] 计划中的唯一新增持久化对象为 `learner_reminder_evaluations`；下一步、进度、访问权和地图状态未复制到前端或新状态表。
