# Data Model: 006-admin-banner-carousel

## 概览

```text
banners (独立运营表)
  ├── image_url / image_key  → GET /api/media/banners/...
  ├── link_url (可选)
  ├── sort_order, is_enabled
  └── deleted_at (软删除)
```

- **轮播图**（`banners`）：首页单张展示单元；图片一对一存储 URL。
- **公开列表**：`is_enabled = 1 AND deleted_at IS NULL`，按 `sort_order` 排序。

---

## banners

首页轮播图记录。

| 字段 | 类型 | 规则 |
|------|------|------|
| id | BIGINT UNSIGNED PK | 自增 |
| image_url | VARCHAR(512) | 非空；上传返回的 `/api/media/banners/...` |
| image_key | VARCHAR(255) | 非空；存储 key `banners/YYYY/MM/...` 供校验与运维 |
| link_url | VARCHAR(2048) | 可空；站内路径或 http(s) URL |
| sort_order | INT | 非空，默认 0；越小越靠前 |
| is_enabled | TINYINT(1) | 非空，默认 1 |
| deleted_at | TIMESTAMP NULL | 软删除时刻；NULL = 未删除 |
| created_at | TIMESTAMP | 创建时间 |
| updated_at | TIMESTAMP | 更新时间 |

**索引**:
- `(deleted_at, is_enabled, sort_order)` — 学习端公开列表
- `(deleted_at, sort_order)` — 管理端默认列表
- `(created_at DESC)` — 管理端按创建时间辅助排序（可选）

**校验**（服务层 `BannerService`）:
- `image_url` / `image_key` 必填且 `image_key` 须匹配 `^banners/\d{4}/\d{2}/[a-f0-9]{32}\.(jpg|jpeg|png|webp)$`
- `link_url` 空则 NULL；非空须通过站内/站外 URL 校验（见 research R4）
- `sort_order` 范围建议 0–9999
- 创建/更新时 `image_url` 必须与 `image_key` 对应同一资源

**软删除**: `UPDATE deleted_at = NOW()`；不物理删除行；查询默认 `deleted_at IS NULL`。

---

## 客户端 DTO 映射

### BannerPublicDTO（学习端 / `GET /home` 内嵌）

| 字段 | 类型 | 来源 |
|------|------|------|
| id | number | `id` |
| image_url | string | `image_url` |
| link_url | string \| null | `link_url` |
| sort_order | number | `sort_order` |

不含 `is_enabled`、`deleted_at`（公开接口永不返回禁用/已删记录）。

### AdminBannerDTO（管理端列表/详情）

| 字段 | 类型 | 来源 |
|------|------|------|
| id | number | `id` |
| image_url | string | `image_url` |
| image_key | string | `image_key` |
| link_url | string \| null | `link_url` |
| sort_order | number | `sort_order` |
| is_enabled | boolean | `is_enabled` |
| created_at | string | ISO8601 |
| updated_at | string | ISO8601 |

### CreateBannerInput（管理端 POST body）

| 字段 | 类型 | 规则 |
|------|------|------|
| image_url | string | 必填 |
| image_key | string | 必填 |
| link_url | string \| null | 可选 |
| sort_order | number | 可选，默认 0 |
| is_enabled | boolean | 可选，默认 true |

### UpdateBannerInput（管理端 PATCH body）

| 字段 | 类型 | 规则 |
|------|------|------|
| image_url | string | 可选 |
| image_key | string | 可选；与 image_url 同改 |
| link_url | string \| null | 可选 |
| sort_order | number | 可选 |
| is_enabled | boolean | 可选 |
| expected_updated_at | string | 必填；最近一次 DTO 返回的 ISO8601 `updated_at`；仅用于更新请求，不落库 |

`expected_updated_at` 必填，使用最近一次管理端 DTO 返回的 `updated_at`（ISO8601）；至少一个业务字段；仅提交版本字段或缺少版本字段 → `VALIDATION_FAILED`。

---

## 状态转换

### 创建

```text
[POST /banners + banner.manage]
  --> 校验 image_key/url、link_url
  --> INSERT banners (deleted_at=NULL, is_enabled=默认1)
  --> INSERT audit_log (banner.create)
  --> 返回 AdminBannerDTO
```

### 更新

```text
[PATCH /banners/{id} + banner.manage]
  --> SELECT WHERE id AND deleted_at IS NULL
  --> 404 if missing or soft-deleted
  --> 校验 expected_updated_at 格式
  --> 校验变更字段
  --> UPDATE WHERE id AND deleted_at IS NULL AND updated_at = expected_updated_at
  --> 409 if affected rows = 0; 不修改任何字段或图片引用
  --> INSERT audit_log (banner.update)
  --> 返回 AdminBannerDTO
```

### 软删除

```text
[DELETE /banners/{id} + banner.manage]
  --> SELECT WHERE id AND deleted_at IS NULL
  --> UPDATE deleted_at = NOW()
  --> INSERT audit_log (banner.delete)
  --> 204（幂等：已删可返回 204 或 404，实现统一 204）

[学习端 GET /home]
  --> 不包含 deleted_at 非空记录
```

### 启用/禁用

```text
[PATCH is_enabled=false]
  --> 学习端 listPublic 立即排除
  --> 管理端列表仍可见，状态显示禁用
```

---

## 与现有系统关系

| 系统 | 关系 |
|------|------|
| `ImageStorage` / `LocalImageStorage` | 扩展 `banners/` 前缀存储与 resolve |
| `CourseCoverController` | 上传校验逻辑复用；新端点 `banner-images` |
| `GET /api/media/{key}` | 读取 banner 图片 |
| `HomeService` / `GET /home` | 内嵌 `banners` 数组 |
| `HomePayload` (contracts) | 扩展 Zod schema |
| `PermissionSeeder` | 新增 `banner.manage` |
| `Authorize` | `/banners`、`/banner-images` 映射 |
| `audit_log` | create / update / delete 审计 |
| `CourseCoverUpload.vue` | 管理端上传 UI 参考 |

---

## 权限与可见性

| 操作 | 权限 |
|------|------|
| 列表 / 创建 / 编辑 / 删除 / 上传 | `banner.manage` |
| 学习端读取启用轮播 | 无令牌（公开 home 接口） |
| 部门数据范围 | 不适用（全站运营内容） |
