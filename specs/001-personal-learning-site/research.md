# Research: 001-personal-learning-site

日期：2026-08-28。所有 NEEDS CLARIFICATION 必须在本文关闭。

## 后端框架与 PHP

- **Decision**: Webman 2.x（`composer create-project workerman/webman:~2.0`），锁定 `workerman/webman-framework` 2.2.x；运行时 PHP 8.4。
- **Rationale**: 官方安装文档要求 PHP >= 8.1、Composer >= 2.0，并给出 `workerman/webman:~2.0`。Packagist 上 `workerman/webman-framework` v2.2.3（2026-07-26）声明 `php: >=8.1`。php.net 当前受支持分支为 8.2–8.5；8.4 仍处于支持窗口且比 8.5 更成熟，满足宪章“Webman 明确支持且仍在安全维护期的 PHP 稳定版”。
- **Alternatives considered**: PHP 8.5（最新但点版本更少）；PHP 8.3（安全支持已于 2025-12-31 结束）。Webman 1.x 已不是当前安装路径。

来源：
- https://www.workerman.net/doc/webman/install.html
- https://packagist.org/packages/workerman/webman-framework
- https://www.php.net/supported-versions.php

## 运行时 ORM 与迁移工具

- **Decision**: 业务运行时统一使用官方 `webman/think-orm`，查询入口为 `support\think\Db` 或继承 `support\think\Model` 的模型，连接配置唯一放在 `apps/api/config/think-orm.php`。Phinx (`robmorgan/phinx`) 只负责版本化迁移和种子，通过 Compose 内受控的一次性命令运行；它的底层数据库驱动不属于业务数据访问层。
- **Rationale**: 宪章 v1.2.0 明确禁止 Eloquent、`illuminate/database` 以及业务代码中的裸 PDO/mysqli。保留 Phinx 是为了使用成熟的迁移版本记录 (`phinxlog`)，但必须把 schema tooling 与 Webman 请求处理隔离，避免两套 ORM、连接池和事务边界并存。
- **Alternatives considered**: 将 Phinx 迁移改为 Webman ORM migration（缺少当前仓库所需的既有版本和命令兼容性）；使用裸 PDO 写业务查询（违反宪章）；继续把 `config/database.php` 当运行时配置（违反 v1.2.0，当前文件仅保留兼容空配置）。

## 数据库迁移安全

- **Decision**: 所有 schema 变更进入 `apps/api/database/migrations/`，生产只通过 Compose 内受控的一次性命令执行（当前入口为 `make migrate`）。迁移前做 MySQL dump 和 `uploads` 命名卷快照，校验非空、SHA-256 和可读性；执行前检查当前 `phinxlog` 版本、连接、磁盘空间和服务兼容窗口。迁移后检查 migration status、关键表/索引/外键、API health 和最小读写路径。
- **Rationale**: MySQL DDL 可能隐式提交，单个迁移不能假设具备完整事务回滚能力。备份和文件卷必须成对保存，才能恢复数据库中引用的 PDF/视频。失败时保留退出码和日志，不重复执行未知的半完成迁移；根据状态选择修复迁移、恢复副本后前向迁移或业务补偿迁移。
- **Rollback policy**: 新增表、可空列和兼容索引等安全变更必须提供并在隔离副本验证 `down()`；删除数据、收缩列、删除索引或破坏旧应用兼容性的变更采用 expand/contract。生产破坏性变更不执行未经验证的 `down()`，必须先恢复备份或使用补偿迁移，并完成恢复演练。
- **Alternatives considered**: 应用启动自动迁移（启动顺序和权限不可控）；生产直接手工改表（不可追溯）；把所有迁移包在一个大事务中（无法覆盖 MySQL DDL 的隐式提交行为）。

## 前端运行时

