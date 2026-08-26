# 支付契约

## 创建支付

`POST /api/learner/v1/courses/{id}/orders`

- 课程须为 published + paid。
- 优惠窗口在确认瞬间校验；窗口已结束则 `VALIDATION`，前端重新拉价格。
- 返回 `{order_id, status:"pending", payment:{type:"wechat_native", code_url}}`。
- 测试环境 Fake 适配器可接受 `X-Fake-Payment-Result: succeeded|failed|cancelled|unknown`。

## 微信回调

`POST /api/internal/v1/payments/wechat/notify`

- 验签失败：记录 unknown，不授权，HTTP 仍按微信要求应答以免风暴（实现时按官方 APIv3 应答规范）。
- 成功：订单 `succeeded`，写入不可变快照，创建 `purchase` 授权。
- 明确失败/关闭：`failed` 或 `cancelled`，不授权。
- 无法判定：`unknown`，不授权，学员可见重试入口。

失败、取消、超时、未知四种状态必须用稳定 `status` 字段返回，学习端展示对应说明并提供重试入口，不得显示为已开通。

## 查询

`GET /api/learner/v1/orders/{id}` 与管理端 `GET /api/admin/v1/orders/{id}` 状态必须一致。管理端不得提供“标记为已支付”。
