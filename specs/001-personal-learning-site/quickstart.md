# Quickstart 验证指南

目标：在 OrbStack 上从零启动，跑通规格里的最短闭环。实现细节见 `tasks.md`（由 `$speckit-tasks` 生成）。

## 前置

- macOS + OrbStack（不要开 Docker Desktop）
- 仓库根目录已有锁定版本的 `compose.yaml`、`.env.example`
- 不在宿主机安装 PHP / Node / MySQL / Redis 作为运行时

## 启动

```bash
cp .env.example .env
docker compose up -d --build
docker compose exec api php vendor/bin/phinx migrate
docker compose exec api php vendor/bin/phinx seed:run
```

种子必须创建：

- 一名超级管理员（后台账号见 `.env.example` 注释，密码首次登录要改）
- 空部门树可随后在管理端创建

健康检查：

```bash
docker compose ps
curl -sf http://localhost:8787/health
```

学习端与管理端静态站点按 compose 端口打开（文档化在 `.env.example`，例如学习端 8080、管理端 8081）。

## 验收剧本（人工或 Playwright）

对照 `contracts/` 与 `spec.md`，至少覆盖：

1. 学员用手机号注册；登录必须先取图形验证码。用该 11 位号创建后台账号必须失败；学习端用后台账号登录必须失败；错误或重复使用验证码不得登录。
2. 超级管理员改密后，15 分钟内发布一门含三级分类、富文本、两章、三种课节的课（SC-001）。
3. 访客首页是分类树，主导航能进学习地图列表。
4. 免费课开始学习立即授权；收费课 Fake 适配器分别打成功/失败/取消/未知，仅成功能看非试看。
5. 员工角色只有问答权限时看不到课程维护和订单。
6. 数据范围为“本部门”时看不到子部门课程；“指定部门”不含下级。
7. 无记录草稿可删；有订单的课不能删。
8. 取消免费访问后不能看非试看，再加入进度仍在；付费授权取消被拒绝。
9. 问答回复、进度重置、取消免费访问出现在学员消息列表（`/api/learner/v1/me/notifications`）。
10. 访问令牌过期后静默刷新成功；管理员踢全部登录后该账户旧令牌立即 401，只踢一个登录族时其他族仍可用。Redis 停掉后登录与受保护请求失败，不得放行。验证码超过 2 分钟或登录成功后再用同一验证码必须失败。
11. 学员登录后打开我的订单，只看到自己的购买记录。
12. 管理员重置学员密码后，学员可用新密码登录。
13. 运行 `apps/api/tests/perf/timing.sh`：浏览/目录/收藏/进度冒烟样本 95% 在 2 秒内。200 并发作为发布后观测，不阻塞首版合并。

## 一键复跑（Compose 内）

```bash
make bootstrap           # .env + 构建启动 + 迁移 + 种子 + 健康检查
bash apps/api/tests/perf/timing.sh     # SC-003 单用户冒烟
docker compose -f compose.yaml -f compose.test.yaml --profile test run --rm api-test
docker compose -f compose.yaml -f compose.test.yaml --profile test run --rm frontend-test
```

## 质量门禁（实现后必须可跑）

```bash
docker compose exec api composer test
docker compose exec api composer stan
docker compose exec admin pnpm lint && pnpm typecheck && pnpm test && pnpm build
docker compose exec web pnpm lint && pnpm typecheck && pnpm test && pnpm build
```

任一失败即未完成。宿主机直接 `php start.php` 成功不构成验收证据。
