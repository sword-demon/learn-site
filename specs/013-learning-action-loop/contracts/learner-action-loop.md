# 学习行动循环契约

前缀和响应 envelope 沿用现有学习端 API：`/api/learner/v1` 与 `ApiResponse<T>`。所有日期时间为服务端 `Asia/Shanghai` 生成的 ISO 8601 字符串。所有受保护接口使用学习端访问令牌。

## 主下一步查询

### `GET /api/learner/v1/me/next-action`

无请求参数。每次请求从 MySQL 当前事实重新计算，不使用客户端传入的进度、课程 ID、访问权或排序结果。响应不得被公共首页缓存；建议响应头 `Cache-Control: private, no-store`。

成功响应的 `data` 形状：

```json
{
  "state": "ready",
  "action": {
    "type": "continue_lesson",
    "priority": 3,
    "rule_code": "continue_authorized_lesson",
    "reason_code": "CONTINUE_LAST_LESSON",
    "title": "继续学习：HTTP 请求生命周期",
    "reason": "继续上次未完成的课节",
    "target": {
      "resource_type": "lesson",
      "resource_id": 42,
      "path": "/learn/7/42"
    },
    "availability": "available",
    "availability_reason": null,
    "generated_at": "2026-09-04T10:00:00+08:00"
  },
  "fallback": null,
  "evaluated_at": "2026-09-04T10:00:00+08:00",
  "degraded_dependencies": []
}
```

`action` 最多一个。`priority` 只用于说明服务端采用了哪一层固定规则，客户端不能拿它重新排序。`target.path` 是服务端根据已知资源类型生成的学习端内部路由；打开路径时仍必须经过目标页面和 API 的身份、状态、访问权校验。

### 状态与枚举

| 字段 | 值 | 说明 |
|------|----|------|
| `state` | `ready` | 有一个可执行主行动 |
| `state` | `empty` | 没有可执行候选，也没有可用浏览入口；`action=null` |
| `state` | `degraded` | 一个或多个事实依赖失败；只返回已确认的行动或服务端确认的降级入口 |
| `type` | `pay_order` | 进入本人仍有效的待支付订单 |
| `type` | `use_coupon` | 进入本人适用的临期优惠券列表/结算入口 |
| `type` | `continue_lesson` | 进入本人有访问权的未完成有效课节 |
| `type` | `open_message` | 进入本人未读且资源仍可用的消息目标 |
| `type` | `continue_map` | 进入本人已开始地图的下一有效步骤 |
| `type` | `start_favorite_course` | 进入仍收藏且尚未开始的已发布课程 |
| `type` | `browse_courses` / `browse_maps` | 没有更高优先级候选时的服务端浏览降级入口 |
| `availability` | `available` | 目标当前可直接执行 |
| `availability` | `requires_access` | 目标课程详情可打开，但课程学习需要取得访问权；不得自动授权 |
| `availability` | `unavailable` | 仅用于可解释的降级结果，不得作为客户端可执行按钮 |

候选优先级从 1 到 7 分别对应订单临期、优惠券临期、继续课节、未读资源消息、地图下一步、收藏课程、浏览入口。主行动原因必须是面向学员的文本，不能把数据库状态、类名或内部错误码直接展示给学员；`rule_code`/`reason_code` 供契约测试和运维解释。

无候选时的合法响应示例：

```json
{
  "state": "ready",
  "action": {
    "type": "browse_courses",
    "priority": 7,
    "rule_code": "fallback_browse",
    "reason_code": "NO_ACTIONABLE_CANDIDATE",
    "title": "浏览课程",
    "reason": "暂时没有待继续的学习任务",
    "target": { "resource_type": "course_list", "resource_id": null, "path": "/" },
    "availability": "available",
    "availability_reason": null,
    "generated_at": "2026-09-04T10:00:00+08:00"
  },
  "fallback": null,
  "evaluated_at": "2026-09-04T10:00:00+08:00",
  "degraded_dependencies": []
}
```

当课程/消息/地图等依赖暂时失败，服务端不能用本地旧值补齐 `action`：

```json
{
  "state": "degraded",
  "action": null,
  "fallback": {
    "type": "browse_courses",
    "reason_code": "ACTION_DEPENDENCY_UNAVAILABLE",
    "title": "浏览课程",
    "reason": "学习状态暂时无法读取，请从课程目录继续",
    "target": { "resource_type": "course_list", "resource_id": null, "path": "/" },
    "availability": "available"
  },
  "evaluated_at": "2026-09-04T10:00:00+08:00",
  "degraded_dependencies": ["learning_progress"]
}
```

未登录请求返回现有 `401 UNAUTHENTICATED`，不得返回匿名学员的 `empty` 或 `degraded` 个性化数据。

## 完成后的刷新契约

