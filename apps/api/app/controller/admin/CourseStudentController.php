<?php
declare(strict_types=1);

namespace App\controller\admin;

use App\service\BusinessException;
use App\service\DataScopeService;
use App\service\EntitlementService;
use App\support\ApiResponse;
use App\support\Logger;
use support\Request;
use support\think\Db;

/**
 * Phase 18 / US8 — Course student list (T091).
 *
 *   GET   /api/admin/v1/courses/{courseId}/students?status=&page=&limit=
 *   POST  /api/admin/v1/courses/{courseId}/students/{accountId}/revoke
 *
 * Lists learners holding an entitlement to the given course. Permission is
 * `course_student.view` (Authorize middleware). The actor's department scope
 * is honoured: if DataScopeService.allowedDepartmentIds() returns a list,
 * rows outside that set are filtered out. The revoke endpoint is gated on
 * `course_student.revoke_free`.
 */
final class CourseStudentController
{
    public function __construct(
        private readonly DataScopeService $scope,
        private readonly EntitlementService $entitlements,
    ) {}

    public function index(Request $request, string $courseId): \support\Response
    {
        return $this->wrap(function () use ($request, $courseId) {
            if (!ctype_digit($courseId)) {
                throw new BusinessException('VALIDATION_FAILED', 'INVALID_ID');
            }
            $cid = (int) $courseId;
            $staffId = $this->staffId($request);

            // Course must exist (404 on miss) — surface to admin as not found
            // rather than an empty list, so typos are visible.
            $exists = Db::name('courses')->where('id', $cid)->value('id');
            if (!$exists) {
                throw new BusinessException('NOT_FOUND', 'COURSE_NOT_FOUND');
            }

            $status = (string) $request->get('status', '');
            $page = max(1, (int) $request->get('page', 1));
            $limit = max(1, min(200, (int) $request->get('limit', 20)));

            $q = Db::name('course_entitlements')
                ->alias('ce')
                ->join('learners l', 'l.account_id = ce.learner_id')
                ->join('accounts a', 'a.id = ce.learner_id')
                ->leftJoin('departments d', 'd.id = l.department_id')
                ->where('ce.course_id', $cid)
                ->field('a.id AS account_id, a.login, a.status AS account_status, a.last_login_at, l.display_name, l.department_id, d.name AS department_name, ce.source, ce.status AS entitlement_status, ce.created_at AS enrolled_at, ce.revoked_at');

            if ($status === 'active' || $status === 'revoked') {
                $q->where('ce.status', $status);
            }

            // Department scope. Null = unrestricted.
            $allowed = $this->scope->allowedDepartmentIds($staffId, 'course_student.view');
            if ($allowed !== null) {
                $q->where('l.department_id', 'in', $allowed);
            }

            $total = (clone $q)->count();
            $rows = $q->order('ce.id', 'desc')->page($page, $limit)->select()->toArray();

            $items = array_map(static function ($r) {
                return [
                    'account_id' => (int) $r['account_id'],
                    'login' => (string) $r['login'],
                    'display_name' => (string) $r['display_name'],
                    'department_id' => $r['department_id'] !== null ? (int) $r['department_id'] : null,
                    'department_name' => (string) ($r['department_name'] ?? ''),
                    'account_status' => (string) $r['account_status'],
                    'source' => (string) $r['source'],
                    'entitlement_status' => (string) $r['entitlement_status'],
                    'enrolled_at' => (string) $r['enrolled_at'],
                    'revoked_at' => $r['revoked_at'] !== null ? (string) $r['revoked_at'] : null,
                    'last_login_at' => $r['last_login_at'] !== null ? (string) $r['last_login_at'] : null,
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

    /**
     * Phase 21 / US18 — revoke a free entitlement (T102).
     *
     *   POST /api/admin/v1/courses/{courseId}/students/{accountId}/revoke
     *
     * Body: { "reason": "..." } (optional, defaults to "admin_revoke").
     *
     * US18 rule: only `source='free'` rows are revocable here. Paid
     * entitlements require a refund workflow that is out of scope for the
     * first release — the operator will see 409 PAID_NOT_REVOCABLE in that
     * case.
     */
    public function revoke(Request $request, string $courseId, string $accountId): \support\Response
    {
        return $this->wrap(function () use ($request, $courseId, $accountId) {
            if (!ctype_digit($courseId) || !ctype_digit($accountId)) {
                throw new BusinessException('VALIDATION_FAILED', 'INVALID_ID');
            }
            $cid = (int) $courseId;
            $aid = (int) $accountId;
            $body = self::readJson($request);
            $reason = trim((string) ($body['reason'] ?? ''));
            if ($reason === '') {
                $reason = 'admin_revoke';
            }
            if (mb_strlen($reason) > 255) {
                throw new BusinessException('VALIDATION_FAILED', 'REASON_TOO_LONG');
            }

            $row = Db::name('course_entitlements')
                ->where('course_id', $cid)
                ->where('learner_id', $aid)
                ->where('status', 'active')
                ->find();
            if (!$row) {
                throw new BusinessException('NOT_FOUND', 'NO_ACTIVE_ENTITLEMENT');
            }
            if ((string) $row['source'] !== 'free') {
                throw new BusinessException('CONFLICT', 'PAID_NOT_REVOCABLE');
            }

            $staffId = $this->staffId($request);
            $this->entitlements->revoke($aid, $cid, $reason, $staffId);
            Logger::info('course_student.revoked', [
                'actor_id' => $staffId,
                'course_id' => $cid,
                'learner_id' => $aid,
                'reason' => $reason,
            ]);
            Db::name('audit_log')->insert([
                'actor_id' => $staffId,
                'action' => 'course_student.revoke_free',
                'target_type' => 'course_entitlement',
                'target_id' => (int) $row['id'],
                'payload_json' => json_encode([
                    'course_id' => $cid,
                    'learner_id' => $aid,
                    'reason' => $reason,
                ], JSON_UNESCAPED_UNICODE),
                'created_at' => date('Y-m-d H:i:s'),
            ]);
            return ['revoked' => true];
        });
    }

    // ─── helpers ─────────────────────────────────────────────────────

    private function staffId(Request $request): int
    {
        $v = (int) ($request->account_id ?? 0);
        if ($v <= 0) {
            throw new \RuntimeException('UNAUTHENTICATED');
        }
        return $v;
    }

    private function wrap(callable $fn): \support\Response
    {
        try {
            return ApiResponse::ok($fn());
        } catch (BusinessException $e) {
            return ApiResponse::fail($this->mapApiCode($e->apiCode), $e->getMessage());
        } catch (\RuntimeException $e) {
            if ($e->getMessage() === 'UNAUTHENTICATED') {
                return ApiResponse::fail(ApiResponse::UNAUTHENTICATED, 'UNAUTHENTICATED');
            }
            throw $e;
        } catch (\Throwable $e) {
            Logger::error('course_students.failed', ['err' => $e->getMessage()]);
            return ApiResponse::fail(ApiResponse::INTERNAL, 'INTERNAL');
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
}