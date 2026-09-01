# Quickstart 验证指南：学员优惠券领取与下单抵扣

目标：在实现完成后，验证 [spec.md](./spec.md) 四条用户故事与 [contracts/](./contracts/) 行为契约。实现任务见 `/speckit-tasks` 生成的 `tasks.md`。

## 前置

```bash
docker compose up -d --build
docker compose exec api php vendor/bin/phinx migrate
docker compose exec api php vendor/bin/phinx seed:run
```

- 管理端 `${ADMIN_PORT:-8081}`、学习端 `${WEB_PORT:-8080}` 可访问
- 超级管理员（或已授予 `coupon.manage`）
- 测试学员账号；至少一门**收费且已发布**课程（含一门挂指定分类、一门在限时优惠期内更佳）

## 自动化门禁

```bash
make test-api    # PHPUnit + PHPStan，含 CouponTest / OrderCouponTest
make test-web    # contracts + admin/web Vitest + build
```

## 场景 1：管理员创建并公开领取（US1 + US2）

1. 管理端 → **优惠券管理** → 新建：
   - 名称：`QA满50减15`
   - 范围：无门槛（或指定分类）
   - 满 50 减 15；公开领取；总量 100；每人限领 1
   - 领取期：含今日
2. 学习端 → 学员中心 → **优惠券** → 领取中心应出现该活动
3. 点击领取 →「我的优惠券」出现未使用券

**API 抽查**:

```bash
# 可领取列表（需学员 token）
curl -fsS -H "Authorization: Bearer $LEARNER_TOKEN" \
  "http://localhost:${WEB_PORT:-8080}/api/learner/v1/coupons/claimable" | jq .

# 领取
curl -fsS -X POST -H "Authorization: Bearer $LEARNER_TOKEN" \
  "http://localhost:${WEB_PORT:-8080}/api/learner/v1/coupons/1/claim" | jq .
```

## 场景 2：定向发放（US1）

1. 创建 `claim_mode=admin_only` 的券，指定一门课程
2. 管理端对测试学员执行「定向发放」
3. 学员「我的优惠券」可见；领取中心**不**展示该活动

```bash
curl -fsS -X POST -H "Authorization: Bearer $ADMIN_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"learner_ids":[101]}' \
  "http://localhost:${ADMIN_PORT:-8081}/api/admin/v1/coupons/2/grants" | jq .
```

## 场景 3：结账选券（US3）

1. 学员打开收费课程 → **购买** → 订单确认页
2. 可选优惠券列表展示适用券与抵扣后应付预览
3. 选择 `QA满50减15` → 应付金额 = 当前价格 − 15（且 ≥ 0）
4. 提交订单 → 返回 `pending` 与含 `coupon_discount_snapshot` 的订单
5. 完成支付（Fake 适配器自动成功）→ 券变已使用，课程访问权开通

```bash
COURSE_ID=42
curl -fsS -H "Authorization: Bearer $LEARNER_TOKEN" \
  "http://localhost:${WEB_PORT:-8080}/api/learner/v1/courses/${COURSE_ID}/checkout-coupons" | jq .

curl -fsS -X POST -H "Authorization: Bearer $LEARNER_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"learner_coupon_id":501}' \
  "http://localhost:${WEB_PORT:-8080}/api/learner/v1/courses/${COURSE_ID}/orders" | jq .
```

**负例**:

- 未满减门槛 → `COUPON_MIN_AMOUNT_NOT_MET`
- 重复领取 → `COUPON_CLAIM_LIMIT_EXCEEDED`
- 已购课程再下单 → `ALREADY_ENTITLED`

## 场景 4：管理端记录与停用（US4）

1. 管理端优惠券列表查看已用/已领数量
2. 打开使用记录，筛选刚完成的订单
3. 停用进行中的活动 → 学员不可新领；未使用实例下单失败 `COUPON_VOIDED`
4. 已支付订单快照中 `coupon_discount_snapshot` 不变

## 权限与隔离

- 无 `coupon.manage` 的管理员访问 `/api/admin/v1/coupons` → `403`
- 学员令牌访问管理端优惠券 API → `403`
- 学员 A 不可查看学员 B 的 `GET /my/coupons` 数据（接口仅返回本人）

## 审计

管理端执行创建、停用、发放后，`audit_log` 存在 `coupon.create` / `coupon.disable` / `coupon.grant` 记录。

## 通过标准

- [ ] 四条用户故事验收场景均可手动复现
- [ ] `make test-api` 与 `make test-web` 全绿
- [ ] 结账页不再依赖本地优惠码占位，价格与 API 一致
- [ ] 支付成功后券状态与 `used_count` 一致；取消/失败订单释放锁定
