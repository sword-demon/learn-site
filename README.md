# Learn Site

个人运营的单站点课程学习产品。学习端面向访客与学员，管理端面向后台用户，用于运营课程、组织权限与内容审核。

## 功能概览

- **课程与目录**：三级分类、富文本简介、章节与课节（Markdown / PDF / 视频）
- **价格与访问**：免费 / 收费、标准价与限时优惠、购买订单与课程访问权
- **学习体验**：学习进度、我的学习、学习地图、收藏与分享海报
- **互动**：评价与树形回复、课节问答、站内消息
- **组织与权限**：部门 / 岗位 / 角色 RBAC、数据范围、用户级授权
- **安全鉴权**：图形验证码登录、不透明访问令牌 + 刷新令牌（Redis 吊销与轮换）

## 技术栈

| 层级 | 技术 |
|------|------|
| API | PHP 8.4、Webman 2.2、ThinkORM、Phinx |
| 学习端 | Vue 3、Vite、TypeScript、Element Plus、Pinia、Tailwind CSS |
| 管理端 | Vue 3、Vite、TypeScript、Element Plus、Pinia |
| 共享契约 | `@learn-site/contracts`（Zod Schema） |
| 数据 | MySQL 8.4、Redis 7.4 |
| 运行 | Docker Compose（推荐 OrbStack） |

## 项目结构

```text
learn-site/
├── apps/
│   ├── api/          # Webman REST API（学员端 + 管理端）
│   ├── web/          # 学习端 SPA
│   └── admin/        # 管理端 SPA
├── packages/
│   └── contracts/    # 前后端共享 Zod 契约
├── docker/           # 各服务 Dockerfile 与 Nginx 配置
├── specs/            # 功能规格、API 契约与验收文档
├── docs/             # ADR 与 Agent 工作流说明
├── compose.yaml      # 本地运行编排
├── Makefile          # 常用 Docker 命令封装
└── .env.example      # 环境变量模板
```

## 前置要求

