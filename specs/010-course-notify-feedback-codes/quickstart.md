# Quickstart 验证指南: 课程发布通知, 意见反馈与激活码兑换

目标: 实现完成后验证 [spec.md](./spec.md) 用户故事与 [contracts/](./contracts/). 任务拆解见后续 `$speckit-tasks` 的 `tasks.md`.

## 前置

```bash
docker compose up -d --build
docker compose exec api php vendor/bin/phinx migrate
docker compose exec api php vendor/bin/phinx seed:run
```

- 管理端 `${ADMIN_PORT:-8081}`, 学习端 `${WEB_PORT:-8080}`
- 超级管理员; 至少两名 **active** 测试学员 (一名保持学习端打开)
- 一门资料完整的 **收费草稿课**, 一门学员尚无访问权的 **已发布收费课**
- redis-queue consumer 与 webman/push 进程随 `api` 容器运行 (008 已接入)

## 自动化门禁

```bash
make test-api    # 含 CoursePublishNotify / ActivationCode / CourseFeedback PHPUnit
make test-web    # contracts + admin/web Vitest
```

## 场景 1: 发布后消息可跳转 (US1 + US6)

1. 学员 A 登录学习端并停留任意页 (保持 push 连接)
2. 管理员发布草稿收费课
3. 发布 API 在数秒内返回成功, 不必等全体投递
4. 学员 A 未读角标增加; 消息列表出现 kind=「新课」, 点击「查看关联内容」进入该课详情
5. 学员 B 稍后打开 `/me/messages` 仍能看到同一条未读并跳转
6. 管理员仅改简介再保存: **不** 出现第二条发布消息
7. 下架后再发布: 出现新的一条

```bash
curl -fsS -X POST -H "Authorization: Bearer $ADMIN_TOKEN" \
  "http://localhost:${ADMIN_PORT:-8081}/api/admin/v1/courses/${COURSE_ID}/publish" | jq .

curl -fsS -H "Authorization: Bearer $LEARNER_TOKEN" \
  "http://localhost:${WEB_PORT:-8080}/api/learner/v1/messages" | jq '.data.items[0] | {kind,resource_type,resource_id,resource_available}'
```

**负例**: 课程下架后消息入口显示「关联内容已不可用」, 不得打开非试看课节.

## 场景 2: 生成与兑换激活码 (US2 + US3)

1. 管理端打开该收费已发布课 → 激活码 → 生成 5 枚, 复制明文
2. 列表只显示 `ABCD****WXYZ` 形态
3. 学员 C (无该课访问权) 打开 `/me/redeem` 或课程详情兑换入口, 提交一枚码
4. 立即能打开非试看课节; 订单列表无对应成功单; 学员名单获得方式 = 激活码兑换
5. 同一枚码再兑 → 已兑换; 学员 C 再兑另一枚该课码 → 已有访问权且码仍为未使用
6. 作废一枚未使用码后兑换失败

```bash
curl -fsS -X POST -H "Authorization: Bearer $ADMIN_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"quantity":5,"expires_at":null}' \
  "http://localhost:${ADMIN_PORT:-8081}/api/admin/v1/courses/${COURSE_ID}/activation-code-batches" | jq .

curl -fsS -X POST -H "Authorization: Bearer $LEARNER_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"code":"AB3D-EFGH-JKMN-PQRS"}' \
  "http://localhost:${WEB_PORT:-8080}/api/learner/v1/activation-codes/redeem" | jq .
```

**负例**: 免费课或草稿课生成被拒; 两学员并发同一码仅一人成功.

## 场景 3: 意见反馈 (US4 + US5)

1. 已获权学员在课程详情「意见反馈」提交带列表的富文本
2. 公开评价区不出现该内容
3. 管理员在 `/courses/:id/feedback` 看到待处理, 打开详情见安全 HTML, 标为已处理
4. 无访问权学员提交 → `COURSE_ACCESS_REQUIRED`

```bash
curl -fsS -X POST -H "Authorization: Bearer $LEARNER_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"body_html":"<p>希望增加练习</p><ul><li>课后题</li></ul>"}' \
  "http://localhost:${WEB_PORT:-8080}/api/learner/v1/courses/${COURSE_ID}/feedback" | jq .
```

## 场景 4: 高性能抽查 (US1 / SC-001–003)

在测试库预置较多 active 学员后发布一门课:

- 发布 HTTP 在常规规模下数秒内返回
- `notification_dispatches.fan_out_status` 从 pending → running → completed
- 抽样学员 inbox 有 `course_published` 且无重复 `idempotency_key`
- 入队失败时课程仍为 published, 管理端可 `POST /notifications/{id}/retry`
