# Contracts: 学习事实漏斗

**Feature**: 017-learning-fact-funnel

## 文件

- [`learningFactFunnel.ts`](./learningFactFunnel.ts) — 前后端共享 Zod Schema。

## 落地说明

把 `learningFactFunnel.ts` 加进 `packages/contracts/src/`，并在 `packages/contracts/src/index.ts` 增加 `export * from "./learningFactFunnel";`。风格与既有模块一致：顶层 `import { z } from "zod"`，DTO 用 `z.object` + `z.infer`，时间 ISO-8601（`Asia/Shanghai`），人数 `number().int().nonnegative()`。

固定中文标签与 `disclaimer` 放在契约里，避免管理端自行写成“提升了完成率”。

## API

### `GET /api/admin/v1/courses/{id}/learning-funnel`

| 项 | 值 |
|---|---|
| 权限 | `course_student.view` |
| 数据范围 | 与课程学员列表相同 |
| Query | `window_days` = `7` \| `30` \| `90`（默认 30）；`source` = `all` \| `free` \| `purchase` \| `activation_code`（默认 `all`） |
| 成功 | `{ ok: true, data: LearningFactFunnelDTO }` |
| 课程不存在或超范围 | `NOT_FOUND` |
| 无权限 | `FORBIDDEN` |
| 非法 query | `VALIDATION_FAILED` |

只读。不写 `audit_log`，不写进度 / 访问权 / 订单。

`stages` 顺序固定：`entitled` → `first_opened` → `valid_progress` → `completed`。后一阶段 `count` 不得大于前一阶段。`pending.in_window + pending.window_elapsed + first_opened.count === entitled.count`。

## 管理端路由

| 路径 | 权限 | 说明 |
|---|---|---|
| `/courses/:id/learning-funnel` | `course_student.view` | 单课报告；从课程学员页进入 |

学习端无本功能入口。

## 不导出

- 学员手机号、名单、课节正文
- 增量、归因、实验提升字段
- 页面曝光、停留时长、客户端估算进度
