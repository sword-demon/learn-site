# 管理端激活码 API

前缀 `/api/admin/v1`. 需管理端访问令牌. 权限点 `activation_code.manage`. 另受课程数据范围约束.

## 生成一批

| 方法 | 路径 |
|------|------|
| POST | `/courses/{courseId}/activation-code-batches` |

**Body**:

```json
{
  "quantity": 20,
  "expires_at": "2026-12-31T23:59:59+08:00"
}
```

- `quantity` 整数 1–1000
- `expires_at` 可 null; 有值时必须晚于当前上海时间

**成功 `201`**:

```json
{
  "id": 9,
  "course_id": 42,
  "quantity": 20,
  "expires_at": "2026-12-31T23:59:59+08:00",
  "created_at": "2026-09-02T10:00:00+08:00",
  "codes": ["AB3D-EFGH-JKMN-PQRS"]
}
```

`codes` **仅此响应返回明文**. 之后任何 GET 都只给脱敏 `display_code`.

**错误**:
- `422 COURSE_NOT_PUBLISHED` / `COURSE_NOT_PAID`
- `422 ACTIVATION_CODE_QUANTITY_INVALID` / `ACTIVATION_CODE_EXPIRES_INVALID`
- `403 FORBIDDEN` — 无权限或超出数据范围
- `404 COURSE_NOT_FOUND`

写审计 `activation_code.batch_create`.

## 列出某课激活码

| 方法 | 路径 |
|------|------|
| GET | `/courses/{courseId}/activation-codes` |

**查询**: `page`, `limit` (默认 20, 最大 100), `status?` = `unused` \| `redeemed` \| `void` \| `expired`

`expired` 为派生筛选: `status=unused AND expires_at IS NOT NULL AND expires_at <= now`.

**成功 `200`**: `{ items, total, page, limit }`

item:

```json
{
  "id": 101,
  "batch_id": 9,
  "course_id": 42,
  "display_code": "AB3D****PQRS",
  "status": "unused",
  "expires_at": "2026-12-31T23:59:59+08:00",
  "redeemed_by": null,
  "redeemed_at": null,
  "voided_at": null,
  "created_at": "2026-09-02T10:00:00+08:00"
}
```

`redeemed_by` 在已兑换时为 `{ account_id, nickname }` (脱敏规则与课程学员名单一致), 否则 null. **无明文码字段**.

## 作废

| 方法 | 路径 |
|------|------|
| POST | `/courses/{courseId}/activation-codes/{codeId}/void` |

仅 `unused` 且未过期或已过期未兑换可作废. 已兑换 → `409 ACTIVATION_CODE_NOT_VOIDABLE`.

**成功 `200`**: `{ "voided": true }`

写审计 `activation_code.void`.
