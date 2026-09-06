# Quickstart: 课程分销方案

**Feature**: 016-course-distribution
**Spec**: [spec.md](./spec.md)
**Data model**: [data-model.md](./data-model.md)
**Contracts**: [contracts/](./contracts/)

## 范围

这份 quickstart 不是实现教程, 是一份可执行的端到端验收脚本. 每条场景对应 spec 里一条可验收的 FR / SC, 跑通即可视为该条款验收通过.

运行环境: 已 `make bootstrap` 成功的本地栈. 涉及到的命令统一走 Makefile, 涉及到的测试统一 `make test` 或 `make test-api` / `make test-web` / `make test-admin` / `make test-e2e`.

## 前置

- 已 `make bootstrap`.
- 已 `make rebuild-all` 一次 (因为 `packages/contracts` 与 `apps/api` 都改了).
- 已 `make migrate` 把新表 `share_entries` / `share_visits` / `distribution_course_overrides` / `commission_records` / `distribution_audit_log` 与 `learners.referrer_learner_id` 加上.
- 已有至少 3 名学员 A / B / C / D / E (5 人), 一门已发布课程 `course_id=1`, 一个管理端账号 `admin`.

## 验证场景

### SC-005 + SC-006: 推荐关系绑定与不污染

1. 学员 A 登录, POST `/api/learner/distribution/share-entries` `{scope:'course', course_id:1}` → 响应含 `plaintext_code` 与 `share_url`. 立刻 GET `/api/learner/distribution/share-entries` → 看到一条, `masked_code` 形如 `ABCD****WXYZ`. 不得再次看到 `plaintext_code`.
2. 打开无痕浏览器, 访问 `share_url` → 课程详情页正常打开, 不显示 A 的昵称或头像. 服务端 DB 看到 `share_visits` 多一条, `bound_learner_id = NULL`.
3. 在无痕浏览器用未注册手机号 `13800000001` 注册新学员 X. 注册成功后:
   - `SELECT referrer_learner_id FROM learners WHERE phone='138****0001'` → 等于 A 的 id.
   - `share_visits.bound_learner_id` 变为 X 的 id, `bound_at` 非空.
4. 用同一无痕浏览器再点 `share_url` → 课程详情页无差别, DB 再多一条 share_visits.
5. 用同一无痕浏览器用未注册手机号 `13800000002` 注册新学员 Y. 注册成功后:
   - Y 的 `referrer_learner_id IS NULL`.
   - A 的 share_entry `bound_count` 仍为 1.
6. 让老学员 B 在已登录态点 A 的 `share_url` → B 的 `referrer_learner_id` 不变 (若原本为空, 仍为空), DB 中无新 `share_visits` 或 `bound_learner_id` 不指向 B.

期望: 1-6 全过即 SC-005 / SC-006 验收.

### SC-001 + SC-002: 链路深度与三级上限

1. 构造链路 A → B → C → D → E (用 5 部手机依次注册, 每次注册时的 visitor_token 都来自 A → B → C → D 的 share_url).
2. E 购买 course_id=1, 走 ZPay 沙箱, 触发订单 succeeded.
3. 后台 GET `/api/admin/distribution/reconcile/by-order/<orderId>`:
   - `receivers.length === 3`.
   - 级别分别为 1/2/3, 推荐人分别是 C / B / A.
   - D 不在 receivers 列表.
   - A 之上 (若还有) 不在.
4. 试把 `distribution_configs.level_cap` 临时调为 4 (绕过应用层直接 SQL `UPDATE site_settings SET value=...`):
   - DB CHECK 约束拒绝 (`CHECK (JSON_EXTRACT(value, '$.level_cap') <= 3)`).
   - 即使绕过 CHECK, 应用层在下次保存时仍拒绝并恢复.

期望: SC-001 / SC-002 / SC-003 验收.

### SC-003: 级别上限不可被超级管理员改

1. 用超级管理员登录, PUT `/api/admin/distribution/config` `{level_cap: 4, ...}` → 返回 4xx, 错误信息含 "合规硬约束".
2. 直接 SQL 写入 `level_cap=4` → DB CHECK 报错.
3. 单测覆盖: `DistributionConfigServiceTest::testLevelCapHardLimit` 跑 `attemptSaveConfigWithLevelCap4` → 期望抛出 `BusinessException`, DB 未变更.

期望: SC-003 验收.

### SC-007: 退款 30 秒内撤销佣金

1. 沿用上面 E 的成功订单.
2. 让 E 申请退款, 走既有退款链路 (`order.status = refunded`).
3. 等待最多 30 秒, 后台 GET `/api/admin/distribution/reconcile/by-order/<orderId>`:
   - 全部 `commission_records.status === 'voided'`.
   - 全部 `void_reason === '关联订单退款'`, `source === 'system_refund_void'`.
4. `distribution_audit_log` 多一条 `commission.void_refund`, actor_type=system.

期望: SC-007 验收.

### SC-008: 对账一致性

1. 任意一笔已有结算订单, GET `/api/admin/distribution/reconcile/by-order/<orderId>` → 返回的 `receivers` 与 `commission_records WHERE order_id=?` 一致.
2. 在 DB 手动 `UPDATE commission_records SET amount_cents = amount_cents + 1 WHERE id = ?` → 再 GET 对账页, 数值立刻反映篡改, `distribution_audit_log` 不会自动多 (这是数据被篡改的负面测试, 仅说明对账页与底层一致).

