---
description: "Task list for admin banner carousel and learner home display"
---

# Tasks: 管理端轮播图管理与学习端首页展示

**Input**: Design documents from `/specs/006-admin-banner-carousel/`

**Prerequisites**: plan.md, spec.md, research.md, data-model.md, contracts/, quickstart.md

**Design baseline**: 宪章 v1.2.0；Webman 2.2 + PHP 8.4；think-orm 唯一 ORM；复用 `ImageStorage`/`CourseCoverUpload`；`GET /home` 内嵌 `banners`；权限码 `banner.manage`；软删除 `deleted_at`。

**Tests**: plan 与 quickstart 要求 PHPUnit + Vitest 覆盖 CRUD、软删除、公开列表过滤、上传校验、首页轮播；测试任务与各用户故事同阶段或紧随其后。

**Organization**: 按用户故事（US1–US4）分组；Setup + Foundational 阻塞所有故事。

## Format: `[ID] [P?] [Story] Description`

- **[P]**: 可并行（不同文件、无未完成依赖）
- **[Story]**: 用户故事标签（US1–US4）
- 描述中须含确切文件路径

## Path Conventions

- 后端：`apps/api/`
- 管理端：`apps/admin/src/`、`apps/admin/tests/`
- 学习端：`apps/web/src/`、`apps/web/tests/`
- 契约：`packages/contracts/src/`

---

## Phase 1: Setup (Shared Infrastructure)

**Purpose**: 管理端上传端点类型与共享上传 API 预备

- [X] T001 [P] Add `/banner-images` to `CoverUploadEndpoint` union and export `uploadBannerImage()` via `uploadCover('/banner-images', …)` in `apps/admin/src/api/covers.ts`

---

## Phase 2: Foundational (Blocking Prerequisites)

**Purpose**: 数据库、图片存储、契约、核心服务、权限——所有用户故事的前置条件

**⚠️ CRITICAL**: 完成本阶段前不得开始用户故事实现

- [X] T002 Create migration `apps/api/database/migrations/20260830000002_banners.php` for `banners` table with indexes per `specs/006-admin-banner-carousel/data-model.md`
- [X] T003 [P] Add `banner.manage` permission to `apps/api/database/seeds/PermissionSeeder.php`
- [X] T004 [P] Create `packages/contracts/src/banner.ts` with `BannerPublicDTO`, `AdminBannerDTO`, `CreateBannerInput`, `UpdateBannerInput`, and admin list envelope per `specs/006-admin-banner-carousel/contracts/`
- [X] T005 Extend `HomePayload` in `packages/contracts/src/home.ts` with `banners: z.array(BannerPublicDTO)` defaulting to `[]` in parse
- [X] T006 Export banner schemas from `packages/contracts/src/index.ts`
- [X] T007 [P] Add contract tests in `packages/contracts/src/__tests__/banner.test.ts`
- [X] T008 Extend `LocalImageStorage` in `apps/api/app/support/storage/LocalImageStorage.php` to support configurable prefix (`covers` | `banners`) and resolve `banners/` keys in `GET /api/media/{key}`
- [X] T009 [P] Register banner-prefixed `ImageStorage` binding in `apps/api/config/dependence.php` (factory or second `LocalImageStorage` instance for `banners/`)
- [X] T010 [P] Create `apps/api/app/controller/admin/BannerImageController.php` reusing `CourseCoverController` upload validation with banner storage and `BANNER_*` error codes (or inject prefix into shared upload handler)
- [X] T011 Create `apps/api/app/model/Banner.php` extending `support\think\Model` with `deleted_at` scope helpers per data-model
- [X] T012 Implement `apps/api/app/service/BannerService.php` with `validateLink()`, `create()`, `update()`, `getForAdmin()`, `listForAdmin()`, `listPublic()`, `softDelete()` — including `audit_log` (`banner.create` / `banner.update` / `banner.delete`)
- [X] T013 Register `BannerService` in `apps/api/config/dependence.php`
- [X] T014 Map `/api/admin/v1/banners` and `POST /api/admin/v1/banner-images` to `banner.manage` in `apps/api/app/middleware/Authorize.php`

