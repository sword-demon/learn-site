# 学习端轮播图 API

前缀 `/api/learner/v1`。轮播数据通过既有首页接口返回，无需登录。

## 首页数据（扩展）

| 方法 | 路径 | 说明 |
|------|------|------|
| GET | `/home` | 在既有响应中增加 `banners` 字段 |

**成功 `200`**（节选）:

```json
{
  "ok": true,
  "data": {
    "categories": [ ... ],
    "site_intro": { ... },
    "recent_courses": [ ... ],
    "banners": [
      {
        "id": 1,
        "image_url": "/api/media/banners/2026/08/ab12....webp",
        "link_url": "/courses/42",
        "sort_order": 0
      },
      {
        "id": 2,
        "image_url": "/api/media/banners/2026/08/cd34....png",
        "link_url": null,
        "sort_order": 10
      }
    ]
  }
}
```

**`banners` 规则**:
- 仅包含 `is_enabled = true` 且 `deleted_at IS NULL` 的记录
- 按 `sort_order ASC, id ASC` 排序
- 无可用记录时返回空数组 `[]`，不返回 `null`
- 永不包含 `image_key`、`is_enabled`、`deleted_at` 等管理字段

---

## 图片读取（公开）

| 方法 | 路径 | 说明 |
|------|------|------|
| GET | `/api/media/banners/{year}/{month}/{file}` | 与课程封面共用媒体路由 |

**成功**: 图片二进制，`Content-Type` 为实际 MIME。

**错误**:
- `404 NOT_FOUND` — `BANNER_NOT_FOUND` 或 `COVER_NOT_FOUND`（实现可统一）

---

## 客户端行为约定

| `link_url` | 点击行为 |
|------------|----------|
| `null` 或空 | 不导航；轮播图不可点击或无指针样式 |
| 以 `/` 开头 | `router.push(link_url)` 站内导航 |
| `http://` / `https://` | `window.open(url, '_blank', 'noopener,noreferrer')` |

无效 scheme（如 `javascript:`）不得由管理端保存成功，学习端不应收到。

---

## 鉴权

- `GET /home`：公开，与既有首页一致
- `GET /api/media/banners/...`：公开读取
- 学员令牌与管理端令牌均不能调用管理端 `/banners` 写接口（宪章令牌隔离）
