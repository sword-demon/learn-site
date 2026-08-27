<?php
declare(strict_types=1);

namespace App\controller\admin;

use App\model\Account;
use App\service\DataScopeService;
use App\service\TokenService;
use App\support\ApiResponse;
use App\support\Logger;
use support\Request;
use support\think\Db;

final class LearnerController
{
    public function __construct(
        private readonly TokenService $tokens,
        private readonly DataScopeService $scope,
    ) {}

    /**
     * Site-wide learner accounts list (Phase 18 / US8 — T091).
     * Filters: status (active|disabled|all), department_id, search.
     * Permission: `learner.view` (Authorize middleware).
     */
    public function index(Request $request): \support\Response
    {
        return $this->wrap(function () use ($request) {
            $staffId = $this->staffId($request);
            $status = (string) $request->get('status', '');
            $search = trim((string) $request->get('search', ''));
            $department = (string) $request->get('department_id', '');
            $page = max(1, (int) $request->get('page', 1));
            $limit = max(1, min(200, (int) $request->get('limit', 20)));

            $q = Db::name('accounts')
                ->alias('a')
                ->join('learners l', 'l.account_id = a.id')
                ->field('a.id, a.login, a.status, a.must_change_password, a.last_login_at, a.created_at, l.nickname AS display_name');
            if ($status === 'active' || $status === 'disabled') {
                $q->where('a.status', $status);
            }
            if ($search !== '') {
                $q->where(function ($w) use ($search) {
                    $w->where('a.login', 'like', "%{$search}%")
                      ->whereOr('l.nickname', 'like', "%{$search}%");
                });
            }

            $departmentFilter = $department !== '' && ctype_digit($department)
                ? [(int) $department]
                : null;
            // Learners do not own a department. Their visibility is derived
            // from the current department of courses they have accessed.
            $allowed = $this->scope->allowedDepartmentIds($staffId, 'learner.view');
            $departmentIds = $allowed === null
                ? $departmentFilter
                : ($departmentFilter === null
                    ? $allowed
                    : array_values(array_intersect($allowed, $departmentFilter)));
            if ($departmentIds !== null) {
                $learnerIds = Db::name('course_entitlements')
                    ->alias('ce')
                    ->join('courses c', 'c.id = ce.course_id')
                    ->where('c.department_id', 'in', $departmentIds)
                    ->distinct(true)
                    ->column('ce.learner_id');
                $learnerIds = array_values(array_filter(
                    array_map('intval', $learnerIds),
                    static fn (int $id): bool => $id > 0,
                ));
                if ($learnerIds === []) {
                    $q->where('a.id', '<', 0);
                } else {
                    $q->where('a.id', 'in', $learnerIds);
                }
            }

            $total = (clone $q)->count();
            $rows = $q->order('a.id', 'desc')->page($page, $limit)->select()->toArray();
            $items = array_map(static function ($r) {
                return [
                    'account_id' => (int) $r['id'],
                    'login' => (string) $r['login'],
                    'display_name' => (string) $r['display_name'],
                    'department_id' => null,
                    'department_name' => '',
                    'status' => (string) $r['status'],
                    'must_change_password' => (int) $r['must_change_password'] === 1,
                    'last_login_at' => $r['last_login_at'] ? (string) $r['last_login_at'] : null,
                    'created_at' => (string) $r['created_at'],
                ];
            }, is_array($rows) ? $rows : []);
            return [
                'items' => $items,
                'total' => (int) $total,
                'page' => $page,
                'limit' => $limit,
            ];
        });
    }

    public function kickLearner(Request $request, string $id): \support\Response
    {
        return $this->doKick($request, $id, 'learner');
    }

    public function resetPassword(Request $request, string $id): \support\Response
    {
        if (!ctype_digit($id)) {
            return ApiResponse::fail(ApiResponse::VALIDATION_FAILED, 'INVALID_ID');
        }
        $body = self::readJson($request);
        $new = (string) ($body['new_password'] ?? '');
        if (strlen($new) < 8 || strlen($new) > 72) {
            return ApiResponse::fail(ApiResponse::VALIDATION_FAILED, 'PASSWORD_LENGTH');
        }
        $account = Account::where('id', (int) $id)->where('kind', 'learner')->find();
        if (!$account) {
            return ApiResponse::fail(ApiResponse::NOT_FOUND, 'LEARNER_NOT_FOUND');
        }
        $account->password_hash = password_hash($new, PASSWORD_DEFAULT);
        $account->must_change_password = 0;
        $account->updated_at = date('Y-m-d H:i:s');
        $account->save();
        $this->tokens->kickAll($id);
        Logger::info('learner.password_reset', [
            'target_id' => (int) $id,
            'actor_id' => (int) ($request->account_id ?? 0),
        ]);
        return ApiResponse::ok(['reset' => true]);
    }

    private function doKick(Request $request, string $id, string $kind): \support\Response
    {
        if (!ctype_digit($id)) {
            return ApiResponse::fail(ApiResponse::VALIDATION_FAILED, 'INVALID_ID');
        }
        $body = self::readJson($request);
        $family = $body['family_id'] ?? null;
        if ($family !== null && !is_string($family)) {
            return ApiResponse::fail(ApiResponse::VALIDATION_FAILED, 'INVALID_FAMILY');
        }
        $account = Account::where('id', (int) $id)->where('kind', $kind)->find();
        if (!$account) {
            return ApiResponse::fail(ApiResponse::NOT_FOUND, 'ACCOUNT_NOT_FOUND');
        }
        if ($family) {
            $count = $this->tokens->kickFamily($id, $family);
            Logger::info('kick.family', [
                'target_id' => (int) $id,
                'kind' => (string) $account->kind,
                'family_id' => $family,
                'actor_id' => (int) ($request->account_id ?? 0),
                'revoked' => $count,
            ]);
        } else {
            $count = $this->tokens->kickAll($id);
            Logger::info('kick.all', [
                'target_id' => (int) $id,
                'kind' => (string) $account->kind,
                'actor_id' => (int) ($request->account_id ?? 0),
                'revoked' => $count,
            ]);
        }
        return ApiResponse::ok(['revoked' => $count]);
    }

    private function staffId(Request $request): int
    {
        $v = (int) ($request->account_id ?? 0);
        if ($v <= 0) {
            throw new \RuntimeException('UNAUTHENTICATED');
        }
        return $v;
    }

    /**
     * Wrap a body callable so that an UNAUTHENTICATED throw becomes the
     * proper API code instead of leaking a 500. All other throwables bubble
     * to the global handler.
     */
    private function wrap(callable $fn): \support\Response
    {
        try {
            return ApiResponse::ok($fn());
        } catch (\RuntimeException $e) {
            $msg = $e->getMessage();
            if ($msg === 'UNAUTHENTICATED') {
                return ApiResponse::fail(ApiResponse::UNAUTHENTICATED, 'UNAUTHENTICATED');
            }
            throw $e;
        }
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
