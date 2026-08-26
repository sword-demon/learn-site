# Implementation Plan: 个人课程学习网站

**Branch**: `001-personal-learning-site` | **Date**: 2026-08-23 | **Spec**: [spec.md](./spec.md)

**Input**: Feature specification from `/specs/001-personal-learning-site/spec.md`

**Note**: 按宪章 v1.1.3 对齐。不写业务代码。学习端即宪章历史用语中的 PC 端 SPA。

## Summary

单站点个人课程学习产品：学习端给访客/学员，管理端给超级管理员与普通员工。覆盖三级分类、富文本简介、章节课节 (Markdown/PDF/视频)、免费/标准价/限时优惠、支付授权、进度、评价树、课节问答、收藏、分享海报、学习地图、站内消息，以及完整组织树 RBAC。

运行全部在 Docker Compose + OrbStack。Webman 2.2 + PHP 8.4 提供 REST API。学习端手机号 + 图形验证码登录，管理端后台账号 + 图形验证码登录。鉴权为访问令牌 + 刷新令牌 (轮换)，状态与验证码挑战存在 Redis。两个独立 Vue 3 应用。MySQL 8.4.11 持久化。支付经适配器对接微信 Native，测试用 Fake 网关。

## Technical Context

**Language/Version**: PHP 8.4；TypeScript 5.x (strict)；Node.js 22 LTS

**Primary Dependencies**: Webman 2.2 (`workerman/webman-framework` ^2.2)；`webman/redis` + PHP redis 扩展；Composer 2；Vue 3 + Vite + Element Plus 2 + Pinia + Vue Router + Zod + Tailwind CSS + Axios；pnpm via Corepack

**Storage**: MySQL `8.4.11@sha256:b3b90af2a6552ae30c266fdb7d5dd55f3afb72404bb78d37fe8a23eb857fd3fb`；Redis `7.4.11@sha256:91d0f7e8c748ec7a4c2b4fb2c4f84edab794dd91d01e095e38dc906db9d684ab`（令牌、吊销、图形验证码）；PDF/视频 Docker 命名卷

**Testing**: PHPUnit、PHPStan level 6+、后端格式化；Vitest、ESLint、Prettier、vue-tsc；Playwright 跑在 Compose 网络

**Target Platform**: Linux 容器；macOS 本地 OrbStack；桌面 Web 为主，窄屏可用

**Project Type**: Web (1 个 API + 2 个前端应用)

**Performance Goals**: SC-003：95% 普通浏览/目录/收藏/进度操作 2 秒内有明确结果；200 名学员同时学习仍满足

**Constraints**: 禁止 `latest` 镜像；禁止 Session/邮箱登录；学习端手机号、管理端账号；登录必须图形验证码 (TTL 2 分钟)；访问令牌 15 分钟、刷新令牌 7 天并轮换；每次受保护请求核 Redis；可踢全部或单个登录族；首版不锁定账户；密钥不进仓库；DB/Redis 默认不暴露端口；生产前端不得用 Vite dev server；支付未成功不得授权

**Scale/Scope**: 单站点、一名超级管理员起步；19 条用户故事、92 条功能需求 (含 FR-092 令牌/踢人)

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

| 门禁 | 结论 | 说明 |
|---|---|---|
| I. 容器即运行契约 | PASS | Compose 编排 api/admin/web/mysql/redis；OrbStack；无宿主机运行时 |
| II. 稳定兼容且可复现 | PASS | Webman 2.2 + PHP 8.4 + Node 22；MySQL/Redis 使用官方 tag 的 index digest，禁止 `latest`；提交 lock 文件 |
| III. 契约优先与端到端类型安全 | PASS | `contracts/` REST；Axios 集中附加令牌并静默刷新；Zod 双端 |
| IV. 数据变更安全可追溯 | PASS | 版本化迁移；命名卷保存 MySQL 与上传文件 |
| V. 质量、安全与可运维性内建 | PASS | 格式化/Lint/类型/PHPStan/单测/契约测试/E2E；stdout 结构化日志；SIGTERM 优雅停机；健康检查 |
| VI. 令牌鉴权 | PASS | Bearer 访问/刷新令牌；Redis 存过期与吊销及验证码；两端登录标识分离；禁止 Session |
| 双前端独立构建 | PASS | `apps/admin` 与 `apps/web`；共享仅 `packages/contracts` |
| Redis 用途受限 | PASS | 仅令牌、过期、吊销、图形验证码；不引入队列 |

Phase 1 复查：无违规，无需 Complexity Tracking。

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
├── api/Dockerfile            # PHP 8.4-cli + redis 扩展
├── admin/Dockerfile
└── web/Dockerfile

compose.yaml
compose.debug.yaml
.env.example
```

**Structure Decision**: 1 API + 2 独立前端 + 1 契约包。令牌受众、登录标识、RBAC 与信息架构按双端切开。

## Complexity Tracking

无宪章违规，本表为空。
