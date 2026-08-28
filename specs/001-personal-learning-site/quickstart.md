# Quickstart 验证指南

目标：在 OrbStack 上从零启动，跑通规格里的最短闭环，并验证迁移、镜像和恢复门禁。实现细节见 `tasks.md`（由 `$speckit-tasks` 生成）。

## 前置

- macOS + OrbStack（不要开 Docker Desktop）
- Docker Compose v2、Buildx；不在宿主机安装 PHP / Node / MySQL / Redis 作为运行时
- 仓库根目录已有 `compose.yaml`、`compose.debug.yaml`、`compose.test.yaml`、`.env.example`、`composer.lock` 和 `pnpm-lock.yaml`

## 首次启动

```bash
cp .env.example .env
docker compose up -d --build
make migrate
make seed
make health
docker compose ps
```

`make migrate` 必须通过 Compose 内的 API 容器执行 `php vendor/bin/phinx migrate`，不得在应用启动脚本、健康检查或 HTTP 请求中隐式迁移。种子必须创建一名超级管理员；后台账号密码首次登录后必须修改。

学习端和管理端分别使用 `${WEB_PORT:-8080}`、`${ADMIN_PORT:-8081}`；API 健康检查使用 `${API_PORT:-8787}`。默认不暴露 MySQL 和 Redis 端口，MySQL 调试端口只能通过 `compose.debug.yaml` 显式打开。

## 镜像锁定验证

```bash
docker buildx imagetools inspect php:8.4-cli@sha256:f78661b492226388a7057679cc731c3e43bc92ba66cd49a8cfe12374a56bee9f
docker buildx imagetools inspect node:22.11.0-alpine@sha256:b64ced2e7cd0a4816699fe308ce6e8a08ccba463c757c00c14cd372e3d2c763e
docker buildx imagetools inspect nginx:1.27.3-alpine@sha256:814a8e88df978ade80e584cc5b333144b9372a8e3c98872d07137dbf3b44d0e4
docker buildx imagetools inspect mysql:8.4.11@sha256:b3b90af2a6552ae30c266fdb7d5dd55f3afb72404bb78d37fe8a23eb857fd3fb
docker buildx imagetools inspect redis:7.4.11@sha256:91d0f7e8c748ec7a4c2b4fb2c4f84edab794dd91d01e095e38dc906db9d684ab
```

每个引用都必须解析到预期的多架构 manifest；生产配置不得出现 `latest`、浮动版本或只锁 tag 的关键基础镜像。

## 迁移前备份与失败处理

首次初始化空库不需要备份。已有数据执行新迁移前，先在 Compose 容器内生成数据库和媒体卷的同点备份：

```bash
BACKUP_DIR="${TMPDIR:-/private/tmp}/learn-site-backup-$(date +%Y%m%d%H%M%S)"
BACKUP_DIR="$BACKUP_DIR" make backup
test -s "$BACKUP_DIR/manifest.json" && test -s "$BACKUP_DIR/SHA256SUMS"
```

迁移命令非零退出、`phinx status` 仍有意外待执行版本、关键约束检查失败或健康检查失败时，停止发布并保留该目录与容器日志。不得手工修改表结构，也不得对未知的半完成迁移盲目重试；根据迁移日志选择修复迁移、备份恢复或补偿迁移。

course_entitlements 的 active 唯一性由生成列 active_key 和唯一索引保证；撤销历史生成 NULL，所以撤销后再次加入可以创建新记录。若回滚前已存在多条撤销历史，迁移会主动拒绝回滚，应使用已验证的恢复路径或前向补偿迁移。

## 恢复演练

恢复演练必须使用临时 Compose project 和临时命名卷，不覆盖开发或生产数据：

```bash
BACKUP_DIR="$BACKUP_DIR" make rehearse-restore
```

恢复后的数据库、`uploads` 引用和最小读写路径必须同时可用；仅验证表数量或 dump 文件存在不算恢复成功。破坏性迁移的生产回滚必须使用已验证的恢复路径或补偿迁移，不能执行未经副本验证的 `down()`。

## 验收剧本（人工或 Playwright）

对照 `contracts/` 与 `spec.md`，至少覆盖：

1. 学员用手机号注册；登录必须先取图形验证码。后台账号不得使用 11 位手机号，学习端与后台登录入口隔离；错误、过期或重复使用验证码不得登录。
2. 超级管理员改密后，在 15 分钟内发布一门含三级分类、富文本、两章、三种课节的课程（SC-001）。
3. 访客首页是分类树，主导航能进入学习地图列表。
4. 免费课开始学习立即授权；Fake 支付完成后才授权，支付前不得通过后台或内部接口手工建权。
5. 员工只有问答权限时看不到课程维护和订单；数据范围为“本部门”时看不到子部门课程，“指定部门”不含下级。
6. 无记录草稿可删；有订单或学习记录的课程不能删；免费访问取消后不能看非试看，再加入时进度仍在，付费授权取消被拒绝。
7. 访问令牌过期后静默刷新成功；管理员踢全部登录后旧令牌立即 401，只踢一个登录族时其他族仍可用；Redis 停止后登录与受保护请求失败关闭。
8. 学员登录后打开我的订单，只看到自己的购买记录；管理员重置学员密码后，学员可用新密码登录。
9. 运行 `apps/api/tests/perf/timing.sh`：浏览、目录、收藏、进度冒烟样本至少 95% 在 2 秒内出现明确结果；200 并发作为发布后观测，不阻塞首版合并。

## 一键复跑（Compose 内）

```bash
make bootstrap
make test
```

`make test` 必须在 Compose 内完成 PHPUnit、PHPStan、PHP 格式检查、前端 ESLint、`vue-tsc`、Vitest、契约构建和两个生产构建；宿主机直接 `php` 或 `pnpm` 成功不构成验收证据。