`POST /api/learner/v1/lessons/{id}/progress` 和 `POST /api/learner/v1/lessons/{id}/video-heartbeat` 继续先返回现有权威 `LessonProgressDTO`。客户端收到 `completed=true` 后必须立即再次调用 `GET /me/next-action`；客户端不得从 `last_lesson_id`、本地进度百分比或地图列表猜测下一个目标。

```text
完成课节
  -> ProgressService 事务内单调写入 lesson_progresses/course_enrollments
  -> 客户端接受 LessonProgressDTO
  -> GET /me/next-action
  -> 重新读取课程、地图、消息、订单和优惠券事实
  -> 替换当前主行动
```

重复完成、跨设备刷新和旧页面提交仍由 `ProgressService` 的数据库锁与单调完成规则处理；next-action endpoint 不接受客户端状态回写。完成课程后，如果地图下一课程没有访问权，响应应为 `continue_map` + `requires_access` 并指向课程详情，而不是授予访问权或直接打开课节。

## 学习端自动提醒消息

### `LearnerNotificationKind`

共享 Zod `LearnerNotificationKind` 新增：

```text
learning_reminder
```

四类规则不拆成四个 kind，具体规则放在 payload 中：

```json
{
  "rule_code": "coupon_expiring",
  "reason_code": "COUPON_EXPIRES_WITHIN_3_LOCAL_DAYS",
  "generated_at": "2026-09-04T08:00:00+08:00"
}
```

这样消息中心只需要一个“学习提醒”展示类别，同时保留可测试、可追踪的规则标识。

### `LearnerNotificationDTO` 增量

现有字段继续保留，`resource_type` 扩展为：

```text
question | course | lesson | learning_map | order | coupon
| course_list | map_list | coupon_list | order_list
```

新增或明确以下字段：

```json
{
  "id": 501,
  "kind": "learning_reminder",
  "title": "优惠券即将到期",
  "body": "你的优惠券将于 2026-09-07 23:59 到期",
  "resource_type": "coupon",
  "resource_id": 88,
  "resource_path": "/me/coupons",
  "resource_available": true,
  "resource_unavailable_reason": null,
  "payload": {
    "rule_code": "coupon_expiring",
    "reason_code": "COUPON_EXPIRES_WITHIN_3_LOCAL_DAYS",
    "generated_at": "2026-09-04T08:00:00+08:00"
  },
  "read": false,
  "created_at": "2026-09-04T08:00:00+08:00"
}
```

`resource_path` 和 `resource_unavailable_reason` 由消息列表请求时服务端重新解析。历史无资源消息可以返回 `null`；旧消息的未知资源类型不能被客户端当成可用路径。若资源已失效，返回 `resource_available=false`、路径为 `null` 或服务端确认的列表降级路径，并提供面向学员的不可用原因。现有 `POST /messages/{id}/read` 的本人校验、幂等性和未读计数规则不变；GET 消息或 GET next-action 不自动标记已读。

### 规则到消息资源

| `rule_code` | `kind` | 首选资源 | 无法确定时的降级 |
|-------------|--------|----------|------------------|
| `favorite_not_started` | `learning_reminder` | `course/{id}` → `/courses/{id}` | `/` 课程目录 |
| `order_expiring` | `learning_reminder` | `order/{id}` → `/me/orders/{id}` | `/me/orders` |
| `coupon_expiring` | `learning_reminder` | `coupon/{id}` → `/me/coupons` | `/me/coupons` |
| `learning_inactive` | `learning_reminder` | 当前可执行课节/地图/收藏课程 | `/` 课程目录或 `/maps` |

资源解析必须验证当前学员：订单和优惠券带 `learner_id`；课节验证课程发布状态、课节 enabled 和 entitlement/试看条件；地图验证 published；课程验证 published。路径可访问不等于课程内容已授权。

## 推送和调度边界

成功写入 `learner_notifications` 后继续由现有 `UnreadCounterService` 增加未读计数，并按现有 Push 可用性发送发现提示。Push 失败不回滚消息、不重建消息；消息列表是完整事实来源。自动提醒不计入管理员消息和必要系统消息的内容/已读语义，但每日 3 条限制只作用于 `learning_reminder`。

提醒评估不是公开 HTTP endpoint。`learner.reminder.evaluate` 由 scheduled task 每 5 分钟执行，使用相同的事件键和 `MessageService` 幂等键重试。22:00–08:00 只记录 `quiet_hours`，不产生未读消息或主动 Push；08:00 后按订单、优惠券、收藏、长期未学习的顺序重新验证并最多发送当日剩余额度。

## Zod 对齐要求

在 `packages/contracts/src/learningAction.ts` 新增并从 `index.ts` 导出 `NextActionTargetDTO`、`NextActionDTO`、`NextActionFallbackDTO`、`LearnerNextActionDTO`；在 `notification.ts` 扩展 kind、resource type 和两个资源状态字段。`apps/web/src/api/learningAction.ts` 必须使用 `ApiResponse(LearnerNextActionDTO).parse(data)`，不能使用未校验的 Axios 泛型或类型断言。
