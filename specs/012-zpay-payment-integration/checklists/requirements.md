# Specification Quality Checklist: 012 Z-Pay 接入

**Purpose**: Validate specification completeness and quality before proceeding to planning
**Created**: 2026-09-04
**Feature**: [spec.md](../spec.md)

## Content Quality

- [x] No implementation details (languages, frameworks, APIs) — 仅描述「应该发生什么」与契约，PHP/SQL/迁移/AES 选型写在约束而非需求
- [x] Focused on user value and business needs — 围绕「学员能付钱 / 运营能配置 / 审计可追」三件事
- [x] Written for non-technical stakeholders — User Scenarios 部分使用「作为运营 / 学员」自然语言
- [x] All mandatory sections completed — User Scenarios / Functional Requirements / Success Criteria / Key Entities / Assumptions 全部填充

## Requirement Completeness

- [x] No [NEEDS CLARIFICATION] markers remain — 所有不确定项已用合理默认落到 Assumptions 与 Open Questions
- [x] Requirements are testable and unambiguous — 每条 FR 都列出 4-8 条 AC
- [x] Success criteria are measurable — SC-001 ~ SC-006 全部含具体数字（3 分钟 / 1 次请求 / 5 秒 / 100 次 / 全链路）
- [x] Success criteria are technology-agnostic — 仅以业务结果描述（"学员 1 次请求内收到 403"），不写 PDO / axios / SQL 字样
- [x] All acceptance scenarios are defined — Primary User Flow 5 条 + Edge Cases 8 条
- [x] Edge cases are identified — 密钥变更 / 双重回调 / 不在白名单 / 通道未启用 / 未知订单号 / 金额不一致 / 重放 / 停用条目
- [x] Scope is clearly bounded — 「Out of Scope」段明确退款 / 多商户 / 密钥轮转 / 主动轮询 / RSA 升级不在本 spec
- [x] Dependencies and assumptions identified — Assumptions 7 条 + Dependencies 6 条（Blocking 3 + Non-Blocking 3）

## Feature Readiness

- [x] All functional requirements have clear acceptance criteria — FR-001 ~ FR-010 全部带 AC
- [x] User scenarios cover primary flows — 配置 / 白名单 / 下单 / 失败 / 审计 5 条
- [x] Feature meets measurable outcomes defined in Success Criteria — SC 与 FR 一一呼应
- [x] No implementation details leak into specification — 提及 PHP/phinx/AES 仅作为约束 / 现状描述；FR 主体是「做什么」

## Notes

- 本 spec 与 `010-course-notify-feedback-codes` / `011-catalog-data-mapper` 共用模板结构
- 任何涉及 `AuditLogService::writeAudit`、`PaymentAdapter` 接口、`OrderService::createPending` 的位置实现前必须先 Read 当前签名
- FR-008 迁移文件需走 `make migrate` 流程（`README.md` 已记录）
- 后续如发现 z-pay 文档与 `docs/epay/` 行为差异，优先以官方文档（https://z-pay.cn/doc.html）为准并回写本 spec
