<?php
declare(strict_types=1);

namespace App\controller\admin;

use App\model\Category;
use App\service\BusinessException;
use App\service\HomeService;
use App\support\ApiResponse;
use App\support\Logger;
use support\Request;
use support\think\Db;

/**
 * Admin category CRUD.
 *
 * Endpoints (all under /api/admin/v1/categories, gated by AdminAuth +
 * Authorize which forces category.manage):
 *
 *   GET    /categories       — full tree (enabled + disabled) for the
 *                              admin UI. The learner-facing home tree
 *                              is built by HomeService::categoryTree().
 *   GET    /categories/flat  — flat paginated, optional ?status=
 *   POST   /categories       — body: { parent_id, name, sort? }
 *   PATCH  /categories/{id}  — { name?, sort? }
 *   PATCH  /categories/{id}/status — { status }
 *   DELETE /categories/{id}
 *
 * Notes:
 *  - depth is capped at 3 (home → category → sub → leaf). parent.depth+1≤3.
 *  - Disabling a category that still has a published course under it
 *    returns CATEGORY_IN_USE (HTTP 409).
 *  - Deleting a category with any course row (any status) returns CONFLICT.
 */
final class CategoryController
{
    private const MAX_DEPTH = 3;

    public function index(Request $request): \support\Response
    {
        $rows = Db::name('categories')
            ->order('depth', 'asc')
            ->order('sort', 'asc')
            ->order('id', 'asc')
            ->select()
            ->toArray();
        return ApiResponse::ok([
            'tree' => HomeService::nest(array_map(fn($r) => [
                'id'        => (int) $r['id'],
                'parent_id' => (int) $r['parent_id'],
                'name'      => (string) $r['name'],
            ], $rows)),
            'flat' => array_map(fn($r) => $this->shape($r), $rows),
        ]);
    }

    public function flat(Request $request): \support\Response
    {
        $page  = max(1, (int) $request->get('page', 1));
        $limit = min(200, max(1, (int) $request->get('limit', 100)));
        $q = Db::name('categories');
        $status = (string) $request->get('status', '');
        if ($status !== '') {
            $q->where('status', $status);
        }
        $total = (clone $q)->count();
        $rows  = $q->order('depth', 'asc')->order('sort', 'asc')->order('id', 'asc')
            ->page($page, $limit)->select()->toArray();
        return ApiResponse::ok([
            'items' => array_map(fn($r) => $this->shape($r), $rows),
            'total' => (int) $total,
            'page'  => $page,
            'limit' => $limit,
        ]);
    }

    public function create(Request $request): \support\Response
    {
        $body = self::readJson($request);
        $parentId = (int) ($body['parent_id'] ?? 0);
        $name     = trim((string) ($body['name'] ?? ''));
        $sort     = isset($body['sort']) ? (int) $body['sort'] : 0;
        if ($name === '' || mb_strlen($name) > 64) {
            return ApiResponse::fail(ApiResponse::VALIDATION_FAILED, 'CATEGORY_NAME_INVALID');
        }

        $parentPath = '/';
        $depth = 1;
        if ($parentId > 0) {
            $parent = Category::find($parentId);
            if (!$parent) {
                return ApiResponse::fail(ApiResponse::NOT_FOUND, 'CATEGORY_NOT_FOUND');
            }
            $depth = ((int) $parent->depth) + 1;
            if ($depth > self::MAX_DEPTH) {
                return ApiResponse::fail(ApiResponse::VALIDATION_FAILED, 'CATEGORY_DEPTH_EXCEEDED');
            }
            $parentPath = (string) $parent->path;
        }

        // Reject duplicate sibling name.
        $dup = Db::name('categories')
            ->where('parent_id', $parentId)
            ->where('name', $name)
            ->find();
        if ($dup) {
            return ApiResponse::fail(ApiResponse::CONFLICT, 'CATEGORY_NAME_TAKEN');
        }

        $now = date('Y-m-d H:i:s');
        $id = (int) Db::transaction(function () use ($parentId, $name, $sort, $depth, $parentPath, $now) {
            return Category::create([
                'parent_id'  => $parentId,
                'name'       => $name,
                'path'       => $parentPath, // path patched with id below
                'depth'      => $depth,
                'sort'       => $sort,
                'status'     => 'enabled',
                'created_at' => $now,
                'updated_at' => $now,
            ])->id;
        });
        Category::where('id', $id)->update([
            'path' => $parentPath === '/' ? '/' . $id : $parentPath . '/' . $id,
        ]);
        Logger::info('category.created', ['id' => $id, 'parent_id' => $parentId]);
        return ApiResponse::ok($this->row($id));
    }

