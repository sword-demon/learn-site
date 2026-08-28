# Implementation Plan: 个人课程学习网站

**Branch**: `001-personal-learning-site` | **Date**: 2026-08-23 | **Spec**: [spec.md](./spec.md)

**Input**: Feature specification from `/specs/001-personal-learning-site/spec.md`

**Note**: 按宪章 v1.2.0 对齐。不写业务代码。学习端即宪章历史用语中的 PC 端 SPA。

## Summary

单站点个人课程学习产品：学习端给访客/学员，管理端给超级管理员与普通员工。覆盖三级分类、富文本简介、章节课节 (Markdown/PDF/视频)、免费/标准价/限时优惠、支付授权、进度、评价树、课节问答、收藏、分享海报、学习地图，以及完整组织树 RBAC。站内消息与真实支付按规格的本期范围边界处理：消息保留契约扩展点，首版不把完整消息中心作为发布门禁；支付首版使用 FakePaymentAdapter，真实微信回调作为后续适配器。

运行全部在 Docker Compose + OrbStack。Webman 2.2 + PHP 8.4 提供 REST API，运行时数据库访问只使用官方 `webman/think-orm`。学习端手机号 + 图形验证码登录，管理端后台账号 + 图形验证码登录。鉴权为访问令牌 + 刷新令牌 (轮换)，状态与验证码挑战存在 Redis。两个独立 Vue 3 应用。MySQL 8.4.11 持久化。支付首版由 FakePaymentAdapter 隔离，真实渠道不得绕过订单状态机。

## Technical Context

**Language/Version**: PHP 8.4；TypeScript 5.x (strict)；Node.js 22 LTS

**Primary Dependencies**: Webman 2.2 (`workerman/webman-framework` ^2.2)；`webman/think-orm` (`top-think/think-orm`)；`webman/redis` + PHP redis 扩展；Phinx 仅用于版本化迁移和种子；Composer 2；Vue 3 + Vite + Element Plus 2 + Pinia + Vue Router + Zod + Tailwind CSS + Axios；pnpm via Corepack

**Storage**: MySQL `8.4.11@sha256:b3b90af2a6552ae30c266fdb7d5dd55f3afb72404bb78d37fe8a23eb857fd3fb`；Redis `7.4.11@sha256:91d0f7e8c748ec7a4c2b4fb2c4f84edab794dd91d01e095e38dc906db9d684ab`（令牌、吊销、图形验证码）；PDF/视频 Docker 命名卷。所有 digest 必须在发布前用 `docker buildx imagetools inspect` 复核其多架构解析结果。

**Testing**: PHPUnit、PHPStan level 6+、后端格式化；Vitest、ESLint、Prettier、vue-tsc；Playwright 跑在 Compose 网络；Phinx 在干净数据库、失败迁移和恢复副本上验证

**Target Platform**: Linux 容器；macOS 本地 OrbStack；桌面 Web 为主，窄屏可用

**Project Type**: Web (1 个 API + 2 个前端应用)

**Performance Goals**: SC-003：95% 普通浏览/目录/收藏/进度操作 2 秒内有明确结果；200 名学员同时学习仍满足

**Constraints**: 禁止 `latest` 和未固定 digest 的关键基础镜像；禁止 Session/邮箱登录；学习端手机号、管理端账号；登录必须图形验证码 (TTL 2 分钟)；访问令牌 15 分钟、刷新令牌 7 天并轮换；每次受保护请求核 Redis；可踢全部或单个登录族；首版不锁定账户；密钥不进仓库；DB/Redis 默认不暴露端口；生产前端不得用 Vite dev server；业务运行时不得使用 `illuminate/database`、Eloquent、裸 PDO 或 mysqli；支付未成功不得授权；数据库迁移不得在应用启动时自动执行

**Scale/Scope**: 单站点、一名超级管理员起步；19 条用户故事、94 条功能需求（含 FR-093 结构化日志、FR-094 Fake 支付边界）

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

| 门禁 | 结论 | 说明 |
|---|---|---|
| I. 容器即运行契约 | PASS | Compose 编排 api/admin/web/mysql/redis；迁移通过 Compose 内受控的一次性命令执行，不在应用启动时执行；OrbStack；无宿主机 PHP/Node/MySQL/Redis 运行时 |
| II. 稳定兼容且可复现 | PASS | Webman 2.2 + PHP 8.4 + Node 22；PHP、Node、nginx、MySQL、Redis 均固定版本与 digest；提交 `composer.lock` 和 `pnpm-lock.yaml`，禁止 `latest` |
| III. 契约优先与端到端类型安全 | PASS | `contracts/` REST；Axios 集中附加令牌并静默刷新；Zod 双端；运行时数据库访问统一走 `think-orm` |
| IV. 数据变更安全可追溯 | PASS | Phinx 仅作迁移工具，业务 ORM 使用 `webman/think-orm`；迁移前备份、失败留痕、可逆迁移在副本验证，破坏性变更使用恢复或补偿路径 |
| V. 质量、安全与可运维性内建 | PASS | 格式化/Lint/类型/PHPStan/单测/契约测试/E2E；迁移失败和恢复演练；stdout 结构化日志；SIGTERM 优雅停机；健康检查 |
| VI. 令牌鉴权 | PASS | Bearer 访问/刷新令牌；Redis 存过期与吊销及验证码；两端登录标识分离；禁止 Session；Redis 故障失败关闭 |
| 双前端独立构建 | PASS | `apps/admin` 与 `apps/web`；共享仅 `packages/contracts` |
| Redis 用途受限 | PASS | 仅令牌、过期、吊销、图形验证码；不引入队列 |