**Checkpoint**: 迁移可 up；契约测试通过；`BannerService::listPublic()` 仅返回启用且未删记录；无 `banner.manage` 时管理端路由返回 403

---

## Phase 3: User Story 1 - 管理员创建并上传轮播图 (Priority: P1) 🎯 MVP

**Goal**: 管理员上传图片并创建轮播记录（跳转地址、排序）；非法上传被拒绝；默认启用

**Independent Test**: `POST /banner-images` + `POST /banners` 成功；管理端列表见新记录；非法图片上传被拒绝（见 spec US1 独立测试）

### Implementation for User Story 1

- [X] T015 [US1] Implement `store()` in `apps/api/app/controller/admin/BannerController.php` delegating to `BannerService::create()`
- [X] T016 [US1] Register `POST /api/admin/v1/banner-images` and `POST /api/admin/v1/banners` in `apps/api/app/route.php`
- [X] T017 [P] [US1] Add `createBanner()` and related Zod parsing in `apps/admin/src/api/banners.ts`
- [X] T018 [US1] Create `apps/admin/src/views/banners/BannerListView.vue` with create dialog, `CourseCoverUpload` for image, link/sort fields, and minimal table to confirm new rows
- [X] T019 [US1] Add `/banners` route with `banner.manage` guard in `apps/admin/src/router/index.ts` and「轮播图管理」menu entry in `apps/admin/src/layouts/AdminMenu.ts`
- [X] T020 [P] [US1] Write `apps/api/tests/BannerTest.php` covering successful create, invalid link rejection, upload MIME/size validation, and `banner.manage` permission gate

**Checkpoint**: US1 验收场景 1–5 可通过 API 与基础管理端 UI 验证；学习端展示在 US4 完成

---

## Phase 4: User Story 2 - 管理员启用、禁用与调整轮播图 (Priority: P1)

**Goal**: 管理端列表筛选启用状态；编辑图片/链接/排序；启用/禁用切换后学习端可见性变化（US4 验收）

**Independent Test**: 创建 3 条不同 `sort_order`；禁用 1 条后 `listPublic()` 少 1 条；PATCH 排序后顺序更新（见 spec US2 独立测试）

### Implementation for User Story 2

- [X] T021 [US2] Implement `index()`, `show()`, and `patch()` in `apps/api/app/controller/admin/BannerController.php`
- [X] T022 [US2] Register `GET /api/admin/v1/banners`, `GET /api/admin/v1/banners/{id}`, and `PATCH /api/admin/v1/banners/{id}` in `apps/api/app/route.php`
- [X] T023 [P] [US2] Add `listBanners()`, `getBanner()`, and `updateBanner()` in `apps/admin/src/api/banners.ts`
- [X] T024 [US2] Extend `BannerListView.vue` with `is_enabled` filter, edit dialog, inline enable/disable toggle, and sort-order editing in `apps/admin/src/views/banners/BannerListView.vue`
- [X] T025 [P] [US2] Extend `apps/api/tests/BannerTest.php` for admin list filters, PATCH update, and `listPublic()` excluding disabled banners

**Checkpoint**: US2 验收场景 1–5 通过 API；与 US4 联调后验证学习端展示顺序与禁用

---

## Phase 5: User Story 3 - 管理员软删除轮播图 (Priority: P1)

**Goal**: 逻辑删除；管理端默认列表与学习端均不可见；删除审计；幂等删除

**Independent Test**: `DELETE /banners/{id}` 后管理端列表与 `listPublic()` 均不含该记录；DB 行 `deleted_at` 非空（见 spec US3 独立测试）

### Implementation for User Story 3

