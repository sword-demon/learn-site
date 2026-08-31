# Implementation Plan: 管理端轮播图管理与学习端首页展示

**Branch**: `006-admin-banner-carousel` | **Date**: 2026-08-30 | **Spec**: [spec.md](./spec.md)

**Input**: Feature specification from `/specs/006-admin-banner-carousel/spec.md`

**Note**: 全栈功能——`apps/api` 新增 `banners` 表与服务、扩展 `ImageStorage` 与 `GET /home`、`apps/admin` 轮播管理模块、`apps/web` 首页轮播组件；复用课程封面上传校验与 `CourseCoverUpload` 模式。

## Summary

为管理端提供**轮播图 CRUD**：上传图片、配置可选跳转地址、排序与启用/禁用；删除为 `deleted_at` 软删除。编辑使用 `updated_at` 乐观锁，版本冲突返回 `409 CONFLICT`。学习端**首页**在分类目录上方展示启用中的轮播（`el-carousel`，默认间隔 5000ms），数据内嵌于 `GET /api/learner/v1/home` 的 `banners` 字段。权限码 `banner.manage`；图片存 `banners/YYYY/MM/` 前缀，与 `covers/` 分离。

## Technical Context

**Language/Version**: PHP 8.4（Webman 2.2）；TypeScript 5.x strict；Vue 3.5；Node.js 22 LTS

**Primary Dependencies**:
- 后端既有：`webman/think-orm`、`ImageStorage`/`LocalImageStorage`、Phinx
- 管理端/学习端：Vue 3、Element Plus（`el-carousel`、`el-upload`）、Pinia、Axios、Zod

**Storage**: MySQL 8 — 新表 `banners`；图片文件 `runtime/uploads/banners/`；`audit_log` 审计

**Testing**: PHPUnit（API 集成）；Vitest + `@vue/test-utils`（admin/web）；`packages/contracts` Vitest

**Target Platform**: Docker Compose 编排的 `api` + `admin` + `web` 容器

**Project Type**: Monorepo — `apps/api` + `apps/admin` + `apps/web` + `packages/contracts`

**Performance Goals**:
- SC-002：首页轮播首屏 ≤3 秒（p95，合并 home 请求）
- SC-003：启用/禁用/删除后展示一致率 100%

**Constraints**:
- 宪章：think-orm 唯一 ORM；令牌隔离；Redis 本功能不使用
- 图片 JPEG/PNG/WebP、5 MiB，与课程封面一致
- 软删除 `deleted_at`；管理端默认列表不展示已删项
- 编辑请求携带 `expected_updated_at`；版本不一致返回 `409 CONFLICT`，不修改记录或图片引用
- 首版无回收站、定时上下线、点击统计

**Scale/Scope**: 4 个用户故事、16 条功能需求；预计新增/修改 ~30 源文件；1 张新表

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

| 门禁 | 结论 | 说明 |
|------|------|------|
| I. 容器即运行契约 | PASS | 迁移经 Phinx 在 api 容器执行；无宿主机依赖 |
| II. 稳定兼容且可复现 | PASS | 无新 Composer/npm 依赖 |
| III. 契约优先与端到端类型安全 | PASS | `packages/contracts/src/banner.ts`；扩展 `HomePayload` Zod |
| IV. 数据变更安全可追溯 | PASS | Phinx 迁移 + `banners` 表；`audit_log` |
| V. 质量、安全与可运维性内建 | PASS | Compose 内执行 Composer 校验/Lint、PHPUnit/PHPStan、Prettier、ESLint、TypeScript、Vitest 与生产构建；链接 scheme 校验 |
| VI. 令牌鉴权 | PASS | 管理端 `banner.manage`；学习端 home 公开读 |
| Redis 使用边界 | PASS | 不使用 Redis |
| 双前端独立构建 | PASS | admin/web 各自构建；contracts 共享类型 |

**Phase 1 复查**: 未引入并行 ORM、消息队列；`ImageStorage` 扩展为前缀参数化，非新存储栈；最终验收补充 Compose 健康、网络、持久化和优雅停机检查。

## Project Structure

### Documentation (this feature)

```text
specs/006-admin-banner-carousel/
├── plan.md
├── research.md
├── data-model.md
├── quickstart.md
├── contracts/
│   ├── admin-banners.md
│   └── learner-banners.md
├── checklists/
│   └── requirements.md
└── spec.md
```

### Source Code (repository root)