Phase 1 复查：无未记录的宪章冲突。`config/think-orm.php` 是唯一业务数据库配置；`config/database.php` 不承载运行时 ORM 配置。迁移、备份和恢复验证属于交付门禁，不得以宿主机直接运行替代。

## Project Structure

### Documentation (this feature)

```text
specs/001-personal-learning-site/
├── plan.md
├── research.md
├── data-model.md
├── quickstart.md
├── contracts/
├── tasks.md
└── spec.md
```

### Source Code (repository root)

```text
apps/
├── api/
│   ├── app/
│   │   ├── controller/       # learner/ 与 admin/ 分离
│   │   ├── middleware/       # Bearer 令牌、RBAC、数据范围
│   │   ├── model/
│   │   ├── service/          # TokenService, CaptchaService, PermissionService
│   │   └── support/payment/
│   ├── config/
│   ├── database/migrations/
│   └── tests/
├── admin/
│   ├── src/
│   └── tests/
└── web/
    ├── src/
    └── tests/

packages/
└── contracts/

docker/
├── api/Dockerfile            # php:8.4-cli@digest + redis/GD 扩展
├── admin/Dockerfile
└── web/Dockerfile

compose.yaml                  # 唯一基础编排；迁移通过 Compose 内受控命令执行
compose.debug.yaml             # 仅显式暴露 MySQL 调试端口
compose.test.yaml              # Compose 内测试镜像与命令

.env.example
```

**Structure Decision**: 1 API + 2 独立前端 + 1 契约包。运行时业务查询和写入统一由 `support\think\Db` 或 `support\think\Model` 完成；Phinx 只读取迁移目录并维护 `phinxlog`，通过 Compose 内受控命令执行，不进入应用服务层。数据库和上传文件分别使用命名卷，并在发布前生成可校验的备份清单。

## ORM 与迁移边界

- 业务代码的查询入口只能是 `support\think\Db` 或继承 `support\think\Model` 的模型；配置只能来自 `apps/api/config/think-orm.php`。
- `apps/api/config/database.php` 保留为空或仅作兼容占位，不能再放业务连接配置；`illuminate/database`、Eloquent、裸 `PDO` 和 `mysqli` 不进入依赖或业务代码。
- Phinx 通过 Compose 内的受控命令运行 `apps/api/database/migrations/` 和种子；当前入口为 `make migrate`，底层使用 API 容器执行 `php vendor/bin/phinx`。应用容器启动、健康检查和请求处理不得隐式执行迁移。
- 迁移执行前必须检查当前版本、数据库连接、磁盘空间和备份成功状态；迁移进程非零退出时停止发布，不自动重试未知的半完成迁移。

## 迁移安全设计

- 每个结构变更使用唯一时间戳文件和明确的 `up()`/`down()`；仅加表、加可空列、加兼容索引等安全变更可在验证后回滚，涉及数据删除、收缩列或破坏兼容性的变更必须采用 expand/contract。
- `course_entitlements` 的 active 唯一性通过 MySQL 生成列 `active_marker` 表达，并与 `learner_id`、`course_id` 组成唯一索引；只有 `status='active'` 行生成非空值，撤销历史生成 `NULL`，允许再次加入创建新记录。回滚前若存在多条撤销历史则拒绝回滚，改用恢复或补偿迁移。
- 生产迁移前同时备份 MySQL 数据和 `uploads` 命名卷，记录文件名、时间、数据库迁移版本、镜像 digest 和 SHA-256。备份必须在迁移前可读、非空且可恢复验证。
- MySQL DDL 可能隐式提交，不能假设一次迁移具备全量事务回滚能力。失败后保留日志和数据库快照，通过修复迁移、恢复备份或补偿迁移处理，不直接手工改表。
- 回滚必须先在隔离恢复副本上验证；生产上的破坏性变更不执行未经验证的 `down()`，优先使用兼容窗口、恢复到备份再前向迁移，或发布明确的补偿迁移。
- 每次迁移后运行 `phinx status`、关键表/约束/索引检查、API 健康检查和最小读写验收；发布前至少完成一次 MySQL 与上传文件卷的恢复演练。

## 基础镜像锁定

| 用途 | 镜像引用 |
|---|---|
| API 构建与运行 | `php:8.4-cli@sha256:f78661b492226388a7057679cc731c3e43bc92ba66cd49a8cfe12374a56bee9f` |
| 前端构建 | `node:22.11.0-alpine@sha256:b64ced2e7cd0a4816699fe308ce6e8a08ccba463c757c00c14cd372e3d2c763e` |
| 前端运行 | `nginx:1.27.3-alpine@sha256:814a8e88df978ade80e584cc5b333144b9372a8e3c98872d07137dbf3b44d0e4` |
| MySQL | `mysql:8.4.11@sha256:b3b90af2a6552ae30c266fdb7d5dd55f3afb72404bb78d37fe8a23eb857fd3fb` |
| Redis | `redis:7.4.11@sha256:91d0f7e8c748ec7a4c2b4fb2c4f84edab794dd91d01e095e38dc906db9d684ab` |

digest 是发布输入的一部分。升级任一基础镜像必须独立记录兼容性依据、漏洞扫描结果、回滚引用和完整 Compose 验证结果。

## Complexity Tracking

无宪章违规，本表为空。