- [X] T026 [US3] Implement `destroy()` soft delete in `apps/api/app/controller/admin/BannerController.php` delegating to `BannerService::softDelete()`
- [X] T027 [US3] Register `DELETE /api/admin/v1/banners/{id}` in `apps/api/app/route.php`
- [X] T028 [US3] Add delete confirmation with `ElMessageBox.confirm` in `apps/admin/src/views/banners/BannerListView.vue`
- [X] T029 [P] [US3] Add `deleteBanner()` in `apps/admin/src/api/banners.ts`
- [X] T030 [P] [US3] Extend `apps/api/tests/BannerTest.php` for soft-delete visibility, idempotent delete, and `banner.delete` audit log

**Checkpoint**: US3 验收场景 1–4 通过；SC-005 软删除不可见率可验证

---

## Phase 6: User Story 4 - 访客与学员在学习端首页查看轮播图 (Priority: P1)

**Goal**: 首页顶部 `el-carousel`；`GET /home` 返回 `banners`；站内/站外点击；无数据不占位

**Independent Test**: 访客打开首页见启用轮播且顺序正确；点击站内/站外链接行为符合契约；无启用记录时不渲染轮播区域（见 spec US4 独立测试）

### Implementation for User Story 4

- [X] T031 [US4] Extend `HomeService` and `apps/api/app/controller/learner/HomeController.php` to include `BannerService::listPublic()` as `banners` in home response
- [X] T032 [P] [US4] Extend `useHomeStore` in `apps/web/src/stores/home.ts` to hold `banners` from `HomePayload`
- [X] T033 [US4] Create `HomeBannerCarousel.vue` with `el-carousel`, internal `router.push`, external `window.open(..., 'noopener,noreferrer')`, and no-click when `link_url` is null in `apps/web/src/components/HomeBannerCarousel.vue`
- [X] T034 [US4] Mount `HomeBannerCarousel` above `home-grid` in `apps/web/src/views/home/HomeView.vue` (render only when `banners.length > 0`)
- [X] T035 [P] [US4] Write `apps/web/tests/HomeBannerCarousel.test.ts` for sort order, click behaviors, and empty-state no-render
- [X] T036 [P] [US4] Extend `apps/web/tests/HomeView.test.ts` and `apps/web/tests/HomeStore.test.ts` for `banners` in home payload
- [X] T037 [P] [US4] Extend `apps/api/tests/BannerTest.php` for `GET /home` banners field (enabled-only, sorted, no admin fields)

**Checkpoint**: US4 验收场景 1–6 通过；SC-002 首页轮播 3 秒内展示可人工验证

---

## Phase 7: Polish & Cross-Cutting Concerns

**Purpose**: 权限文案、安全泄漏测试、迁移门禁、管理端 UI 测试与端到端冒烟

- [X] T038 [P] Add `banner.manage` label in `apps/api/app/controller/admin/RoleController.php` permission map
- [X] T039 [P] Extend `apps/api/tests/AuthorizeLeakTest.php` for admin banner routes and learner home cross-token isolation
- [X] T040 [P] Extend `apps/api/tests/MigrationReleaseGateTest.php` for `20260830000002_banners.php`
- [X] T041 [P] Extend `apps/api/tests/ImageStorageTest.php` for `banners/` prefix store and resolve
- [X] T042 [P] Write `apps/admin/tests/BannerListView.test.ts` for list rendering, create/edit dialogs, enable toggle, and delete confirmation
- [X] T043 Run validation scenarios in `specs/006-admin-banner-carousel/quickstart.md` and fix any gaps found

---

## Dependencies & Execution Order

### Phase Dependencies

- **Setup (Phase 1)**: 无依赖，可立即开始
- **Foundational (Phase 2)**: 依赖 Setup（T001 可选并行）— **阻塞所有用户故事**
- **US1 (Phase 3)**: 依赖 Foundational — **MVP 起点**
- **US2 (Phase 4)**: 依赖 Foundational + US1（创建流程与列表页基础）
- **US3 (Phase 5)**: 依赖 Foundational + US2（列表页删除 UI）
- **US4 (Phase 6)**: 依赖 Foundational；可与 US2/US3 并行开发 API/UI，完整验收需 US1 种子数据
- **Polish (Phase 7)**: 依赖所有目标用户故事完成

