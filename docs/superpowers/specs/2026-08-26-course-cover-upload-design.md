# 课程封面上传设计

## 目标

将管理端课程编辑页的“封面 URL”文本输入替换为图片上传组件。上传接口先将图片保存到本地持久化存储，并通过稳定的图片读取 URL 返回给前端；存储实现通过接口隔离，为后续接入 OSS 保留替换点。

## 已确认约束

- 允许格式：JPEG、PNG、WebP。
- 单张最大大小：5 MiB。
- 服务端同时校验实际 MIME 类型和文件扩展名。
- 非法、空文件或超限文件不得落盘。
- 不使用用户原始文件名作为存储文件名。
- 课程仍保存现有 `courses.cover_url` 字段；上传结果的 `url` 直接写入该字段。
- 不扩展课节 `assets` 表的 `kind` 枚举，避免混淆课程封面与课节资源生命周期。

## 架构

### 管理端接口

新增 `POST /api/admin/v1/course-covers`，由 `AdminAuth` 和 `Authorize` 保护，权限为 `course.manage`。请求使用 `multipart/form-data`，字段名为 `file`。成功响应使用标准信封：

```json
{
  "ok": true,
  "data": {
    "key": "covers/2026/08/ab12...ef.webp",
    "url": "/api/media/covers/2026/08/ab12...ef.webp",
    "mime_type": "image/webp",
    "size_bytes": 12345
  }
}
```

错误使用现有 `VALIDATION_FAILED`（`COVER_FILE_REQUIRED`、`COVER_SIZE_INVALID`、`COVER_MIME_INVALID`、`COVER_EXTENSION_INVALID`）或 `INTERNAL`（`COVER_STORE_FAILED`）。

### 图片读取

新增 `GET /api/media/{key}`。该路由只接受服务端生成的 `covers/YYYY/MM/<random>.<ext>` 形式 key，拒绝路径穿越、任意扩展名和不存在文件，成功时按存储记录的 MIME 返回文件内容。读取接口不暴露本地绝对路径。

### 存储抽象

在 `apps/api/app/support/storage` 定义 `ImageStorage` 接口，最小接口为：

```php
interface ImageStorage
{
    /** @return array{key: string, url: string, mime_type: string, size_bytes: int} */
    public function store(\Webman\Http\UploadFile $file, string $mime, string $extension): array;

    /** @return array{path: string, mime_type: string}|null */
    public function resolve(string $key): ?array;
}
```

`LocalImageStorage` 实现把文件写入 `runtime/uploads/covers/YYYY/MM/`，随机生成文件名，并返回 `/api/media/{key}`。容器通过 `dependence.php` 将 `ImageStorage::class` 绑定到 `LocalImageStorage`。未来 `OssImageStorage` 只需实现同一接口并替换绑定，控制器和前端无需修改。

### 前端交互

`CourseEditView.vue` 的封面字段改为单图 `el-upload`：仅允许图片格式、自动上传、隐藏文件列表，显示当前预览、替换和清除操作。上传成功后把响应 `url` 写入 `form.cover_url`；课程保存接口仍沿用已有 `cover_url` 字段。上传失败保留原封面并显示错误提示，上传过程中禁止重复提交。

## 安全与错误处理

- MIME 使用 `getMimeType()`，扩展名使用原始上传名的 `getUploadExtension()`，两者必须与同一白名单项匹配。
- 上传尺寸由 `COVER_MAX_BYTES` 环境变量覆盖，默认 `5242880`。
- key 只由后端生成；读取路由对 key 做严格正则匹配，并使用 `realpath` 检查目标仍位于封面根目录。
- 文件移动成功后才返回成功；数据库不新增封面表，课程草稿保存仍是唯一的课程字段写入动作。
- 本地文件写入失败时返回统一错误，不泄漏绝对路径或异常详情。

## 测试与验收

- PHP 单元测试覆盖：合法 JPEG/PNG/WebP、MIME/扩展名不一致、超限、空文件、路径穿越读取和不存在文件。
- 前端 API 测试覆盖标准信封解包，视图测试覆盖上传成功预览、替换和清除。
- Compose 中通过 API PHPUnit/PHPStan、管理端 lint/typecheck/test/build。
- 容器重建后，登录管理端进入新建/编辑课程，选择图片后能预览，保存并刷新课程仍显示图片；浏览器控制台无新增错误。
