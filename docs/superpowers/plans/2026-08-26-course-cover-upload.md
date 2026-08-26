# 课程封面上传实施计划

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 为课程编辑页提供 JPEG/PNG/WebP 封面上传、本地持久化读取和可替换的图片存储适配接口。

**Architecture:** `CourseCoverController` 只负责请求校验和统一响应，`ImageStorage` 隐藏文件落盘与 key 解析，当前由 `LocalImageStorage` 实现并通过容器绑定。管理端使用 `el-upload` 调用新上传接口，把返回 URL 写回现有 `cover_url` 字段；媒体读取路由只暴露服务端生成的 key。

**Tech Stack:** Webman/PHP 8.4、Think ORM、Vue 3、Element Plus、TypeScript、Zod、Vitest、PHPUnit、PHPStan、Docker Compose。

**Spec:** `docs/superpowers/specs/2026-08-26-course-cover-upload-design.md`

## Global Constraints

- 允许格式仅为 JPEG、PNG、WebP，单张最大 5 MiB。
- 服务端同时校验实际 MIME 和原始文件扩展名；非法文件不得落盘。
- 存储文件名由后端随机生成，不使用用户原始文件名。
- 不扩展课节 `assets` 表；课程仍保存 `courses.cover_url`。
- 不执行 stage、commit 或 push；验证使用 Compose，保留现有未跟踪 worktree。

### Task 1: Image Storage Interface and Local Adapter

**Files:**
- Create: `apps/api/app/support/storage/ImageStorage.php`
- Create: `apps/api/app/support/storage/LocalImageStorage.php`
- Modify: `apps/api/config/dependence.php`
- Test: `apps/api/tests/ImageStorageTest.php`

**Interfaces:**
- `ImageStorage::store(\Webman\Http\UploadFile $file, string $mime, string $extension): array{key:string,url:string,mime_type:string,size_bytes:int}`
- `ImageStorage::resolve(string $key): ?array{path:string,mime_type:string}`
- `LocalImageStorage` receives an optional root path defaulting to `runtime_path('uploads/covers')`, allowing tests to use an isolated temporary directory.

- [ ] **Step 1: Write failing storage tests**

Add tests that create a real temporary `UploadFile` around a small JPEG/PNG/WebP fixture, call `store`, assert the generated key matches `covers/YYYY/MM/[a-f0-9]{32}.ext`, the returned URL is `/api/media/{key}`, and `resolve` returns the file path and MIME. Add a path traversal test asserting `resolve('../runtime/logs/x') === null` and an absent-file test asserting `null`.

- [ ] **Step 2: Run the focused test and verify it fails**

Run:

```bash
docker compose -f compose.yaml -f compose.test.yaml --profile test run --rm --no-deps api-test php vendor/bin/phpunit tests/ImageStorageTest.php
```

Expected: FAIL because `ImageStorage` and `LocalImageStorage` do not exist.

- [ ] **Step 3: Implement the interface and local adapter**

Implement strict key validation, `realpath` containment under the configured root, random names from `random_bytes(16)`, date directories, and `UploadFile::move`. Return only relative key, public URL, MIME, and size. Catch filesystem exceptions in the caller, not inside `resolve`.

- [ ] **Step 4: Run the focused test and verify it passes**

Run the same PHPUnit command; expected `PASS` for storage, traversal, and missing-file cases.

- [ ] **Step 5: Bind the adapter**

Add `ImageStorage::class => new LocalImageStorage()` to `apps/api/config/dependence.php`, preserving the existing payment binding.

### Task 2: Upload and Media Read Endpoints

**Files:**
- Create: `apps/api/app/controller/admin/CourseCoverController.php`
- Create: `apps/api/app/controller/media/CourseCoverMediaController.php`
- Modify: `apps/api/app/route.php`
- Modify: `apps/api/app/middleware/Authorize.php`
- Modify: `apps/api/app/support/ApiResponse.php` only if a new stable status mapping is required
- Test: `apps/api/tests/CourseCoverControllerTest.php`
- Test: `apps/api/tests/AuthorizeLeakTest.php` (extend existing permission case)

**Interfaces:**
- `POST /api/admin/v1/course-covers` with multipart field `file`; success data is `{key,url,mime_type,size_bytes}`.
- `GET /api/media/{key}` returns the image bytes with stored MIME or a non-leaking 404 response.
- `Authorize::permissionFor('/api/admin/v1/course-covers', 'POST') === 'course.manage'`.

- [ ] **Step 1: Write failing endpoint and authorization tests**

Cover controller tests must exercise: missing file, zero-byte file, unsupported MIME, extension mismatch, over-5-MiB file, valid upload, and storage failure. Assert error envelopes and that invalid cases do not create a file. Add a route authorization data case for `course.manage`.

