# HTTP 契约约定

基路径：

- 学习端 API：`/api/learner/v1`
- 管理端 API：`/api/admin/v1`
- 支付回调：`/api/internal/v1/payments/wechat/notify`（验签，无登录令牌）

认证：`Authorization: Bearer <access_token>`。禁止 Session。学习端用手机号登录，管理端用后台账号登录，禁止邮箱。两端登录必须先 `GET /auth/captcha` 再提交 `captcha_id` 与 `captcha`。验证码 Redis TTL 120 秒、一次一用 (登录成功也作废)。验证码错误或密码错误均返回 `400`，验证码类用 `error.code=CAPTCHA_INVALID`，均不得泄露账户是否存在。访问令牌 TTL 15 分钟，过期返回 `401` 且 `error.code=TOKEN_EXPIRED`，客户端静默刷新（不要求验证码）；刷新令牌 TTL 7 天并轮换；刷新失败、重用检测吊销或已被踢下线返回 `401` 且 `error.code=TOKEN_REVOKED`，并标明应去 `learner_login` 或 `admin_login`。Redis 不可用时登录、刷新与受保护请求失败关闭。首版不因连续失败锁定账户。

通用响应：

```json
{ "ok": true, "data": {} }
{ "ok": false, "error": { "code": "FORBIDDEN", "message": "..." } }
```

错误码：`UNAUTHENTICATED`、`TOKEN_EXPIRED`、`TOKEN_REVOKED`、`CAPTCHA_INVALID`、`FORBIDDEN`、`NOT_FOUND`、`VALIDATION`、`CONFLICT`、`PAYMENT_UNSETTLED`、`CATEGORY_IN_USE`、`LAST_SUPER_ADMIN`。

分页：`page`、`page_size`（默认 20，最大 100），响应 `items` + `total`。

权限：管理端每个写/读在令牌核验之后做功能权限 + 数据范围。无权限与超范围一律 `403`，列表不返回超范围行。超管跳过。

Zod：`packages/contracts` 为两端生成同等 schema；后端独立再校验一遍。
