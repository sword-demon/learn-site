<?php

declare(strict_types=1);

namespace App\controller\admin;

use App\service\BusinessException;
use App\service\RoleService;
use App\support\ApiResponse;
use support\Request;

/**
 * Admin role CRUD + permission catalogue.
 *
 * Endpoints (Authorize forces org.role on /roles and /permissions):
 *   GET    /roles             — list with permission_ids + scope_department_ids
 *   POST   /roles             — { name, code, data_scope, permission_ids?, scope_department_ids? }
 *   PATCH  /roles/{id}        — { name?, data_scope?, permission_ids?, scope_department_ids? }
 *   PATCH  /roles/{id}/status — { status }
 *   DELETE /roles/{id}
 *   GET    /permissions       — flat permission catalogue
 */
final class RoleController
{
    /** @var array<string, string> */
    private const PERMISSION_LABELS = [
        'dashboard.view' => '查看管理工作台',
        'category.manage' => '管理分类',
        'course.view' => '查看课程',
        'course.manage' => '编辑课程内容',
        'course.publish' => '发布或下架课程',
        'course.delete' => '删除空白草稿',
        'asset.upload' => '上传 PDF 或视频资源',
        'course_student.view' => '查看课程学员名单',
        'course_student.reset' => '重置学员进度',
        'course_student.revoke_free' => '撤销免费课程访问权',
        'qa.view' => '查看问答',
        'qa.answer' => '回复问答',
        'review.view' => '查看评价',
        'review.moderate' => '隐藏或恢复评价',
        'map.view' => '查看学习地图',
        'map.manage' => '编辑学习地图',
        'map.publish' => '发布学习地图',
        'order.view' => '查看订单（只读）',
        'learner.view' => '查看学员账号',
        'learner.reset_password' => '重置学员密码',
        'learner.kick' => '强制学员下线',
        'site.manage' => '编辑站点资料',
        'audit.view' => '查看审计日志',
        'org.department' => '管理部门',
        'org.post' => '管理岗位',
        'org.role' => '管理角色',
        'org.staff' => '管理员工账号',
        'org.grant' => '管理用户级权限覆盖',
    ];

    public function __construct(
        private readonly RoleService $svc,
    ) {
    }

    public function index(Request $request): \support\Response
    {
        return ApiResponse::ok(['items' => $this->svc->listAll()]);
    }

    public function create(Request $request): \support\Response
    {
        try {
            $body = self::readJson($request);
            $row = $this->svc->create(
                name: (string) ($body['name'] ?? ''),
                code: (string) ($body['code'] ?? ''),
                dataScope: (string) ($body['data_scope'] ?? 'self'),
                permissionIds: self::ints($body['permission_ids'] ?? []),
                scopeDepartmentIds: self::ints($body['scope_department_ids'] ?? []),
                actorId: (int) ($request->account_id ?? 0),
            );
            return ApiResponse::ok($row);
        } catch (BusinessException $e) {
            return ApiResponse::fail($e->apiCode, $e->getMessage());
        }
    }

    public function update(Request $request, string $id): \support\Response
    {
        if (!ctype_digit($id) || (int) $id <= 0) {
            return ApiResponse::fail(ApiResponse::VALIDATION_FAILED, 'INVALID_ID');
        }
        try {
            $body = self::readJson($request);
            $row = $this->svc->update(
                id: (int) $id,
                name: array_key_exists('name', $body) ? (string) $body['name'] : null,
                dataScope: array_key_exists('data_scope', $body) ? (string) $body['data_scope'] : null,
                permissionIds: array_key_exists('permission_ids', $body) ? self::ints($body['permission_ids']) : null,
                scopeDepartmentIds: array_key_exists('scope_department_ids', $body) ? self::ints($body['scope_department_ids']) : null,
                actorId: (int) ($request->account_id ?? 0),
            );
            return ApiResponse::ok($row);
        } catch (BusinessException $e) {
            return ApiResponse::fail($e->apiCode, $e->getMessage());
        }
    }

    public function status(Request $request, string $id): \support\Response
    {
        if (!ctype_digit($id) || (int) $id <= 0) {
            return ApiResponse::fail(ApiResponse::VALIDATION_FAILED, 'INVALID_ID');
        }
        try {
            $body = self::readJson($request);
            $row = $this->svc->setStatus(
                (int) $id,
                (string) ($body['status'] ?? ''),
                (int) ($request->account_id ?? 0),
            );
            return ApiResponse::ok($row);
        } catch (BusinessException $e) {
            return ApiResponse::fail($e->apiCode, $e->getMessage());
        }
    }

    public function destroy(Request $request, string $id): \support\Response
    {
        if (!ctype_digit($id) || (int) $id <= 0) {
            return ApiResponse::fail(ApiResponse::VALIDATION_FAILED, 'INVALID_ID');
        }
        try {
            $this->svc->delete((int) $id, (int) ($request->account_id ?? 0));
            return ApiResponse::ok(['deleted' => true]);
        } catch (BusinessException $e) {
            return ApiResponse::fail($e->apiCode, $e->getMessage());
        }
    }

    public function permissions(Request $request): \support\Response
    {
        $items = array_map(static function (array $permission): array {
            $code = (string) ($permission['code'] ?? '');
            if (isset(self::PERMISSION_LABELS[$code])) {
                $permission['description'] = self::PERMISSION_LABELS[$code];
            }
            return $permission;
        }, $this->svc->listPermissions());

        return ApiResponse::ok(['items' => $items]);
    }

    /** @return list<int> */
    private static function ints(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $v) {
            if (is_int($v) || (is_string($v) && ctype_digit($v))) {
                $out[] = (int) $v;
            }
        }
        return $out;
    }

    /** @return array<array-key, mixed> */
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