### User Story Dependencies

```text
Setup → Foundational
              ├─→ US1 (创建+上传) ──→ US2 (列表/编辑/启用)
              │                              └─→ US3 (软删除)
              └─→ US4 (学习端首页，可与 US2/US3 并行)
```

| 故事 | 依赖 | 可独立测试 |
|------|------|------------|
| US1 | Foundational | 是 — API 上传+创建 + 管理端最小列表 |
| US2 | Foundational + US1 | 是 — PATCH/筛选；学习端可见性需 US4 |
| US3 | Foundational + US2 | 是 — DELETE + `listPublic()` 过滤 |
| US4 | Foundational | 是 — 空 `banners` 不占位；有数据验收需 US1 |

### Within Each User Story

- `BannerService` 先于 Controller（Foundational 已完成）
- Controller 先于路由注册
- API 客户端与后端路由同步
- 管理端/学习端 UI 在对应 API 就绪后接入
- 测试在实现后或紧随其后

### Parallel Opportunities

- Phase 1: T001 单任务
- Phase 2: T003–T007、T009–T010 可与 T002 之后并行；T012 依赖 T011；T013–T014 在 T012 之后
- US1: T017、T020 与 T018 部分并行
- US2: T023、T025 与 T024 部分并行
- US3: T029、T030 与 T028 并行
- US4: T032、T035、T036、T037 并行
- Polish: T038–T042 全部 [P]

---

## Parallel Example: User Story 1

```bash
# T015–T016 后端就绪后并行:
Task T017: apps/admin/src/api/banners.ts
Task T020: apps/api/tests/BannerTest.php

# 然后串联:
Task T018: apps/admin/src/views/banners/BannerListView.vue
Task T019: router + AdminMenu
```

---

## Parallel Example: Foundational

```bash
# T002 迁移落地后并行:
Task T003: PermissionSeeder.php
Task T004: banner.ts
Task T007: banner.test.ts
Task T009: dependence.php banner ImageStorage
Task T010: BannerImageController.php

# 然后:
Task T011: Banner.php
Task T012: BannerService.php
Task T013–T014: dependence + Authorize
```

---

## Implementation Strategy

### MVP First (User Story 1)

1. Complete Phase 1: Setup
2. Complete Phase 2: Foundational
3. Complete Phase 3: User Story 1（上传 + 创建 + 管理端入口）
4. **STOP and VALIDATE**: `BannerTest` 创建与上传用例通过；curl 可完成上传与创建
5. 加 US4 学习端展示 → 完整「创建即可见」闭环
6. 再加 US2/US3 运营能力

### Incremental Delivery

1. Setup + Foundational → 数据与服务基础就绪
2. US1 创建轮播 → 管理端可上图
3. US4 学习端首页 → 访客可见（核心价值）
4. US2 启用/排序 → 运营调序与上下线
5. US3 软删除 → 内容治理
6. Polish → 安全、迁移门禁、quickstart 全量验证

### Parallel Team Strategy

| 开发者 | 任务流 |
|--------|--------|
| A | Foundational → US1 → US2 → US3 |
| B | Foundational 完成后 → US4 学习端轮播 |
| C | Foundational 完成后 → 契约测试 + BannerTest 扩展 |

---

## Notes

- 图片存 `banners/YYYY/MM/`；与 `covers/` 分离；媒体路由 `GET /api/media/banners/...`
- `link_url` 可选；站内 `/path`、站外 `http(s)://`；空则学习端不导航
- 软删除 `deleted_at`；首版无回收站 UI
- `sort_order` 越小越靠前；`listPublic()` 与 admin 列表排序一致
- `[P]` 任务避免同文件冲突；`BannerListView.vue` 在 US1/US2/US3 顺序修改
- 每个 Checkpoint 可独立提交；全量门禁见 `specs/006-admin-banner-carousel/quickstart.md`
