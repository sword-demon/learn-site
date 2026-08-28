# Data Model: 001-personal-learning-site

约定：全部表 InnoDB，`utf8mb4` / `utf8mb4_unicode_ci`；主键 `id` bigint 无符号自增；时间字段 `created_at`/`updated_at`；删除策略以规格为准（业务删除多为状态变更，物理删除仅限无学习记录且无订单的课程）。

## 账户与组织

### accounts

按账户类型保存登录标识。禁止邮箱。

| 字段 | 规则 |
|---|---|
| id | PK |
| kind | `learner` \| `staff` |
| login | `(kind, login)` 唯一。learner 必须匹配 `^1[3-9]\d{9}$`；staff 不得匹配该形态 |
| password_hash | password_hash |
| must_change_password | 后台员工首次登录为 true |
| status | `active` \| `disabled` |
| last_login_at | 可空 |

校验：`kind=learner` 不得拥有员工组织关系；`kind=staff` 不得走学员注册。禁止邮箱字段作为登录标识。

图形验证码不入库。Redis 键：

- `captcha:{id}` → 规范化答案，TTL 120 秒，校验后立即 DEL；登录成功也 DEL
- `access:{token_hash}` → `{account_id, kind, family_id, exp}`，TTL 15 分钟
- `refresh:{token_hash}` → `{account_id, kind, family_id, exp}`，TTL 7 天
- `family:{family_id}` → 吊销标记；踢单个登录族时标记该族，踢全部时标记该账户所有族

受保护请求必须命中未吊销的 `access:*`。刷新成功后删除旧 `refresh:*` 并签发新对。

### learners

| 字段 | 规则 |
|---|---|
| account_id | UK，FK accounts |
| nickname | 公开身份，可空则回退匿名 |
| avatar_url | 可空 |
| show_on_course | 是否同意在课程页公开，默认 false |

### staff_users

| 字段 | 规则 |
|---|---|
| account_id | UK，FK accounts |
| is_super_admin | bool |
| department_id | FK departments，普通员工必填且部门须启用；超管可空 |
| display_name | 必填 |

状态：所属账户 `disabled` 或部门停用时不得登录管理端（超管除外）。站点至少一名 `is_super_admin=true` 且账户启用。

### departments

| 字段 | 规则 |
|---|---|
| parent_id | 可空表示根；禁止自引用与环 |
| name, sort, status | status=`enabled`\|`disabled` |
| path | 物化路径便于“本部门及下级”查询 |

深度最多 5。有子部门或员工时不能删除。停用不级联。

### posts

岗位。`department_id` 必填。员工岗位必须属于其当前部门。

### roles

`data_scope`: `all` \| `dept` \| `dept_and_children` \| `self` \| `specified_depts`。`specified_depts` 不含下级。

### permissions

权限点代码与规格 FR-075 对齐（模块.动作）。种子数据写入，不由界面增删代码。

### 关系表

- `staff_post` (staff_user_id, post_id)
- `staff_role` (staff_user_id, role_id)
- `post_role` (post_id, role_id)
- `role_permission` (role_id, permission_id)
- `role_scope_department` (role_id, department_id) 仅 `specified_depts`
- `staff_permission_override`：`effect`=`grant`\|`deny`，deny 优先；记录操作者与时间

有效功能权限 = (岗位角色 ∪ 直接角色) ∪ grant − deny。超管跳过。

## 站点与分类

### site_profile

单行。公开名称、简介、基础展示字段。

### categories

最多三级。`parent_id`、`name`、`sort`、`status`。有子分类或课程不能删。有已发布课程不能停用。

## 课程内容

### courses

| 字段 | 规则 |
|---|---|
| category_id | 必须指向启用分类才能发布 |
| department_id | 必填，启用部门 |
| title, cover_url, teacher_display_name, summary | 教师展示名称不是角色 |
| intro_rich_text | 发布前必填，存储消毒后的 HTML |
| status | `draft` \| `published` \| `unpublished` |
| price_mode | `free` \| `paid` |
| list_price | paid 时 > 0 |
| sale_price | 可空；>=0 且 < list_price |
| sale_starts_at, sale_ends_at | 优惠窗口 |
| created_by_staff_id | 「仅本人」数据范围用，按创建者不算最后编辑者 |

状态转换：`draft → published`（分类有效、资料完整、价格合法、至少一个有效章节课节）；`published ↔ unpublished`；`draft|unpublished` 且无学习记录、无订单才可物理删除。

### chapters / lessons

章节属于课程，课节属于章节，均有 `sort`、`status`=`active`\|`archived`。课节 `content_type`=`markdown`\|`pdf`\|`video` 三选一；`body_markdown` 或 `asset_id`；`is_preview` bool。归档不删学习记录。

### assets

PDF/视频文件。`kind`、`storage_path`、`status`=`processing`\|`ready`\|`missing`\|`broken`。替换资源不改 lesson id。