- **Decision**: Node.js 22 LTS（Jod），通过 Corepack 启用 pnpm；Vue 3 + Vite + Element Plus 2.x + Pinia + Vue Router + TypeScript 严格模式 + Zod + Tailwind CSS + Axios。两个应用独立构建。
- **Rationale**: nodejs.org 发布表显示 v22 与 v24 均为 LTS，v20 已 EOL。选型时点取较成熟的 LTS 22。Element Plus 官方定位为 Vue 3 组件库，npm 当前 2.14.x。宪章禁止生产环境用开发服务器，生产镜像多阶段构建只留静态文件。
- **Alternatives considered**: Node 24 LTS（更新，生态验证略少）；Node 20（已 EOL）。

来源：
- https://nodejs.org/en/about/previous-releases
- https://element-plus.org/
- https://www.npmjs.com/package/element-plus

## 数据库

- **Decision**: Docker Official Image `mysql:8.4.11@sha256:b3b90af2a6552ae30c266fdb7d5dd55f3afb72404bb78d37fe8a23eb857fd3fb`（Hub tag 的 index digest，2026-08-23 查询）。字符集 `utf8mb4`。默认不映射主机端口；`debug` profile 才暴露 3306。
- **Rationale**: 宪章要求关键基础镜像固定 digest，禁止 `latest`/`8`。index digest 可同时解析 amd64/arm64。
- **Alternatives considered**: 只锁 tag `8.4.11`（仍会在重建时漂到新 build）；MySQL 9.7（非 8 系列）。

来源：https://hub.docker.com/_/mysql

## 基础镜像锁定

- **Decision**: PHP、Node、nginx、MySQL、Redis 全部使用完整版本加 digest，digest 作为发布输入提交并在发布前用 `docker buildx imagetools inspect` 复核。当前设计引用：`php:8.4-cli@sha256:f78661b492226388a7057679cc731c3e43bc92ba66cd49a8cfe12374a56bee9f`、`node:22.11.0-alpine@sha256:b64ced2e7cd0a4816699fe308ce6e8a08ccba463c757c00c14cd372e3d2c763e`、`nginx:1.27.3-alpine@sha256:814a8e88df978ade80e584cc5b333144b9372a8e3c98872d07137dbf3b44d0e4`，以及上文的 MySQL/Redis digest。
- **Rationale**: 仅固定 tag 仍可能随 registry 重建漂移；digest 同时满足可复现构建和多架构镜像解析。API 镜像还必须在构建阶段安装 GD、Redis、PDO/MySQL、pcntl 和 posix，以满足验证码、数据库和优雅停机约束。
- **Alternatives considered**: `latest`、浮动小版本 tag 或只锁 tag（均不可复现）；为每个平台手工选不同 digest（破坏 macOS OrbStack 与 Linux CI 的同一运行契约）。

## Redis 镜像

- **Decision**: Docker Official Image `redis:7.4.11@sha256:91d0f7e8c748ec7a4c2b4fb2c4f84edab794dd91d01e095e38dc906db9d684ab`（Hub tag 的 index digest，2026-08-23 查询）。只允许 `api` 连接，不映射主机端口。
- **Rationale**: 宪章要求 Redis 固定版本且固定 digest。7.4.11 覆盖令牌 TTL 与验证码短键。
- **Alternatives considered**: `redis:latest` / `redis:7`（漂移）；只锁 tag 不锁 digest。

来源：https://hub.docker.com/_/redis

## 认证与令牌

- **Decision**: 不透明访问令牌 + 刷新令牌 (哈希后写入 Redis)，禁止 Session。访问令牌 15 分钟，刷新令牌 7 天，图形验证码 2 分钟，均以 Redis TTL 为准 (规格 FR-091/FR-092)。学习端用中国大陆手机号 + 密码；管理端用后台账号 + 密码；禁止邮箱。后台账号不得为 11 位手机号。两端登录必须先 `GET /auth/captcha`，再提交 `captcha_id` 与输入；验证码一次一用，登录成功后也作废。刷新令牌换发与退出不要求验证码。刷新必须轮换；旧刷新令牌再使用则吊销该登录族并要求重新登录。管理员可踢全部登录或单个登录族。首版不以连续失败锁定账户。密码使用 PHP `password_hash()`。学员自助找回不作为登录前置；首版由具备 `learner.reset_password` 的管理员重置。PHP 使用官方 `webman/redis` (需 redis 扩展)。
- **Rationale**: 宪章 v1.2.0 要求图形验证码。OWASP 将 CAPTCHA 作为防暴力破解的一层，并强调必须服务端校验、不得默认放行。RFC 9700 要求公共客户端刷新令牌轮换。登录用密码而不是短信验证码。
- **Alternatives considered**: Session Cookie（已被宪章禁止）；邮箱登录（已被宪章禁止）；纯无状态 JWT（无法立即踢人）；短信验证码登录（超出当前范围）；第三方行为验证（隐私与依赖超出首版）。

