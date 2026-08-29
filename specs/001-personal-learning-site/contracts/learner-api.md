# 学习端 API

前缀 `/api/learner/v1`。

## 账号

| 方法 | 路径 | 鉴权 | 说明 |
|---|---|---|---|
| POST | `/auth/register` | 无 | `{phone, password, captcha_id, captcha_answer}`。phone 必须为 11 位大陆手机号；验证码无论对错立即作废 |
| GET | `/auth/captcha` | 无 | 返回验证码图片与 `captcha_id`，挑战写入 Redis，TTL 120 秒 |
| POST | `/auth/login` | 无 | `{phone, password, captcha_id, captcha_answer}`，返回 access_token (15 分钟) 与 refresh_token (7 天)；验证码无论对错立即作废 |
| POST | `/auth/refresh` | 刷新令牌 | 轮换刷新令牌，返回新的一对令牌；不要求验证码；重用已失效刷新令牌则吊销整族 |
| POST | `/auth/logout` | 学员 | 作废当前令牌族 |
| GET | `/me` | 学员 | 资料与公开偏好 |
| PATCH | `/me` | 学员 | 昵称、是否在课程页公开 |

## 发现与内容

| 方法 | 路径 | 鉴权 | 说明 |
|---|---|---|---|
| GET | `/home` | 可选 | 首页：启用中的分类树，不含地图精选 |
| GET | `/categories/{id}/courses` | 可选 | 分类课程列表（含子路径） |
| GET | `/courses/{id}` | 可选 | 详情、目录、价格、人数、评分；无权限课节只给摘要 |
| GET | `/courses/{id}/lessons/{lessonId}` | 视试看/授权 | Markdown HTML 或 PDF/视频播放地址 |
| GET | `/learning-maps` | 可选 | 已发布地图列表 |
| GET | `/learning-maps/{id}` | 可选 | 阶段与课程摘要 |

## 授权、进度、订单

| 方法 | 路径 | 鉴权 | 说明 |
|---|---|---|---|
| POST | `/courses/{id}/start` | 学员 | 免费课立即授权；撤销后再次调用会新建 active 授权并沿用既有进度；收费课 `409` 指引购买 |
| POST | `/courses/{id}/orders` | 学员 | 创建 pending 订单并返回扫码参数；已有有效授权则 `CONFLICT` |
| GET | `/orders` | 学员 | 仅当前学员的购买快照与支付状态 |
| GET | `/orders/{id}` | 学员 | 含支付状态；不得查看他人订单 |
| GET | `/my/learning` | 学员 | 继续学习入口；含最新授权状态、来源、撤销时间/原因和 `can_rejoin` |
| POST | `/lessons/{id}/progress` | 学员 | 位置/完成；Markdown/PDF 未打开不能完成 |
| POST | `/lessons/{id}/video-heartbeat` | 学员 | 累计有效观看 |

## 互动与其他

| 方法 | 路径 | 鉴权 | 说明 |
|---|---|---|---|
| GET | `/courses/{id}/reviews` | 无 | 仅公开评价；作者按公开偏好显示昵称或“匿名学员” |
| POST | `/courses/{id}/reviews` | 完成至少一课 | 每课一条有效评价；返回完整评价线程 |
| GET | `/reviews/{id}` | 无 | 仅公开评价及祖先链均公开的回复 |
| PATCH | `/reviews/{id}` | 评价作者 | `{rating, body}`；返回完整评价线程 |
| DELETE | `/reviews/{id}` | 评价作者 | 删除评价及其回复 |
| POST | `/reviews/{id}/replies` | 已授权学员 | `{body, parent_id?}`；最多三级 |
| GET/POST | `/lessons/{id}/questions` | 已授权 | 课节问答，全员已授权可见 |
| POST | `/questions/{id}/messages` | 提问者（未关闭） | 追问 |
| POST/DELETE | `/courses/{id}/favorite` | 学员 | 收藏 |
| POST | `/courses/{id}/share-link` | 可选 | 稳定链接 |
| POST | `/courses/{id}/posters` | 可选 | 生成海报；失败仍返回 share-link |
| POST | `/learning-maps/{id}/start` | 学员 | 不授予收费课访问权 |
| GET | `/messages` | 学员 | 站内消息 |
| POST | `/messages/{id}/read` | 学员 | 标记已读 |

未授权读非试看内容、问答全文：`403`，不泄露正文。

课程下架后不再接受新加入或购买；已有 active 授权仍可直接读取已获权非试看课节及其媒体。免费授权
被撤销后，非试看内容立即返回 `403`，但学习记录保留；课程仍为已发布免费课时可再次调用 `/start`。

公开评价与回复响应中的 `learner_id`、`author_learner_id`、`author_staff_id` 均为 `null`；
当请求携带有效学员令牌时，以 `viewer_owned` 标识当前学员本人创建的评价或回复，仍不得返回内部 ID。
评价列表另以可空 `viewer_review` 返回本人当前公开评价，确保该评价不在当前分页时仍可进入编辑流程。
通过 `author_name` 显示作者公开身份，并通过 `edited` 标记内容是否编辑过。
