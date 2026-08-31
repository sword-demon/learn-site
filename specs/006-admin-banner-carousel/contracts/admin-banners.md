# 管理端轮播图 API

前缀 `/api/admin/v1`。需管理端访问令牌；轮播 CRUD 与上传权限点 `banner.manage`。

## 上传轮播图片

| 方法 | 路径 | 说明 |
|------|------|------|
| POST | `/banner-images` | `multipart/form-data`，字段 `file` |

**成功 `200`**:

```json
{
  "ok": true,
  "data": {
    "key": "banners/2026/08/ab12cd34ef56....webp",
    "url": "/api/media/banners/2026/08/ab12cd34ef56....webp",
    "mime_type": "image/webp",
    "size_bytes": 12345
  }
}
```

**错误**（与课程封面一致）:
- `VALIDATION_FAILED` — `BANNER_FILE_REQUIRED`、`BANNER_SIZE_INVALID`、`BANNER_MIME_INVALID`、`BANNER_EXTENSION_INVALID`
- `INTERNAL` — `BANNER_STORE_FAILED`
- `403 FORBIDDEN` — 无 `banner.manage`

---

## 查询轮播图列表

| 方法 | 路径 | 说明 |
|------|------|------|
| GET | `/banners` | 未软删除记录分页列表 |

**查询参数**:

| 参数 | 类型 | 默认 | 说明 |
|------|------|------|------|
| page | int | 1 | ≥1 |
| limit | int | 20 | 1–100 |
| is_enabled | bool | — | 可选，`true`/`false` 筛选启用状态 |

**成功 `200`**:

```json
{
  "ok": true,
  "data": {
    "items": [
      {
        "id": 1,
        "image_url": "/api/media/banners/2026/08/ab12....webp",
        "image_key": "banners/2026/08/ab12....webp",
        "link_url": "/courses/42",
        "sort_order": 0,
        "is_enabled": true,
        "created_at": "2026-08-30T10:00:00+08:00",
        "updated_at": "2026-08-30T10:00:00+08:00"
      }
    ],
    "total": 3,
    "page": 1,
    "limit": 20
  }
}
```

**排序**: `sort_order ASC, id ASC`

**范围**: 仅 `deleted_at IS NULL`

---

## 查询单条详情

| 方法 | 路径 | 说明 |
|------|------|------|
| GET | `/banners/{id}` | 单条未删除记录 |

**错误**:
- `403 FORBIDDEN`
- `404 NOT_FOUND` — 不存在或已软删除

---

## 创建轮播图

| 方法 | 路径 | 说明 |
|------|------|------|
| POST | `/banners` | 创建记录（须先上传图片） |

**Body**:

```json
{
  "image_url": "/api/media/banners/2026/08/ab12....webp",
  "image_key": "banners/2026/08/ab12....webp",
  "link_url": "https://example.com/promo",
  "sort_order": 10,
  "is_enabled": true
}
```

**成功 `201`**: 返回 `AdminBannerDTO`（见列表项结构）。

**错误**:
- `VALIDATION_FAILED` — `BANNER_IMAGE_REQUIRED`、`BANNER_LINK_INVALID`、`BANNER_SORT_INVALID`
- `403 FORBIDDEN`

---

## 更新轮播图

| 方法 | 路径 | 说明 |
|------|------|------|
| PATCH | `/banners/{id}` | 部分更新 |

**Body**（必须携带 `expected_updated_at`，且至少一项业务字段）:

```json
{
  "expected_updated_at": "2026-08-30T10:00:00+08:00",
  "link_url": "/",
  "sort_order": 0,
  "is_enabled": false
}
```

**成功 `200`**: 返回 `AdminBannerDTO`。

**错误**:
- `403 FORBIDDEN`
- `404 NOT_FOUND`
- `409 CONFLICT` — `BANNER_VERSION_CONFLICT`，`expected_updated_at` 已过期；服务端不修改记录或图片引用
- `VALIDATION_FAILED` — 缺少或格式错误的 `expected_updated_at`、仅提交版本字段或字段校验失败

---

## 软删除轮播图

| 方法 | 路径 | 说明 |
|------|------|------|
| DELETE | `/banners/{id}` | 逻辑删除 |

**成功 `204`**: 无 body。

**语义**: 设置 `deleted_at`；幂等（已删再次删除仍 `204`）。

**错误**:
- `403 FORBIDDEN`

---

## 鉴权映射

| 路径前缀 | 权限 |
|----------|------|
| `/api/admin/v1/banners` | `banner.manage` |
| `POST /api/admin/v1/banner-images` | `banner.manage` |