    public function update(Request $request, string $id): \support\Response
    {
        if (!ctype_digit($id) || (int) $id <= 0) {
            return ApiResponse::fail(ApiResponse::VALIDATION_FAILED, 'INVALID_ID');
        }
        $row = Category::find((int) $id);
        if (!$row) {
            return ApiResponse::fail(ApiResponse::NOT_FOUND, 'CATEGORY_NOT_FOUND');
        }
        $body = self::readJson($request);
        $patch = ['updated_at' => date('Y-m-d H:i:s')];
        if (array_key_exists('name', $body)) {
            $name = trim((string) $body['name']);
            if ($name === '' || mb_strlen($name) > 64) {
                return ApiResponse::fail(ApiResponse::VALIDATION_FAILED, 'CATEGORY_NAME_INVALID');
            }
            // Sibling-uniqueness check before applying.
            $dup = Db::name('categories')
                ->where('parent_id', (int) $row->parent_id)
                ->where('name', $name)
                ->where('id', '<>', (int) $id)
                ->find();
            if ($dup) {
                return ApiResponse::fail(ApiResponse::CONFLICT, 'CATEGORY_NAME_TAKEN');
            }
            $patch['name'] = $name;
        }
        if (array_key_exists('sort', $body)) {
            $patch['sort'] = max(0, (int) $body['sort']);
        }
        Category::where('id', (int) $id)->update($patch);
        Logger::info('category.updated', ['id' => (int) $id]);
        return ApiResponse::ok($this->row((int) $id));
    }

    public function status(Request $request, string $id): \support\Response
    {
        if (!ctype_digit($id) || (int) $id <= 0) {
            return ApiResponse::fail(ApiResponse::VALIDATION_FAILED, 'INVALID_ID');
        }
        $row = Category::find((int) $id);
        if (!$row) {
            return ApiResponse::fail(ApiResponse::NOT_FOUND, 'CATEGORY_NOT_FOUND');
        }
        $body = self::readJson($request);
        $status = (string) ($body['status'] ?? '');
        if (!in_array($status, ['enabled', 'disabled'], true)) {
            return ApiResponse::fail(ApiResponse::VALIDATION_FAILED, 'CATEGORY_STATUS_INVALID');
        }
        if ($status === 'disabled') {
            $publishedCount = (int) Db::name('courses')
                ->where('category_id', (int) $id)
                ->where('status', 'published')
                ->count();
            if ($publishedCount > 0) {
                return ApiResponse::fail(ApiResponse::CATEGORY_IN_USE, 'CATEGORY_IN_USE');
            }
        }
        Category::where('id', (int) $id)->update([
            'status'     => $status,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        Logger::info('category.status_changed', ['id' => (int) $id, 'status' => $status]);
        return ApiResponse::ok($this->row((int) $id));
    }

    public function destroy(Request $request, string $id): \support\Response
    {
        if (!ctype_digit($id) || (int) $id <= 0) {
            return ApiResponse::fail(ApiResponse::VALIDATION_FAILED, 'INVALID_ID');
        }
        $row = Category::find((int) $id);
        if (!$row) {
            return ApiResponse::fail(ApiResponse::NOT_FOUND, 'CATEGORY_NOT_FOUND');
        }
        $children = (int) Db::name('categories')->where('parent_id', (int) $id)->count();
        if ($children > 0) {
            return ApiResponse::fail(ApiResponse::CONFLICT, 'CATEGORY_HAS_CHILDREN');
        }
        $courses = (int) Db::name('courses')->where('category_id', (int) $id)->count();
        if ($courses > 0) {
            return ApiResponse::fail(ApiResponse::CONFLICT, 'CATEGORY_IN_USE');
        }
        Category::where('id', (int) $id)->delete();
        Logger::info('category.deleted', ['id' => (int) $id]);
        return ApiResponse::ok(['deleted' => true]);
    }

    private function row(int $id): array
    {
        $r = Category::find($id);
        return $r ? $this->shape($r->toArray()) : [];
    }

    private function shape(array $r): array
    {
        return [
            'id'         => (int) $r['id'],
            'parent_id'  => (int) ($r['parent_id'] ?? 0),
            'name'       => (string) $r['name'],
            'path'       => (string) ($r['path'] ?? '/'),
            'depth'      => (int) $r['depth'],
            'sort'       => (int) $r['sort'],
            'status'     => (string) $r['status'],
            'created_at' => (string) ($r['created_at'] ?? ''),
            'updated_at' => (string) ($r['updated_at'] ?? ''),
        ];
    }

    private static function readJson(Request $request): array
    {
        $raw = (string) $request->rawBody();
        if ($raw === '') {
            return [];
        }
        $data = json_decode($raw, true);
        return is_array($data) ? $data : [];
    }
}
