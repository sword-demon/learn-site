# Learn Site

个人运营的单站点课程学习产品。学习端面向访客与学员，管理端面向后台用户，用于运营课程、组织权限与内容审核。两端账户体系完全分离，所有业务能力以「课程访问权」为交付单位。

## 功能概览

### 学习端（访客 / 学员）

- **课程与目录**：三级分类、富文本简介、章节与课节（Markdown / PDF / 视频）、试看课节
- **价格与访问**：免费 / 收费、标准价与限时优惠、购买订单；免费加入 / 支付成功 / 激活码兑换三种访问权来源；付费访问权一经生效不可撤回
- **支付**：Z-Pay（兼容易支付协议）微信支付与支付宝，支持扫码与跳转两种收银方式
- **激活码兑换**：学员中心或课程详情页兑换，一码一课一次使用
- **优惠券**：领取中心与「我的优惠券」，结账选券抵扣并按满减规则计算
- **学习闭环**：学习进度、我的学习、学习地图、收藏与分享海报；学习地图只组织路径，不授予收费课程访问权
- **学习行动循环**：登录后只给出一个「下一步行动」（含做什么、为何现在做、点击去哪），完成课节或课程后自动重算，跨设备一致
- **每日签到**：每自然日提交一次富文本「每日计划」，未签到进入学习端时自动弹窗提醒，另有独立签到历史页
- **互动**：评价与树形回复、公开的课节问答、仅管理员可见的课程意见反馈、站内消息与实时推送
- **分销中心**：生成只属于自己的课程分享入口（明文短码仅在生成时返回一次，之后一律脱敏），查看推荐绑定、待结算与已结算佣金
- **合规页面**：《用户协议》与《退款说明》（虚拟课程购买后原则上不退款）

### 管理端（后台用户）

- **课程运营**：分类树、课程编辑与预览、发布核验清单（硬错误为零才允许触发外部通知）、课程激活码、课程意见反馈
- **学习运营**：课程学员名单、启动队列（识别访问权已生效但尚未开始的学员并按课程阈值人工触达）、学习事实漏斗（访问权生效 → 首次打开课节 → 产生有效进度 → 完成课程，并与试看、订单、发布触达分开）
- **内容待办**：把课节问答与私有课程反馈推进到「内容已改善 / 只完成响应 / 明确不改」的可追溯结果
- **运营收件箱**：把未发布课程、异常学习地图、待答问题、待处理反馈、支付未知状态、队列失败等汇总为按权限与数据范围裁剪的行动列表，附积压时长、影响范围与建议动作
- **订单与营销**：订单查询、优惠券活动与定向发放、轮播图管理
- **学员与通知**：学员账号、学习进度与学习记录查询、通知投递状态与失败补投、签到记录查询与删除
- **分销**：分销配置（最多三级，硬上限不可越界）、课程级分销覆盖、佣金对账与作废（作废必须填写原因）、分销审计日志
- **支付配置**：Z-Pay 商户参数落库配置、通道启停、生产环境测试白名单
- **组织与权限**：部门 / 岗位 / 角色 RBAC、数据范围、用户级授权覆盖、员工首次登录强制改密
- **站点与审计**：站点资料、自动任务与执行日志、审计日志（内容审核动作与恢复）

### 平台能力

- **双账户体系**：学员（手机号）与后台用户（后台账号）不可互登、不可合并
- **鉴权**：不透明访问令牌（15 分钟）+ 刷新令牌（7 天，轮换后旧令牌失效），支持按登录 family 踢下线
- **登录安全**：两端登录均需图形验证码（TTL 2 分钟），登录与领券等接口带 Redis 限流
- **异步与规模**：通知 fan-out、支付回调处理走 Redis 队列，踢人不扫描全库，首页读路径带缓存
- **可观测性**：中间件记录 API 请求审计并提供后台查询接口，便于定位异常调用
- **契约优先**：前后端共享 `packages/contracts` 的 Zod Schema，禁止手写 DTO 类型

## 技术栈

| 层级 | 技术 |
|------|------|
| API | PHP 8.4、Webman 2.2、ThinkORM、Phinx、`webman/redis-queue`、`webman/push` |
| 前端 | Vue 3、Vite、TypeScript、Element Plus、Pinia、Tailwind CSS（学习端与管理端同栈，各自独立构建） |
| 共享契约 | `@learn-site/contracts`（Zod Schema） |
| 数据 | MySQL 8.4、Redis 7.4 |
| 测试 | PHPUnit 11、Vitest、Playwright、PHPStan、ESLint、vue-tsc |
| 运行 | Docker Compose（推荐 OrbStack） |

## 项目结构

