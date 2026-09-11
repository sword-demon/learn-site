# Quickstart: 学习事实漏斗

**Feature**: [spec.md](./spec.md)
**Date**: 2026-09-11

实现细节在 `tasks.md`。这里只验证报告是否符合规格。

## Prerequisites

- `make up` 与 `make migrate`
- 管理端登录账号具备 `course_student.view`，课程在其数据范围内
- 至少一门已发布课程，含有效课节；可选一门带试看课节的课

## Seed（管理端或测试夹具）

| Fixture | 做法 |
|---|---|
| 仅访问权 | 学员取得该课课程访问权，不打开课节 |
| 打开无进度 | 访问权生效后打开课节，Markdown/PDF 不点完成，视频停在 90% 以下 |
| 有效进度未完成课 | 至少完成一个有效课节，课程未完成 |
| 完成课程 | 完成全部有效课节，学习记录有完成时间 |
| 窗口进行中 | 访问权生效不足当前窗口，尚未打开课节 |
| 窗口已结束未打开 | 把生效时刻设在窗口长度之前，从未打开课节 |
| 仅试看 | 登录学员打开试看课节，无该课访问权 |
| 订单成功未学 | 支付成功取得访问权，从未打开课节 |
| 发布触达 | 课程发布消息已发出，`recipient_count` > 0 |

## Automated

```bash
pnpm --filter @learn-site/contracts test -- learningFactFunnel

docker compose -f compose.yaml -f compose.test.yaml --profile test build api-test
make test-api
make test-fmt

pnpm --filter @learn-site/admin test -- LearningFactFunnel
pnpm --filter @learn-site/admin lint
pnpm --filter @learn-site/admin typecheck

make rebuild-api
make rebuild-admin
```

进度相关既有测试必须保持绿色：课节完成写入不得调用漏斗服务。

## Manual scenarios

### V1. 四阶段人数可对上

**Given**: 上述 10 / 6 / 4 / 2 样本（访问权 / 打开 / 有效进度 / 完成）。
**When**: 打开 `/courses/{id}/learning-funnel`，窗口 30 日，来源全部。
**Then**: 四阶段为 10 / 6 / 4 / 2。页面出现契约中的 disclaimer。试看访客（无登录进度）不在进入队列。

### V2. 未开始不是流失

**Given**: 一名访问权刚生效未打开课节的学员，一名窗口已过仍未打开的学员。
**When**: 查看 pending。
**Then**: 前者只在“窗口进行中、尚未开始”；后者只在“窗口内未转化”。无“流失”“弃学”。

### V3. 对照区分区

**Given**: 5 笔支付成功、3 人完成课程、若干仅试看、一封课程发布消息。
**When**: 看同一页。
**Then**: 订单成功 = 5，完成课程 = 3，试看区有仅试看学员，发布触达与进入队列分两行。不能把 5 读成完成人数。

### V4. 按来源拆分

**Given**: 免费加入 / 支付成功 / 激活码兑换人数已知。
**When**: 切 `source`。
**Then**: 各来源进入队列之和等于全部；支付成功筛选不含免费加入。无“付费带来完成”文案。

### V5. 看报告时学员仍能记进度

**Given**: 管理员连续刷新漏斗。
**When**: 另一学员提交课节有效进度。
**Then**: 进度按既有规则成功；学员反馈不因报表刷新失败。稍后刷新漏斗可见该有效进度（允许标明非实时）。

### V6. 范围与空状态

**Given**: 数据范围外课程 id，以及一门窗口内零访问权的课。
**When**: 直开漏斗 URL。
**Then**: 范围外与课程学员列表相同拒绝；零访问权显示空状态，不把试看/订单填进四阶段。