```text
apps/api/
├── database/
│   ├── migrations/
│   │   └── 20260830000002_banners.php
│   └── seeds/PermissionSeeder.php         # + banner.manage
├── app/
│   ├── model/Banner.php
│   ├── controller/
│   │   ├── admin/BannerController.php
│   │   └── admin/BannerImageController.php  # 或复用 CourseCoverController + 注入
│   ├── service/BannerService.php
│   ├── support/storage/
│   │   ├── ImageStorage.php               # 可选：工厂或前缀参数
│   │   └── LocalImageStorage.php          # + banners/ 前缀
│   ├── middleware/Authorize.php           # + /banners, /banner-images
│   └── route.php
└── tests/BannerTest.php

apps/admin/
├── src/
│   ├── api/banners.ts
│   ├── api/covers.ts                      # + '/banner-images' 端点
│   ├── layouts/AdminMenu.ts               # + 轮播图管理
│   ├── router/index.ts                    # + /banners
│   └── views/banners/BannerListView.vue
└── tests/BannerListView.test.ts

apps/web/
├── src/
│   ├── components/HomeBannerCarousel.vue
│   ├── views/home/HomeView.vue            # 顶部挂载轮播
│   └── stores/home.ts                     # banners 字段
└── tests/HomeBannerCarousel.test.ts

packages/contracts/src/
├── banner.ts
├── home.ts                                # HomePayload + banners
└── index.ts
```

**Structure Decision**: `BannerService` 集中校验、软删除与审计；`BannerController` 薄层。学习端 `HomeBannerCarousel` 纯展示组件，数据来自 `home` store。管理端单列表页 + 创建/编辑对话框，上传复用 `CourseCoverUpload`。

## Complexity Tracking

> 无宪章违规需豁免。

| Violation | Why Needed | Simpler Alternative Rejected Because |
|-----------|------------|-------------------------------------|
| — | — | — |

## Phase 0: Research Summary

见 [research.md](./research.md)。关键结论：

- 表 `banners` + `deleted_at` 软删除
- `banners/` 图片前缀；扩展 `LocalImageStorage` 与 `/api/media/{key}`
- `GET /api/learner/v1/home` 内嵌 `banners`，不单开公开列表 API
- `link_url` 可选；站内 `/...` 与站外 `http(s)://`
- `sort_order ASC`；`is_enabled` 布尔
- `banner.manage` + `audit_log`
- 学习端 `el-carousel`，无数据不占位

## Phase 1: Design Summary

见 [data-model.md](./data-model.md)、[contracts/admin-banners.md](./contracts/admin-banners.md)、[contracts/learner-banners.md](./contracts/learner-banners.md)、[quickstart.md](./quickstart.md)。

### 数据层

- `banners` — 图片 URL/key、可选 link、排序、启用、软删除时间戳

### API 面

**学习端**（公开）:
- `GET /api/learner/v1/home` — 响应增加 `banners[]`
- `GET /api/media/banners/...` — 图片读取

**管理端** (`banner.manage`):
- `POST /api/admin/v1/banner-images` — 上传
- `GET /api/admin/v1/banners`
- `GET /api/admin/v1/banners/{id}`
- `POST /api/admin/v1/banners`
- `PATCH /api/admin/v1/banners/{id}` — 必须携带 `expected_updated_at`
- `DELETE /api/admin/v1/banners/{id}` — 软删除

### 前端

- **admin**: `BannerListView` — 列表/筛选/创建编辑对话框/删除确认/启用切换
- **web**: `HomeBannerCarousel` + `HomeView` 顶部；`home` store 扩展

### 运维

- 迁移：`phinx migrate`
- 种子：`banner.manage` 写入 `PermissionSeeder`

## Implementation Notes (for tasks phase)

1. **迁移**: 建 `banners` 表与索引；`down()` 删表
2. **LocalImageStorage**: 支持 `prefix` 参数（`covers` | `banners`）；`dependence.php` 绑定 banner 用实例或工厂
3. **BannerService::validateLink()**: 空 / 站内 / 站外三分支；拒绝 `javascript:` 等
4. **BannerService::softDelete()**: `deleted_at = now()`；查询 scope `whereNull('deleted_at')`
5. **HomeService**: 调用 `BannerService::listPublic()` 填入 home 响应
6. **contracts**: `BannerPublicDTO`、`AdminBannerDTO`、`CreateBannerInput`、`UpdateBannerInput`；`HomePayload.banners`
7. **admin**: `uploadCover('/banner-images', ...)`；`CourseCoverUpload` 直接复用
8. **web**: 站内 `router.push`；站外 `window.open(..., 'noopener,noreferrer')`；`link_url` 空不加点击；自动轮播间隔 5000ms
9. **权限**: `PermissionSeeder` + `Authorize` + `AdminMenu` + 路由 `meta.permission`
10. **测试**: 软删除过滤、禁用过滤、跨权限 403、home 不含管理字段、图片上传校验

## Next Step

执行 `quickstart.md` 中的最终质量门禁、Compose 验收和人工验收；完成后将规格状态更新为已验收。