```text
learn-site/
├── apps/
│   ├── api/            # Webman REST API（学员端 + 管理端）
│   ├── web/            # 学习端 SPA
│   └── admin/          # 管理端 SPA
├── packages/
│   └── contracts/      # 前后端共享 Zod 契约
├── docker/             # 各服务 Dockerfile 与 Nginx 配置
├── ops/backup/         # 备份 / 迁移 / 恢复演练脚本
├── scripts/            # 镜像、迁移与运行时边界校验脚本
├── specs/              # 功能规格、API 契约与验收文档（001–017）
├── docs/               # ADR、Agent 工作流、实施计划与调研记录
├── tasks/              # 工程教训（lessons.md）与待办
├── compose.yaml        # 本地运行编排
├── compose.test.yaml   # CI 式一次性测试容器（api-test / frontend-test）
├── compose.debug.yaml  # 调试编排（额外暴露 MySQL 3306）
├── compose.restore.yaml# 隔离 project 恢复演练
├── Makefile            # 常用 Docker 命令封装
├── CONTEXT.md          # 领域词汇表
└── .env.example        # 环境变量模板
```

## 前置要求

- macOS + [OrbStack](https://orbstack.dev/)（推荐；请勿与 Docker Desktop 同时启用）
- Docker Compose v2
- 本地开发**不需要**在宿主机安装 PHP、Node、MySQL 或 Redis

## 快速开始

```bash
# 1. 复制环境变量并按需修改（尤其数据库密码、超级管理员凭据与 PAYMENT_KEY_ENC_KEY）
cp .env.example .env

# 2. 一键构建、启动、迁移、种子与健康检查
make bootstrap
```

启动成功后：

| 服务 | 默认地址 | 说明 |
|------|----------|------|
| 学习端 | http://localhost:8080 | 访客 / 学员使用，nginx 同时把 `/app/` 代理到推送服务 |
| 管理端 | http://localhost:8081 | 后台用户使用 |
| API | http://localhost:8787 | REST 接口，`/health` 健康检查 |

首次种子会创建超级管理员，账号见 `.env` 中的 `SUPER_ADMIN_ACCOUNT` / `SUPER_ADMIN_PASSWORD`。首次登录后须修改密码。

### 手动分步启动

```bash
docker compose up -d --build                        # 构建并启动全部服务
docker compose exec api php vendor/bin/phinx migrate # 执行数据库迁移
docker compose exec api php vendor/bin/phinx seed:run # 写入权限码与种子数据
curl -sf http://localhost:8787/health               # 健康检查
```

## 常用命令

所有命令走 Makefile（封装 Docker Compose）。`make help` 可查看全部目标。

```bash
make help        # 查看所有 Make 目标
make up          # 构建并启动全部服务
make down        # SIGTERM 优雅停栈并保留数据卷
make ps          # 容器状态
make logs        # 跟日志（默认 api，可用 SERVICE=web 切换）
make rebuild-api # 改 apps/api 源码后重建（另有 rebuild-web / rebuild-admin / rebuild-all）
make migrate     # 迁移前备份 + phinx migrate + 状态与健康校验
make seed        # 执行种子数据（新权限码靠它写入）
make health      # curl API /health
make sh-api      # 进入 api 容器 shell
make debug       # 额外暴露 MySQL 3306 便于本地排查
make prototype   # 打开 throwaway HTML 原型（:4173）
```

`make down` 会按 `stop_grace_period` 先发送 SIGTERM，只有进程未在期限内退出才强制终止。日常停栈不要使用 `down -v`，后者会删除数据卷。

### 测试与质量门禁

```bash
make test              # api-test + frontend-test + api-fmt
make test-api          # PHPUnit（Compose test profile）
make test-web          # 前端 typecheck + build
make test-fmt          # 后端 PHP 语法检查（php -l 遍历 app/ 与 tests/）
make lint              # 前端 ESLint（全工作区）
make typecheck         # 前端 vue-tsc（admin + web）
make phpstan           # Compose 内 PHPStan（512M 内存）
make test-e2e          # 独立 Compose project 跑管理端与学习端 Playwright
make test-perf         # 重置 E2E 夹具并跑单用户 95% / 2 秒性能冒烟
make e2e-down          # 删除独立 E2E project 及其测试卷
```

性能冒烟会重置独立的 `learn-site-e2e` 测试库，对浏览、目录、收藏、进度四类接口各采集 20 个串行样本，每类及总体都必须至少 95% 返回 HTTP 200 且低于 2 秒；跑完记得执行 `make e2e-down` 删除隔离测试卷。`apps/api/tests/perf/load-smoke.sh` 目前是并发压测占位脚本，需要宿主机安装 k6 或 wrk 后按 `specs/008-api-scale-100k/quickstart.md` 扩展。

### 运维脚本

```bash
make verify-images             # 校验镜像构建产物
make verify-migrations         # 校验迁移可重复执行
make verify-runtime-boundaries # 校验运行时边界（容器内不越权访问宿主机）
make backup BACKUP_DIR=/absolute/path            # 备份 MySQL 与 uploads
make restore BACKUP_DIR=/absolute/path           # 恢复备份
make rehearse-restore BACKUP_DIR=/absolute/path  # 隔离 Compose project 恢复演练
```

### 前端 Monorepo（pnpm）

根目录使用 pnpm workspace，共享包与两个 SPA 各自独立构建：

```bash
pnpm install           # 安装全部工作区依赖
pnpm build:contracts   # 构建共享 Zod 契约
pnpm build:web         # 构建学习端
pnpm build:admin       # 构建管理端
pnpm lint              # 全工作区 Lint
pnpm test              # 全工作区单测
```

> 验收以 Docker Compose 内运行为准；宿主机直接 `php start.php` 或 `pnpm dev` 不构成发布验收证据，也不会更新 Compose 里 nginx 提供的页面。

若 Docker 构建时 `corepack prepare pnpm` 因网络失败，可在 `.env` 设置 `NPM_REGISTRY=https://registry.npmmirror.com` 后重建前端镜像。

### 修改源码后如何生效

`compose.yaml` 把源码**打进镜像**（web/admin 为 Vite 构建后的静态文件，api 为 PHP 代码），**没有**挂载宿主机目录。因此：

| 改动路径 | 需重建的服务 | 命令 |
|----------|--------------|------|
| `apps/web/` | `web` | `make rebuild-web` |
| `apps/admin/` | `admin` | `make rebuild-admin` |
| `apps/api/` | `api` | `make rebuild-api` |
| `packages/contracts/` | `web` + `admin`（API 若引用新契约字段也需 `api`） | `make rebuild-all` |

拉取含**数据库迁移**的更新后，除重建 `api` 外还需执行 `make migrate`（可选 `make seed` 以写入新权限码，如 `ops_inbox.view`、`content_todo.manage`、`distribution.config`、`distribution.reconcile`、`distribution.audit`）。

```bash
# 示例：改了学习端首页后
make rebuild-web

# 改了 API 与契约后
make rebuild-api && make rebuild-web
```

`make restart` **只会**重启现有容器，**不会**重新构建，改代码后页面 / API 行为不会变。`compose.test.yaml` 里的 `frontend-test` / `api-test` 是一次性测试容器，不用于热更新预览。

## 文档

| 文档 | 路径 | 说明 |
|------|------|------|
| 领域词汇 | [`CONTEXT.md`](./CONTEXT.md) | 学员、后台用户、访问权等领域语言 |
| 工程教训 | [`tasks/lessons.md`](./tasks/lessons.md) | 可复用的工程约定与踩坑记录 |
| 总规格 | [`specs/001-personal-learning-site/spec.md`](./specs/001-personal-learning-site/spec.md) | 用户故事与功能需求 |
| 签到 | [`specs/005-learner-daily-checkin/spec.md`](./specs/005-learner-daily-checkin/spec.md) | 学员签到、弹窗提醒与管理端记录 |
| 轮播图 | [`specs/006-admin-banner-carousel/spec.md`](./specs/006-admin-banner-carousel/spec.md) | 管理端轮播与学习端首页展示 |
| 十万级扩展 | [`specs/008-api-scale-100k/spec.md`](./specs/008-api-scale-100k/spec.md) | 队列异步化、Token 索引、读缓存与部署调参 |
| 学员优惠券 | [`specs/009-learner-coupons/spec.md`](./specs/009-learner-coupons/spec.md) | 领取、结账抵扣与管理端活动 |
| 发布通知、激活码与意见反馈 | [`specs/010-course-notify-feedback-codes/spec.md`](./specs/010-course-notify-feedback-codes/spec.md) | 新课异步消息、课程激活码与私密意见反馈 |
| Z-Pay 支付 | [`specs/012-zpay-payment-integration/spec.md`](./specs/012-zpay-payment-integration/spec.md) | 微信 / 支付宝收单、支付配置与测试白名单 |
| 学习行动循环 | [`specs/013-learning-action-loop/spec.md`](./specs/013-learning-action-loop/spec.md) | 下一步行动、提醒节流与完成后更新 |
| 运营异常收件箱 | [`specs/014-ops-exception-inbox/spec.md`](./specs/014-ops-exception-inbox/spec.md) | 统一待办、积压年龄与影响范围 |
| 课程发布核验清单 | [`specs/015-course-publish-checklist/spec.md`](./specs/015-course-publish-checklist/spec.md) | 发布前确定性检查与影响清单 |
| 课程分销方案 | [`specs/016-course-distribution/spec.md`](./specs/016-course-distribution/spec.md) | 分享入口、推荐绑定、三级佣金与对账 |
| 学习事实漏斗 | [`specs/017-learning-fact-funnel/spec.md`](./specs/017-learning-fact-funnel/spec.md) | 访问权生效到完成课程的事实转化 |
| 其他规格 | [`specs/`](./specs/) | 每个规格目录含 spec / plan / tasks / quickstart / contracts |
| 实施计划 | [`docs/plans/`](./docs/plans/) | 课程启动队列、内容待办反馈闭环等计划书 |
| 架构决策 | [`docs/adr/`](./docs/adr/) | ADR 记录（账户分离、访问权不可撤回等） |
| Agent 协作 | [`AGENTS.md`](./AGENTS.md) | Issue 跟踪、triage 标签与后端开发约定 |

## 架构要点

```text
┌─────────────┐     ┌─────────────┐
│  apps/web   │     │ apps/admin  │
│  学习端 SPA  │     │  管理端 SPA  │
└──────┬──────┘     └──────┬──────┘
       │                   │
       └─────────┬─────────┘
                 │ REST + Zod 契约（实时推送经 nginx /app/ 代理）
           ┌─────▼─────┐
           │  apps/api  │  Webman 2.2 + Redis 队列消费者
           └─────┬─────┘
       ┌─────────┼─────────┐
  ┌────▼────┐         ┌────▼────┐
  │  MySQL  │         │  Redis  │
  │  持久化  │         │ 令牌/缓存/队列 │
  └─────────┘         └─────────┘
```

- **双账户体系**：学员与后台用户完全分离，不可互登（ADR-0001）
- **令牌鉴权**：Bearer 访问令牌 + 刷新令牌轮换，支持按 family 踢下线（ADR-0003）
- **付费闭环**：虚拟课程支付成功后开通访问权，原则上不退款（ADR-0002 与 `/refund`）
- **学习地图不授予访问权**：地图只组织学习路径（ADR-0004）
- **数据范围跟随课程部门**：后台可见范围由课程归属部门决定（ADR-0005）
- **分销合规**：层级上限硬编码为三级，佣金结算、对账与作废全程写审计
- **审计是副作用而非装饰**：管理端所有写操作经由 service 私有 `writeAudit()` 落 `audit_log`

## 环境变量

完整变量列表与注释见 [`.env.example`](./.env.example)，主要包括：

- `MYSQL_*` — 数据库凭据
- `ACCESS_TOKEN_TTL` / `REFRESH_TOKEN_TTL` / `CAPTCHA_TTL` — 令牌与验证码 TTL
- `SUPER_ADMIN_ACCOUNT` / `SUPER_ADMIN_PASSWORD` — 种子超级管理员
- `APP_ENV` / `APP_BASE_URL` / `TZ` — 运行环境与对外基址
- `WEBMAN_WORKERS` / `QUEUE_CONSUMERS` / `DB_POOL_MAX` / `REDIS_POOL_MAX` — 进程数与连接池
- `PAYMENT_DRIVER` / `FAKE_PAYMENT` / `FAKE_PAYMENT_DELAY_MS` / `PAYMENT_NOTIFY_ASYNC` / `PAYMENT_KEY_ENC_KEY` — 支付驱动选择、本地 Fake 适配器与支付配置加密密钥
- `PUSH_APP_KEY` / `PUSH_APP_SECRET` / `VITE_PUSH_APP_KEY` / `VITE_PUSH_URL` — 实时推送（`VITE_*` 在构建时打进学习端镜像）
- `TOKEN_KICK_ALLOW_SCAN_FALLBACK` — 踢人降级为全库扫描的兜底开关，默认关闭
- `HOME_CACHE_TTL_SITE` / `HOME_CACHE_TTL_CATEGORY` / `HOME_CACHE_TTL_BANNERS` / `HOME_CACHE_ENABLED` — 首页读缓存
- `WEB_PORT` / `ADMIN_PORT` / `API_PORT` — 对外端口
- `NPM_REGISTRY` — 前端镜像构建时可选 npm 镜像

Z-Pay 的商户 ID、密钥、回调地址与启用通道在管理端「支付配置」页面维护并落库（`PAYMENT_KEY_ENC_KEY` 用于加密存储商户密钥），不走环境变量；生产环境测试白名单在「支付白名单」页面维护。

## 许可证

专有软件（Proprietary）。详见各子包声明。
