<?php
declare(strict_types=1);

namespace App\controller\admin;

use App\model\Account;
use App\service\BusinessException;
use App\service\DataScopeService;
use App\service\LearnerDetailService;
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
        private readonly LearnerDetailService $details,
    ) {}

    /**
     * Site-wide learner accounts list (Phase 18 / US8 — T091).
     * Filters: status (active|disabled|all), search.
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
            $learnerIds = array_values(array_map(
                static fn(array $row): int => (int) $row['id'],
                is_array($rows) ? $rows : [],
            ));
            $learningByLearner = [];
            $ordersByLearner = [];
            if ($learnerIds !== []) {
                $learningRows = Db::name('course_enrollments')
                    ->where('learner_id', 'in', $learnerIds)
                    ->field('learner_id, COUNT(*) AS course_count, SUM(CASE WHEN completed_at IS NOT NULL THEN 1 ELSE 0 END) AS completed_course_count')
                    ->group('learner_id')
                    ->select()
                    ->toArray();
                foreach ($learningRows as $summary) {
                    $learningByLearner[(int) $summary['learner_id']] = $summary;
                }
                $orderRows = Db::name('orders')
                    ->where('learner_id', 'in', $learnerIds)
                    ->where('status', 'succeeded')
                    ->field('learner_id, COUNT(*) AS successful_order_count, COALESCE(SUM(paid_amount), 0) AS total_paid_amount')
                    ->group('learner_id')
                    ->select()
                    ->toArray();
                foreach ($orderRows as $summary) {
                    $ordersByLearner[(int) $summary['learner_id']] = $summary;
                }
            }
            $items = array_map(static function ($r) use ($learningByLearner, $ordersByLearner) {
                $learnerId = (int) $r['id'];
                $learning = $learningByLearner[$learnerId] ?? [];
                $orders = $ordersByLearner[$learnerId] ?? [];
                return [
                    'account_id' => $learnerId,
                    'login' => (string) $r['login'],
                    'display_name' => (string) $r['display_name'],
                    'department_id' => null,
                    'department_name' => '',
                    'status' => (string) $r['status'],
                    'must_change_password' => (int) $r['must_change_password'] === 1,
                    'last_login_at' => $r['last_login_at'] ? (string) $r['last_login_at'] : null,
                    'created_at' => (string) $r['created_at'],
                    'course_count' => (int) ($learning['course_count'] ?? 0),
                    'completed_course_count' => (int) ($learning['completed_course_count'] ?? 0),
                    'successful_order_count' => (int) ($orders['successful_order_count'] ?? 0),
                    'total_paid_amount' => (float) ($orders['total_paid_amount'] ?? 0),
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

    public function learningProgress(Request $request, string $id): \support\Response
    {
        return $this->wrapDetail(function () use ($request, $id) {
            if (!ctype_digit($id)) {
                throw new BusinessException('VALIDATION_FAILED', 'INVALID_ID');
            }
            return $this->details->listCourseProgress($this->staffId($request), (int) $id, [
                'page' => $request->get('page', 1),
                'limit' => $request->get('limit', 20),
            ]);
        });
    }

    public function learningRecords(Request $request, string $id): \support\Response
    {
        return $this->wrapDetail(function () use ($request, $id) {
            if (!ctype_digit($id)) {
                throw new BusinessException('VALIDATION_FAILED', 'INVALID_ID');
            }
            return $this->details->listLessonRecords($this->staffId($request), (int) $id, [
                'page' => $request->get('page', 1),
                'limit' => $request->get('limit', 20),
            ]);
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

    private function wrapDetail(callable $fn): \support\Response
    {
        try {
            return ApiResponse::ok($fn());
        } catch (BusinessException $e) {
            return ApiResponse::fail($this->mapApiCode($e->apiCode), $e->getMessage());
        } catch (\RuntimeException $e) {
            $msg = $e->getMessage();
            if ($msg === 'UNAUTHENTICATED') {
                return ApiResponse::fail(ApiResponse::UNAUTHENTICATED, 'UNAUTHENTICATED');
            }
            throw $e;
        }
    }

    private function mapApiCode(string $code): string
    {
        return match ($code) {
            'UNAUTHENTICATED' => ApiResponse::UNAUTHENTICATED,
            'NOT_FOUND' => ApiResponse::NOT_FOUND,
            'FORBIDDEN' => ApiResponse::FORBIDDEN,
            'VALIDATION_FAILED' => ApiResponse::VALIDATION_FAILED,
            'CONFLICT' => ApiResponse::CONFLICT,
            default => ApiResponse::VALIDATION_FAILED,
        };
    }

    /** @return array<string, mixed> */
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