## 交易与访问

### orders

| 字段 | 规则 |
|---|---|
| learner_id, course_id | |
| list_price_snapshot, sale_price_snapshot, paid_amount, currency | 不可变 |
| status | `pending` \| `succeeded` \| `failed` \| `cancelled` \| `unknown` |
| provider, provider_ref | |
| succeeded_at | 仅 succeeded |

`succeeded` 才创建或恢复 `course_entitlements`。禁止把非成功状态改成成功。课程调价不改快照。

### course_entitlements

| 字段 | 规则 |
|---|---|
| learner_id, course_id | 有效期内唯一 |
| source | `free` \| `purchase` |
| order_id | purchase 时必填 |
| status | `active` \| `revoked` |
| revoked_at, revoked_reason, revoked_by | 仅 free 可 revoked |

## 学习

### course_enrollments

课程学习记录：progress_percent、last_lesson_id、last_position、completed_at。取消免费访问不删本行。

### lesson_progresses

课节进度。Markdown/PDF：打开后才允许 `completed`。视频：有效观看 >= 90% 自动完成。已完成不得回退。

### learning_maps / map_stages / map_stage_courses / map_enrollments

地图有 department_id 与 status。阶段有序。地图内课程不重复。发布禁止空地图/空阶段/未发布课程。进度 = 已完成课程数 / 当前有效课程数。收费课不因加入地图授权。

## 互动

### reviews / review_replies

每学员每课最多一条有效评价（1–5 星 + 正文）。回复树。`visibility`=`public`\|`hidden`。隐藏后不计分。

### questions / question_messages

问题绑定 course/chapter/lesson。status=`pending`\|`answered`\|`closed`。消息为提问者或管理员的回答/追问/补充。对已授权学员公开，无私密问题。

### favorites

(learner_id, course_id) 唯一有效收藏。

### share_posters

一次生成结果：cover/title/teacher/price 快照 + 入口。失败不影响分享链接。

### moderation_logs

对象类型、对象 id、动作 hide/restore、原因、操作者、时间。

### messages

学员站内消息。`type`=`question_update`\|`progress_reset`\|`entitlement_revoked`；`unread`；关联多态 id。不发送邮件/短信。

## ORM 与迁移安全约束

- 领域模型和服务的 MySQL 读写统一经过 `support\think\Db` 或继承 `support\think\Model` 的模型，配置来源唯一为 `apps/api/config/think-orm.php`。`config/database.php` 不承载业务连接配置；`illuminate/database`、Eloquent、业务裸 PDO 和 mysqli 均禁止。
- `phinxlog` 是 Phinx 的迁移元数据表，不是业务实体。迁移文件位于 `apps/api/database/migrations/`，由 Compose 内受控的一次性迁移命令按版本串行执行；API 启动和健康检查不得自动迁移。
- 迁移前必须保存同一时间点的 MySQL dump 与 `uploads` 命名卷快照，并生成包含迁移版本、镜像 digest、文件大小和 SHA-256 的 manifest。数据库和文件卷必须作为同一恢复单元验证，避免记录恢复后出现媒体引用悬空。
- 每个新迁移应优先使用显式 `up()`/`down()`，并在干净数据库和恢复副本上各运行一次。MySQL DDL 的隐式提交意味着失败后不得假设可以整体回滚；必须保留迁移日志，根据已执行部分选择修复迁移、备份恢复或补偿迁移。
- `course_entitlements` 使用由 `status` 派生的可空生成列 `active_marker`，并与 `learner_id`、`course_id` 组成唯一索引，只限制同一学员/课程的一条 `active` 记录；`revoked` 记录生成 `NULL`，可以无限保留审计历史。回滚前若已有多条撤销历史，必须停止并改用恢复或前向补偿迁移。
- 破坏性变更（删除数据、收缩列、删除索引或破坏旧应用兼容性的变更）必须走 expand/contract，先完成备份和恢复演练，再进入收缩阶段。生产不得执行未经副本验证的 `down()`；恢复后重新前向迁移或发布补偿迁移。
- 迁移成功后的验收至少包括：`phinx status` 无待执行版本，关键表、唯一约束、外键和索引检查通过，API `/health` 同时可访问 MySQL/Redis，最小读写用例通过。迁移非零退出或验收失败即停止发布，不手工改表、不盲目重试。

## 关系要点

- 订单、问答、评价、课程学员名单的数据范围 **跟随课程当前 department_id**。
- 分类与站点资料只受功能权限，不受部门范围。
- 学习记录在课节归档、课程下架、免费访问取消后仍保留。

## 校验摘要

- 部门深度 ≤ 5，分类深度 ≤ 3。
- 优惠价窗口外回到标准价；确认购买前窗口结束必须重新确认。
- 同一学员同一课不得重复有效授权。
- 权限变更对下一次请求生效。