期望: SC-008 验收.

### SC-009: 全场景无明文手机号

1. 学员 A GET `/api/learner/distribution/commissions` → `referee_masked_phone` 全部 `^1[3-9]\*{8}\d{4}$`.
2. 学员 A GET `/api/learner/distribution/downline` → `masked_phone` 全部脱敏.
3. 管理员 GET `/api/admin/distribution/reconcile/by-order/<orderId>` → `referrer_masked_phone` 全部脱敏.
4. 管理员导出 csv `/api/admin/distribution/commissions/export?format=csv` → 文件内所有手机号脱敏.
5. 管理员 GET `/api/admin/distribution/audit` → `before_json` / `after_json` 内不允许出现明文手机号 (前端可手动 `jq` 校验).
6. 网络抓包: 上述所有响应包体内不出现 11 位连续手机号.

期望: SC-009 验收.

### SC-010: 关闭分销后入口消失

1. 管理员 PUT `/api/admin/distribution/config` `{enabled: false, ...}` → 200.
2. 学员 A GET `/api/learner/distribution/share-entries` → 404 或 `enabled=false` 提示, 不返回列表.
3. 学员 A 访问学习端"我的分销"页 → 前端路由守卫跳 404, 接口返回 404.
4. 历史 commission_records 不变, 仍可查询.

期望: SC-010 验收.

### SC-011: 回放准确率

1. 取一笔历史订单, 记录当时的 `config_snapshot_json` 与 `order_paid_cents_snapshot`.
2. 在测试环境改 distribution_config (调比例 / 封顶), 不动订单.
3. 调 `CommissionReplay::replay($orderId)`, 输出每级应发佣金.
4. 与 commission_records 中实际写入的金额逐一对比, 全部一致.

期望: SC-011 验收.

### SC-012: 可用性

1. 让 3 名有 `distribution.config` 权限的普通员工 + 1 名超级管理员, 各自完成:
   - 打开配置页 → 修改 enabled / level1_pct → 保存 → 看到审计记录.
   - 打开对账页 → 按订单号查询一笔订单 → 看到 3 个接收人.
   - 选一条记录 → 撤销 → 填写原因 → 看到状态变 voided.
2. 让他们各自说出"为什么级别上限是 3, 不能调成 4" → 80% 以上能正确说出"我国法规硬约束".

期望: SC-012 验收.

## 自动化测试覆盖

| 条款 | 单测 | 集成测 |
|---|---|---|
| FR-001 / FR-002 推荐关系写入与不可改 | `LearnerReferrerTest` | `RegistrationReferralBindingTest` |
| FR-005 同访客多次注册只取第一个 | `ReferralBindingOnceTest` | — |
| FR-007 跨设备恢复推荐关系 | `ReferralCookieClearedRecoveryTest` | — |
| FR-008 / FR-010 级别 = {1,2,3} 且 ≤ 3 | `CommissionLevelEnumTest`, `LevelCapHardLimitTest` | — |
| FR-011 单笔 ≤ 3 接收人去重 | `CommissionReceiverUniquenessTest` | `SettlementConcurrencyTest` (并发双结算) |
| FR-012 单笔总佣金 ≤ 单笔封顶 | `CommissionCapTruncationTest` | — |
| FR-013 / FR-016 配置写入按订单快照生效 | `ConfigSnapshotReplayTest` | — |
| FR-017 所有写操作写审计 | `DistributionAuditCoverageTest` | — |
| FR-019 退款撤销 | `OrderRefundVoidCommissionTest` | — |
| FR-020 管理员撤销需 ≥ 5 字符原因 | `AdminVoidRequiresReasonTest` | — |
| FR-021 金额以分为单位 | `CommissionAmountCentsTest` | — |
| FR-022 推荐人账户不可用时不升级 | `BlockedReferrerNoUpgradeTest` | — |
| FR-023 学员端可见性 | `LearnerCommissionViewTest`, `LearnerDownlineViewTest` | — |
| FR-024 学员端零明文 | `LearnerPlaintextLeakTest` | — |
| FR-025 导出 csv 脱敏 | `ExportCsvMaskingTest` | — |

## 手工验证 (不可自动化)

- SC-012 可用性观察: 邀请 3+1 管理员实际操作 + 简答, 记录通过率.

## 跑通清单

- [ ] `make test-api` 全过, 覆盖率 ≥ 80% (新增 service 与 controller).
- [ ] `make test-web` 全过, 「我的分销」页与契约对齐.
- [ ] `make test-admin` 全过, 配置页 / 对账页 / 审计页与契约对齐.
- [ ] `make test-e2e` 通过端到端场景: 推荐关系建立 → 下单 → 结算 → 退款 → 撤销.
- [ ] `make lint` `make typecheck` `make phpstan` 全过.
- [ ] 至少跑一次 `make sh-api` 进容器, 手工执行上面的 SQL 抽查, 结果与 quickstart 一致.

## 不在 quickstart 内

- 提现 / 排行榜 / 跨账户结算 / 佣金到账消息 — FR-026 / FR-027 排除, 不验收.