- [ ] **Step 2: Run focused tests and verify they fail**

Run:

```bash
docker compose -f compose.yaml -f compose.test.yaml --profile test run --rm --no-deps api-test php vendor/bin/phpunit tests/CourseCoverControllerTest.php tests/AuthorizeLeakTest.php
```

Expected: FAIL because the routes/controller and permission mapping are absent.

- [ ] **Step 3: Implement controller, media reader, routes, and validation**

Inject `ImageStorage` into `CourseCoverController`, require a valid `UploadFile`, enforce `COVER_MAX_BYTES` default `5242880`, map MIME/extension pairs (`image/jpeg`→`jpg|jpeg`, `image/png`→`png`, `image/webp`→`webp`), call `store`, and wrap the result with `ApiResponse::ok`. Add the admin route under existing middleware and media route without admin auth. Media controller must call `resolve`, return `response()->file($path)->header('Content-Type', $mime)` on success, and use `ApiResponse::fail(NOT_FOUND, 'COVER_NOT_FOUND')` otherwise.

- [ ] **Step 4: Run focused tests and verify they pass**

Run the same PHPUnit command; expected all cover and authorization tests pass.

### Task 3: Frontend Upload Contract and Course Editor Component

**Files:**
- Modify: `apps/admin/src/api/catalog.ts`
- Modify: `apps/admin/src/views/catalog/CourseEditView.vue`
- Test: `apps/admin/tests/CatalogApi.test.ts`
- Create or modify: `apps/admin/tests/CourseEditView.test.ts`

**Interfaces:**
- `uploadCourseCover(input: { file: File; onUploadProgress?: (event: {loaded:number;total?:number}) => void }): Promise<{key:string;url:string;mime_type:string;size_bytes:number}>`.
- `CourseEditView` keeps `form.cover_url: string`; upload success changes only that field, while clear sets it to `''`.

- [ ] **Step 1: Write failing frontend tests**

Mock the HTTP client with a standard `{ok:true,data:{...}}` envelope and assert `uploadCourseCover` sends `FormData` to `/course-covers`. Mount the course editor with a mocked upload request, assert success renders an image preview and updates `form.cover_url`, and assert clear removes the preview without changing unrelated course fields.

- [ ] **Step 2: Run focused frontend tests and verify they fail**

Run:

```bash
docker compose -f compose.yaml -f compose.test.yaml --profile test run --rm --no-deps frontend-test sh -c 'corepack enable && corepack prepare pnpm@9.12.0 --activate >/dev/null && pnpm install --frozen-lockfile >/dev/null && pnpm --filter @learn-site/admin test -- --run tests/CatalogApi.test.ts tests/CourseEditView.test.ts'
```

Expected: FAIL because the new API function and upload UI do not exist.

- [ ] **Step 3: Implement API wrapper and upload component**

Parse `ApiOk` with a Zod schema, use `el-upload` with `auto-upload`, `show-file-list=false`, `accept="image/jpeg,image/png,image/webp"`, and a custom request handler. Render an `<el-image>` preview when `form.cover_url` is non-empty, expose a replace action, clear action, loading state, and error toast. Keep `saveDraft` payload unchanged except for the uploaded URL already present in `form.cover_url`.

- [ ] **Step 4: Run focused frontend tests and verify they pass**

Run the same Vitest command; expected all focused tests pass.

### Task 4: Full Verification and Container Rollout

**Files:**
- Modify: none unless verification finds a concrete failure.

- [ ] **Step 1: Run full Compose frontend and API gates**

Run:

```bash
docker compose -f compose.yaml -f compose.test.yaml --profile test run --rm frontend-test
docker compose -f compose.yaml -f compose.test.yaml --profile test run --rm --no-deps api-test
```

Expected: lint, typecheck, all frontend tests/build, PHPUnit, PHPStan, and formatter checks pass according to the existing test image commands.

- [ ] **Step 2: Rebuild the local API and frontends**

Run:

```bash
docker compose up -d --build api admin web
docker compose ps api admin web mysql redis
```

Expected: all five services show healthy.

- [ ] **Step 3: Perform browser-level smoke verification**

After the user signs in, open course create/edit, choose a valid image, confirm preview, save draft, reload, and confirm the cover remains. Also confirm an invalid extension/MIME is rejected and no new console errors appear. Do not enter credentials or submit forms on the user’s behalf.

## Execution Notes

- Keep the existing `AssetController` for PDF/video lessons unchanged.
- Do not create a database table for covers in this iteration.
- Do not log raw filenames, local paths, tokens, or image contents.