来源：
- https://www.workerman.net/doc/webman/db/redis.html
- https://www.rfc-editor.org/rfc/rfc9700.html

## 支付

- **Decision**: 后端定义支付端口（创建支付、查询、处理回调）。本期 MVP 由 FakePaymentAdapter 在固定延迟后只产生 `success`，成功后才创建访问权；订单状态模型保留 `pending`、`succeeded`、`failed`、`cancelled`、`unknown`，供后续真实适配器和契约扩展。真实微信支付 APIv3 Native（PC 扫码）不进入本期发布门禁。
- **Rationale**: 当前规格 FR-094 明确把 Fake success-only 作为本期实现边界，同时要求失败、取消、未知状态不得授权的状态机不被破坏。先隔离支付端口，可以在后续接入微信回调验签、重复通知和乱序通知时不改订单与授权边界；本期不把不可观测的真实回调行为伪装成已验收能力。
- **Alternatives considered**: 首版接入真实微信（凭据、回调和个人站点运营依赖超出当前范围）；Fake 强制四态（与当前 FR-094 success-only 边界冲突）；同时接微信和支付宝（超出首版）。

## 文件与媒体

- **Decision**: Markdown 正文存数据库；PDF 与视频存本地磁盘命名卷，经后端鉴权后输出。不转码、不做 CDN。体积上限由环境变量配置（建议 PDF 50MB、视频 500MB）。处理中/缺失按规格显示状态。
- **Rationale**: 规格把复杂转码和 DRM 划出范围。个人站点本地卷可复现、可备份。对象存储可在流量起来后替换存储驱动。
- **Alternatives considered**: 首版接 OSS（密钥、跨环境复杂度高）；把文件放进 Git（不可行）。

## 权限实现

- **Decision**: 按规格自研组织树 + 角色权限点 + 数据范围 + 用户级授予/禁用。不引入 Casbin 或 webman-admin。
- **Rationale**: 规格的数据范围（本部门及下级、指定部门不含下级、仅本人、用户级禁用优先）不是通用 Casbin RBAC 开箱模型。webman-admin 会带入另一套后台，与双 Vue 应用和宪章技术栈冲突。
- **Alternatives considered**: php-casbin / webman casbin 插件；webman/admin。

## 测试与质量门禁

- **Decision**: 后端 PHPUnit + PHPStan（level 6+）+ 代码格式；前端 Vitest + ESLint + Prettier + `vue-tsc`；关键路径 Playwright 跑在 Compose 网络内。契约测试对照 `contracts/`。
- **Rationale**: 宪章要求格式化、Lint、类型检查、静态分析、测试、生产构建、容器启动检查。
- **Alternatives considered**: 只用手动点选；Pest（可用但非必须，PHPUnit 更常见于 PHP 8.4 容器镜像）。

## 仓库结构

- **Decision**: `apps/api`（Webman）、`apps/admin`、`apps/web`、`packages/contracts`（OpenAPI 与共享 Zod 类型）、仓库根 `compose.yaml` + `docker/`。
- **Rationale**: 两个前端必须可独立构建；共享的只能是契约和类型，不能复制 API 客户端。
- **Alternatives considered**: 单仓单前端再按路由拆（违反双应用约束）；后端与前端同镜像（无法多阶段静态托管）。
