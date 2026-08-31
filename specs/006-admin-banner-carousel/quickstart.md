# Quickstart 验证指南：管理端轮播图管理与学习端首页展示

目标：在实现完成后，验证 [spec.md](./spec.md) 四条用户故事与 [contracts/](./contracts/) 行为契约。实现细节见后续 `tasks.md`（`/speckit-tasks` 生成）。

## 前置

- Compose 栈已启动：

```bash
docker compose up -d --build
docker compose exec api php vendor/bin/phinx migrate
docker compose exec api php vendor/bin/phinx seed:run
```

- 管理端 `${ADMIN_PORT:-8081}`、学习端 `${WEB_PORT:-8080}` 可访问
- 测试账号：超级管理员（或已授予 `banner.manage`）

## 自动化门禁（最终验收执行）

```bash
# API：在 Compose 测试镜像内校验依赖、Lint、PHPUnit 和 PHPStan
make test-api

# PHP 语法检查
make test-fmt

# 契约、管理端和学习端：在 Compose 测试容器内执行
make test-web
```

`make test-api` 必须通过 `composer validate --strict`、`composer lint`、PHPUnit 和 PHPStan；`make test-web` 必须通过契约 Lint/测试/构建、两个前端的 Prettier（`src` 与 `tests`）、ESLint、TypeScript、Vitest 和生产构建。

## Compose 运行时验收

从仓库根目录执行：

```bash
# 干净上下文重建应用镜像
docker compose build --no-cache

# 服务健康、依赖和端口状态
docker compose up -d
docker compose ps
docker compose exec -T api php -r '$pdo = new PDO("mysql:host=" . getenv("DB_HOST") . ";dbname=" . getenv("DB_DATABASE"), getenv("DB_USER"), getenv("DB_PASSWORD")); $redis = new Redis(); $redis->connect((string) getenv("REDIS_HOST"), (int) getenv("REDIS_PORT")); exit($pdo->query("SELECT 1")->fetchColumn() === 1 && $redis->ping() === true ? 0 : 1);'

# 迁移和接口数据已落盘后，重启 API 并确认仍可读取
docker compose exec -T api php vendor/bin/phinx status -e runtime
docker compose restart api
curl -fsS "http://localhost:${WEB_PORT:-8080}/api/learner/v1/home" | jq '.data.banners'

# 验证 API 可优雅停止，再恢复服务供后续人工验收
docker compose stop api
docker compose start api
docker compose ps api
```

通过标准：`api`、`admin`、`web`、`mysql`、`redis` 均为健康状态；API 能通过 Compose 内网络访问 MySQL 和 Redis；重启 API 后首页仍能读取轮播数据；`docker compose stop api` 成功返回且服务可再次启动。不得以宿主机直接运行 PHP 或 Node 的结果替代上述证据。

## 前端管理端门禁细节

`make test-web` 已包含以下检查：

```bash
# 管理端和学习端命令由 compose.test.yaml 中的 frontend-test 在容器内串行执行
pnpm --filter @learn-site/web exec prettier --check src tests
pnpm --filter @learn-site/admin exec prettier --check src tests
pnpm --filter @learn-site/web lint
pnpm --filter @learn-site/admin lint
pnpm --filter @learn-site/web typecheck
pnpm --filter @learn-site/admin typecheck
pnpm --filter @learn-site/web test
pnpm --filter @learn-site/admin test
pnpm --filter @learn-site/web build
pnpm --filter @learn-site/admin build
```

**预期新增测试**（实现阶段）:
- `apps/api/tests/BannerTest.php` — CRUD、软删除、公开列表过滤、链接校验、上传、权限
- `apps/api/tests/ImageStorageTest.php` — `banners/` 前缀 resolve（扩展）
- `apps/admin/tests/BannerListView.test.ts` — 列表、启用切换、删除确认、上传
- `apps/web/tests/HomeBannerCarousel.test.ts` — 展示、排序、点击站内/站外、空列表不占位
- `apps/web/tests/HomeView.test.ts` — home 响应含 banners
- `packages/contracts/src/__tests__/banner.test.ts` — Zod 契约

---

## 人工验收剧本

### 1. 管理员创建轮播图（US1）

1. 登录管理端，打开「轮播图管理」。
2. 点击新增，上传 JPEG/PNG/WebP 图片（<5 MiB），填写站内路径 `/` 或站外 `https://example.com`，排序 `0`，保存。
3. 列表出现新记录，缩略图正确。
4. 打开学习端首页（访客即可）→ 轮播区域显示该图。
5. 尝试上传超限或非法格式 → 拒绝并提示。

**通过**: 合法图可创建；非法上传不产生记录。

### 2. 启用、禁用与排序（US2）

1. 再创建 2 条轮播，`sort_order` 分别为 `10`、`20`。
2. 学习端首页顺序应为 `0 → 10 → 20`。
3. 禁用 `sort_order=10` 的记录 → 学习端仅剩 2 张。
4. 修改 `sort_order=20` 为 `5` → 刷新学习端，顺序更新。
5. 管理端筛选「仅启用」→ 列表与筛选一致。

**通过**: 禁用/启用与排序与 FR-005、FR-011 一致。

### 3. 软删除（US3）

1. 删除一条启用中的轮播 → 确认对话框 → 成功。
2. 管理端默认列表不再显示；学习端首页不再展示。
3. 数据库中该行仍存在且 `deleted_at` 非空（运维验证）。
4. 无 `banner.manage` 的管理员无法访问模块。

**通过**: 软删除不可见率 100%。

### 4. 学习端首页展示与点击（US4）

1. 访客打开首页 → 3 秒内见轮播（有数据时）。
2. 配置站内链接 `/courses/{id}` → 点击后在学习端内跳转。
3. 配置站外链接 → 点击新窗口打开。
4. `link_url` 为空 → 仅展示，点击无导航。
5. 删除全部或禁用全部 → 轮播区域不显示，分类树与课程列表正常。

**通过**: 与 [learner-banners.md](./contracts/learner-banners.md) 点击约定一致。

---

## API 冒烟（curl）

```bash
# 公开 home（应含 banners 数组）
curl -s "http://localhost:${WEB_PORT:-8080}/api/learner/v1/home" | jq '.data.banners'

# 管理端列表（需替换 TOKEN）
curl -s -H "Authorization: Bearer $ADMIN_TOKEN" \
  "http://localhost:${ADMIN_PORT:-8081}/api/admin/v1/banners" | jq '.data.items | length'
```

---

## 回滚

- 迁移 `down()` 删除 `banners` 表（仅开发环境；生产须备份后执行）
- 移除 `banner.manage` 种子权限不影响已授权角色（可手动从角色中移除）
