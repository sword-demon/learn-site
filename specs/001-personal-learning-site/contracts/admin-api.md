# 管理端 API

前缀 `/api/admin/v1`。除登录、刷新和获取验证码外均需访问令牌。

## 登录与工作台

| 方法 | 路径 | 权限点 | 说明 |
|---|---|---|---|
| GET | `/auth/captcha` | 无 | 返回验证码图片与 `captcha_id`，TTL 120 秒 |
| POST | `/auth/login` | 无 | `{account, password, captcha_id, captcha}`，返回 access_token (15 分钟) 与 refresh_token (7 天)；账号不得为 11 位手机号；`must_change_password` 时只允许改密接口 |
| POST | `/auth/refresh` | 刷新令牌 | 轮换刷新令牌，不要求验证码；重用已失效刷新令牌则吊销整族 |
| POST | `/auth/logout` | 登录 | 作废当前令牌族 |
| POST | `/auth/password/first` | 登录 | 首次改密 |
| POST | `/staff/{id}/kick` | `org.staff` | body `{ family_id? }`；省略则作废该账户全部登录族，传入则只作废该族 |
| POST | `/learners/{id}/kick` | `learner.kick` | 同上，针对学员账户 |
| POST | `/learners/{id}/password` | `learner.reset_password` | 管理员重置学员密码；不得仅凭 `learner.view` |
| GET | `/me` | 登录 | 有效权限点列表、数据范围摘要 |
| GET | `/dashboard` | `dashboard.view` | 权限范围内待办 |

`GET /dashboard` 返回五类工作台数据：待回答问题、待处理评价、异常学习地图、未发布课程和最近
5 笔订单。每类数据同时受对应模块查看权限与课程当前部门数据范围约束；无对应模块权限时计数或
订单列表返回 `null`，不得以全站数据或 `0` 冒充。`scope` 为 `all` 或 `restricted`。

## 组织与 RBAC

| 方法 | 路径 | 权限点 |
|---|---|---|
| CRUD | `/departments` | `org.department` |
| CRUD | `/posts` | `org.post` |
| CRUD | `/roles` | `org.role` |
| GET | `/permissions` | `org.role` |
| CRUD | `/staff` | `org.staff` |
| PUT | `/staff/{id}/overrides` | `org.grant` |

部门请求中的根节点使用 `parent_id: 0`，服务端持久化为 `NULL`；部门最多五级，存在子部门、员工或
岗位时不得删除。停用部门不级联停用子部门。

岗位创建与更新支持 `role_ids: number[]`，岗位响应也返回 `role_ids`。这些角色是岗位默认角色；
员工有效角色为岗位默认角色与 `staff.role_ids` 直接角色的并集。普通员工必须属于一个启用部门，
所分配岗位必须启用且全部属于该员工当前部门，所分配角色必须启用。

员工写操作强制以下边界：后台登录名不得为 11 位手机号形态；员工不能删除或停用自己，也不能
修改自己的部门、岗位、角色、超管状态或权限覆盖；非超级管理员不能晋升超级管理员，也不能通过
角色、岗位或用户级覆盖授予自己未持有的权限；最后一名启用中的超级管理员不能被降级、停用或
删除。最后超管校验与写操作在同一事务内锁定执行。

指定部门数据范围只包含明确勾选的部门，不自动包含其子部门。

## 内容

| 方法 | 路径 | 权限点 |
|---|---|---|
| CRUD/排序/启用 | `/categories` | `category.manage`；有已发布课不能停用 |
| CRUD | `/courses` | `course.view` / `course.manage` |
| POST | `/courses/{id}/publish` 等 | `course.publish` |
| DELETE | `/courses/{id}` | `course.delete` |
| CRUD | `/courses/{id}/chapters|lessons` | `course.manage` |
| POST | `/assets` | `course.manage` |
| CRUD | `/learning-maps` | `map.view` / `map.manage` / `map.publish` |

课程、地图写操作必须把 `department_id` 限制在操作者数据范围内。

## 运营

| 方法 | 路径 | 权限点 |
|---|---|---|
| GET | `/questions` | `qa.view` |
| GET | `/questions/filter-options` | `qa.view` |
| POST | `/questions/{id}/answer` 等 | `qa.answer` |
| GET | `/reviews` | `review.view` |
| GET | `/reviews/{id}` | `review.view` |
| POST | `/reviews/{id}/replies` | `review.view` |
| POST | `/reviews/{id}/hide|restore` | `review.moderate` |
| POST | `/review-replies/{id}/hide|restore` | `review.moderate` |
| GET | `/courses/{id}/students` | `course_student.view` |
| POST | `/enrollments/{id}/reset` | `course_student.reset` |
| POST | `/entitlements/{id}/revoke` | `course_student.revoke_free`；付费来源 `403` |
| GET | `/orders` | `order.view` |
| GET | `/learners` | `learner.view` | 学员账户列表：注册状态、公开资料、学习与购买摘要 |
| GET/PATCH | `/site` | `site.manage` |
| GET | `/moderation-logs` | `audit.view` |

列表均按课程当前部门做数据范围。课程改部门后，订单/问答/评价随新部门可见。
评价详情、回复和隐藏/恢复同样按课程当前部门校验；隐藏评价或回复必须写入审核记录，隐藏父回复时
公开响应不得返回其后代。管理端评价线程可返回内部作者 ID 和审核字段。
