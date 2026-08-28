# 支付契约

## 创建支付

`POST /api/learner/v1/courses/{id}/orders`

- 课程须为 published + paid。
- 优惠窗口在确认瞬间校验；窗口已结束则 `VALIDATION`，前端重新拉价格。
- 返回 `{order_id, status:"pending", payment:{type:"wechat_native", code_url}}`。
- MVP 的 Fake 适配器在 `FAKE_PAYMENT_DELAY_MS`（默认 3000 毫秒）后按 `success` 完成回调；没有可供管理员或学员调用的“标记成功”接口。
- 仅自动化测试可调用非公开的 fake notify seam，使用 `X-Fake-Payment-Result: succeeded|failed|cancelled|unknown` 覆盖订单状态机。该 seam 不代表本期真实支付能力，也不得作为生产入口暴露。

## 微信回调

`POST /api/internal/v1/payments/wechat/notify`

- 验签失败：记录 unknown，不授权，HTTP 仍按微信要求应答以免风暴（实现时按官方 APIv3 应答规范）。
- 成功：订单 `succeeded`，写入不可变快照，创建 `purchase` 授权。
- 明确失败/关闭：`failed` 或 `cancelled`，不授权。
- 无法判定：`unknown`，不授权，学员可见重试入口。

订单状态模型必须支持失败、取消、超时、未知四种稳定 `status` 值；学习端展示对应说明并提供重试入口，不得显示为已开通。MVP 默认 fake 流程只会自动产生成功状态，其他状态通过测试 seam 或后续真实适配器进入。

## 查询

`GET /api/learner/v1/orders/{id}` 与管理端 `GET /api/admin/v1/orders/{id}` 状态必须一致。管理端不得提供“标记为已支付”。
