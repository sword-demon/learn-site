<?php
declare(strict_types=1);

namespace App\controller\admin;

use App\support\ApiResponse;
use App\support\Logger;
use support\Request;
use support\think\Db;

/**
 * Phase 19 / US11 — moderation log (T095).
 *
 *   GET /api/admin/v1/audit?action=&target_type=&actor_id=&page=&limit=
 *
 * Permission: `audit.view` (Authorize middleware).
 *
 * Read-only. Audit rows are append-only — no PUT/DELETE here. The writer
 * side lives in services / PaymentAdapter where state transitions happen.
 */
final class AuditController
{
    public function index(Request $request): \support\Response
    {
        return $this->wrap(function () use ($request) {
            $action = (string) $request->get('action', '');
            $targetType = (string) $request->get('target_type', '');
            $actorId = (string) $request->get('actor_id', '');
            $page = max(1, (int) $request->get('page', 1));
            $limit = max(1, min(200, (int) $request->get('limit', 50)));

            $q = Db::name('audit_log')
                ->alias('al')
                ->leftJoin('accounts a', 'a.id = al.actor_id')
                ->field('al.id, al.actor_id, al.action, al.target_type, al.target_id, al.payload_json, al.created_at, a.login AS actor_login');
            if ($action !== '') {
                $q->where('al.action', $action);
            }
            if ($targetType !== '') {
                $q->where('al.target_type', $targetType);
            }
            if ($actorId !== '' && ctype_digit($actorId)) {
                $q->where('al.actor_id', (int) $actorId);
            }

            $total = (clone $q)->count();
            $rows = $q->order('al.id', 'desc')->page($page, $limit)->select()->toArray();
            $items = array_map(static function ($r) {
                return [
                    'id' => (int) $r['id'],
                    'actor_id' => $r['actor_id'] !== null ? (int) $r['actor_id'] : null,
                    'actor_login' => $r['actor_login'] !== null ? (string) $r['actor_login'] : null,
                    'action' => (string) $r['action'],
                    'target_type' => (string) $r['target_type'],
                    'target_id' => $r['target_id'] !== null ? (int) $r['target_id'] : null,
                    'payload_json' => (string) ($r['payload_json'] ?? ''),
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

    private function wrap(callable $fn): \support\Response
    {
        try {
            return ApiResponse::ok($fn());
        } catch (\Throwable $e) {
            Logger::error('audit.list.failed', ['err' => $e->getMessage()]);
            return ApiResponse::fail(ApiResponse::INTERNAL, 'INTERNAL');
        }
    }
}