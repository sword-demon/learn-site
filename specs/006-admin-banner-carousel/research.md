# Research: 006-admin-banner-carousel

## R1: 数据模型 — `banners` 表 + `deleted_at` 软删除

**Decision**: 新建表 `banners`；`deleted_at` TIMESTAMP NULL 表示软删除；管理端默认查询 `deleted_at IS NULL`；学习端仅返回 `is_enabled = 1 AND deleted_at IS NULL`。

**Rationale**:
- 规格 FR-008 明确要求逻辑删除且数据保留；`deleted_at` 是业界标准软删除字段，首版项目尚无其他表使用，本功能作为引入点。
- 与物理删除（005 签到）区分：轮播为运营内容，误删后需数据可追溯，软删除更符合规格假设。

**Alternatives considered**:

| 方案 | 弃用原因 |
|------|----------|
| `is_deleted` 布尔 | 丢失删除时刻，审计与清理策略较弱 |
| 物理删除 | 违反用户明确要求与 FR-008 |
| 独立 `banner_deletions` 归档表 | 首版过度工程 |

---

## R2: 图片上传与存储

**Decision**:
- 扩展 `LocalImageStorage`（或新增 `PrefixedImageStorage` 包装）支持 `banners/` 前缀：`banners/YYYY/MM/<random>.<ext>`，校验规则与课程封面一致（JPEG/PNG/WebP，5 MiB，`finfo` MIME + 扩展名双校验）。
- 新增 `POST /api/admin/v1/banner-images`，复用 `CourseCoverController` 上传校验逻辑，注入 `banner` 前缀的 `ImageStorage` 实现（或工厂）。
- 媒体读取沿用 `GET /api/media/{key}`；扩展 `resolve()` 正则接受 `banners/` 前缀。
- 管理端复用 `CourseCoverUpload.vue` 模式；`uploadCover` 扩展端点 `/banner-images`。

**Rationale**:
- 规格假设与课程封面策略一致；既有 `ImageStorage` 抽象与 `CourseCoverUpload` 组件可复用，避免重复校验代码。
- `banners/` 前缀与 `covers/` 分离，符合课程封面设计文档「不把运营 Banner 与课节资源生命周期混淆」的精神；比 map-covers 共用 `covers/` 路径更清晰。

**Alternatives considered**:

| 方案 | 弃用原因 |
|------|----------|
| 复用 `POST /course-covers` + `covers/` 路径 | 运维与清理时难以区分 Banner 与课程封面文件 |
| 新建独立 OSS 流程 | 首版无必要；`ImageStorage` 已预留替换点 |
| 存外链 URL 不上传 | 无法满足「上传图片」核心需求 |

---

## R3: 学习端数据获取 — 扩展 `GET /home`

**Decision**: 在既有 `GET /api/learner/v1/home` 响应中增加 `banners: BannerPublicDTO[]`；`HomeService` 调用 `BannerService::listPublic()`；不新增独立公开列表接口（首版）。

**Rationale**:
- 轮播仅用于首页；合并到 home 请求减少首屏 RTT，利于 SC-002（3 秒内展示）。
- `HomeView` 已通过 `useHomeStore().load()` 拉取 home 数据；扩展 store 即可，无需额外 composable 请求。

**Alternatives considered**:

| 方案 | 弃用原因 |
|------|----------|
| `GET /banners` 独立接口 | 首页多一次请求，首屏变慢 |
| 内嵌在 `site_intro` | 语义错误，站点资料与运营轮播职责不同 |

---

## R4: 跳转地址校验

**Decision**:
- `link_url` 可空（NULL）；非空时服务端校验：
  - **站内**：以 `/` 开头，长度 1–512，禁止 `//` 开头（防协议相对 URL）；允许学习端已有路由路径（如 `/courses/1`）。
  - **站外**：`http://` 或 `https://` 开头，长度 ≤2048；禁止 `javascript:` 等危险 scheme。
- 学习端点击：站内用 `router.push(link_url)`；站外用 `window.open(link_url, '_blank', 'noopener,noreferrer')`；空链接不绑定点击或 `pointer-events: none`。

**Rationale**:
- 规格假设跳转可选；站内/站外二分足够首版；客户端不解析复杂 deep link。
- 站外新窗口 + `noopener` 对齐常见外链安全实践；首版不做域名白名单。

**Alternatives considered**:

| 方案 | 弃用原因 |
|------|----------|
| 仅允许站内路径 | 运营活动常需外链 |
| `link_type` 枚举字段强制二选一 | 可从 URL 形态推导，减少表单字段 |

---

## R5: 展示排序

**Decision**: 字段 `sort_order INT NOT NULL DEFAULT 0`；学习端与管理端均 `ORDER BY sort_order ASC, id ASC`；数值越小越靠前（规格假设）。

**Rationale**: 简单直观；与分类、菜单等常见排序习惯一致；`id` 兜底保证稳定顺序。

---

## R6: 管理端模块与权限

**Decision**:
- 权限码 `banner.manage`（列表、创建、编辑、删除、上传）。
- 侧栏「轮播图管理」→ `/banners`；`BannerListView.vue` 列表 + 创建/编辑对话框（或抽屉）。
- `Authorize::MAP` 增加 `'/api/admin/v1/banners' => 'banner.manage'`；`banner-images` POST 映射 `banner.manage`。
- 全站可见数据，不受部门数据范围约束（规格假设）。

**Rationale**: 与 `notification.manage`、`checkin.manage` 单一权限码模式一致；轮播属站点级运营内容。

---

## R7: 审计日志

**Decision**: 写入既有 `audit_log`：
- 创建：`action=banner.create`，`target_type=banners`，`target_id`
- 更新：`action=banner.update`，`payload_json` 含变更字段摘要
- 软删除：`action=banner.delete`，`payload_json` 含 `image_url`、`link_url`

**Rationale**: 对齐 `CheckinService`、`NotificationDispatchService`；满足 FR-009。

---

## R8: 学习端轮播 UI

**Decision**:
- 组件 `HomeBannerCarousel.vue`：Element Plus `el-carousel`，`interval` 默认 5000ms，`arrow` 当 `items.length > 1`。
- 置于 `HomeView.vue` 主内容区顶部（`home-grid` 之上）；`banners.length === 0` 时不渲染组件（不占位）。
- 图片 `el-image` + `fit="cover"`；加载失败 `hide-on-click-modal` 或 `@error` 跳过该 slide。
- 样式对齐学习端首页现有 spacing；不引入新 UI 库。

**Rationale**: Element Plus 已在学习端使用；`el-carousel` 满足 FR-012 手动/自动轮播；零 Banner 不占位满足假设。

**Alternatives considered**:

| 方案 | 弃用原因 |
|------|----------|
| 自定义 CSS 动画 | 重复实现指示器与无障碍 |
| Swiper 第三方库 | 新依赖，宪章要求最小抽象 |

---

## R9: think-orm 模型

**Decision**: `App\model\Banner` 继承 `support\think\Model`；表名 `banners`；全局查询 scope `whereNull('deleted_at')` 用于管理端默认列表；`listPublic()` 额外 `where('is_enabled', 1)`。

**Rationale**: 宪章 IV 要求 think-orm 唯一 ORM；scope 避免遗漏软删除过滤。

---

## R10: 启用/禁用

**Decision**: `is_enabled TINYINT(1) NOT NULL DEFAULT 1`；PATCH 可单独切换；创建默认启用。

**Rationale**: 规格 FR-005；布尔字段比 status 枚举更简单，首版无「草稿」状态需求。