- macOS + [OrbStack](https://orbstack.dev/)（推荐；请勿与 Docker Desktop 同时启用）
- Docker Compose v2
- 本地开发**不需要**在宿主机安装 PHP、Node、MySQL 或 Redis

## 快速开始

```bash
# 1. 复制环境变量并按需修改（尤其数据库密码与超级管理员凭据）
cp .env.example .env

# 2. 一键构建、启动、迁移、种子与健康检查
make bootstrap
```

启动成功后：

| 服务 | 默认地址 | 说明 |
|------|----------|------|
| 学习端 | http://localhost:8080 | 访客 / 学员使用 |
| 管理端 | http://localhost:8081 | 后台用户使用 |
| API | http://localhost:8787 | REST 接口，`/health` 健康检查 |

首次种子会创建超级管理员，账号见 `.env` 中的 `SUPER_ADMIN_ACCOUNT` / `SUPER_ADMIN_PASSWORD`。首次登录后须修改密码。

### 手动分步启动

```bash
docker compose up -d --build
docker compose exec api php vendor/bin/phinx migrate
docker compose exec api php vendor/bin/phinx seed:run
curl -sf http://localhost:8787/health
```

## 常用命令

```bash
make help        # 查看所有 Make 目标
make ps          # 容器状态
make logs        # 查看日志（默认 api 服务）
make migrate     # 执行数据库迁移
make seed        # 执行种子数据
make sh-api      # 进入 api 容器 shell
make down        # 停止服务（保留数据卷）
```

### 测试与质量门禁

```bash
make test                              # API + 前端测试套件
make test-api                          # PHPUnit（Compose test profile）
make test-web                          # 前端 typecheck + build

# 容器内单独执行
docker compose exec api composer test
docker compose exec api composer stan
```

性能冒烟（单用户 95% 请求 2 秒内）：

```bash
bash apps/api/tests/perf/timing.sh
```

### 前端 Monorepo（pnpm）

根目录使用 pnpm workspace，共享包与两个 SPA 各自独立构建：

```bash
pnpm install
pnpm build:contracts   # 构建共享 Zod 契约
pnpm build:web         # 构建学习端
pnpm build:admin       # 构建管理端
pnpm lint              # 全工作区 Lint
pnpm test              # 全工作区单测
```

> 验收以 Docker Compose 内运行为准；宿主机直接 `php start.php` 或 `pnpm dev` 不构成发布验收证据，也不会更新 Compose 里 nginx 提供的页面。

### 修改源码后如何生效

`compose.yaml` 把源码**打进镜像**（web/admin 为 Vite 构建后的静态文件，api 为 PHP 代码），**没有**挂载宿主机目录。因此：

| 改动路径 | 需重建的服务 | 命令 |
|----------|--------------|------|
| `apps/web/` | `web` | `make rebuild-web` |
| `apps/admin/` | `admin` | `make rebuild-admin` |
| `apps/api/` | `api` | `make rebuild-api` |
| `packages/contracts/` | `web` + `admin`（API 若引用新契约字段也需 `api`） | `make rebuild-all` 或分别重建 |

```bash
# 示例：改了学习端首页后
make rebuild-web

# 改了 API 与契约后
make rebuild-api && make rebuild-web
```

`make restart` **只会**重启现有容器，**不会**重新构建，改代码后页面/API 行为不会变。

`compose.test.yaml` 里的 `frontend-test` / `api-test` 用于 CI 式一次性测试，不是用来热更新预览页面。

## 文档

| 文档 | 路径 | 说明 |
|------|------|------|
| 领域词汇 | [`CONTEXT.md`](./CONTEXT.md) | 学员、后台用户、访问权等领域语言 |
| 功能规格 | [`specs/001-personal-learning-site/spec.md`](./specs/001-personal-learning-site/spec.md) | 用户故事与功能需求 |
| 快速验收 | [`specs/001-personal-learning-site/quickstart.md`](./specs/001-personal-learning-site/quickstart.md) | 从零启动与验收剧本 |
| API 契约 | [`specs/001-personal-learning-site/contracts/`](./specs/001-personal-learning-site/contracts/) | 学员端 / 管理端 REST 约定 |
| 架构决策 | [`docs/adr/`](./docs/adr/) | ADR 记录 |
| Agent 协作 | [`AGENTS.md`](./AGENTS.md) | Issue 跟踪与 triage 标签 |

## 架构要点

```text
┌─────────────┐     ┌─────────────┐
│  apps/web   │     │ apps/admin  │
│  学习端 SPA  │     │  管理端 SPA  │
└──────┬──────┘     └──────┬──────┘
       │                   │
       └─────────┬─────────┘
                 │ REST + Zod 契约
           ┌─────▼─────┐
           │  apps/api  │  Webman 2.2
           └─────┬─────┘
       ┌─────────┼─────────┐
  ┌────▼────┐         ┌────▼────┐
  │  MySQL  │         │  Redis  │
  │  持久化  │         │ 令牌/验证码 │
  └─────────┘         └─────────┘
```

- **双账户体系**：学员（手机号）与后台用户（后台账号）完全分离，不可互登
- **令牌鉴权**：Bearer 访问令牌（15 分钟）+ 刷新令牌（7 天，轮换后旧令牌失效）
- **登录安全**：两端登录均需图形验证码（TTL 2 分钟）
- **契约优先**：`packages/contracts` 提供 Zod Schema，前后端共享类型与校验

## 环境变量

完整变量列表见 [`.env.example`](./.env.example)，主要包括：

- `MYSQL_*` — 数据库凭据
- `ACCESS_TOKEN_TTL` / `REFRESH_TOKEN_TTL` / `CAPTCHA_TTL` — 令牌与验证码 TTL
- `SUPER_ADMIN_ACCOUNT` / `SUPER_ADMIN_PASSWORD` — 种子超级管理员
- `FAKE_PAYMENT` — 本地 Fake 支付适配器（MVP 开发用）
- `WEB_PORT` / `ADMIN_PORT` / `API_PORT` — 对外端口

## 许可证

专有软件（Proprietary）。详见各子包声明。
