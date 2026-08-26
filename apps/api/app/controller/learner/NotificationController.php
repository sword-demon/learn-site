<?php
declare(strict_types=1);

namespace App\controller\learner;

use App\service\BusinessException;
use App\support\ApiResponse;
use App\support\Logger;
use support\Request;
use support\think\Db;

/**
 * Phase 21 / US18 — learner inbox (T104 surface).
 *
 *   GET  /api/learner/v1/me/notifications            list unread + recent
 *   POST /api/learner/v1/me/notifications/{id}/read  mark single row read
 *
 * Read-only envelope. A mark-all-read endpoint is intentionally not
 * shipped — single-row read keeps the contract testable.
 */
final class NotificationController
{
    public function index(Request $request): \support\Response
    {
        try {
            $aid = (int) ($request->account_id ?? 0);
            if ($aid <= 0) {
                return ApiResponse::fail(ApiResponse::UNAUTHENTICATED, 'UNAUTHENTICATED');
            }
            $rows = Db::name('learner_notifications')
                ->where('learner_id', $aid)
                ->order('id', 'desc')
                ->limit(50)
                ->select()
                ->toArray();
            $items = array_map(static fn ($r) => [
                'id' => (int) $r['id'],
                'kind' => (string) $r['kind'],
                'title' => (string) $r['title'],
                'body' => $r['body'] !== null ? (string) $r['body'] : null,
                'payload' => $r['payload_json'] !== null ? json_decode((string) $r['payload_json'], true) : null,
                'read' => $r['read_at'] !== null,
                'created_at' => (string) $r['created_at'],
            ], is_array($rows) ? $rows : []);
            return ApiResponse::ok(['items' => $items]);
        } catch (\Throwable $e) {
            Logger::error('notifications.failed', ['err' => $e->getMessage()]);
            return ApiResponse::fail(ApiResponse::INTERNAL, 'INTERNAL');
        }
    }

    public function read(Request $request, string $id): \support\Response
    {
        try {
            if (!ctype_digit($id)) {
                throw new BusinessException('VALIDATION_FAILED', 'INVALID_ID');
            }
            $aid = (int) ($request->account_id ?? 0);
            if ($aid <= 0) {
                return ApiResponse::fail(ApiResponse::UNAUTHENTICATED, 'UNAUTHENTICATED');
            }
            $now = date('Y-m-d H:i:s');
            Db::name('learner_notifications')
                ->where('id', (int) $id)
                ->where('learner_id', $aid)
                ->whereNull('read_at')
                ->update(['read_at' => $now]);
            return ApiResponse::ok(['read' => true]);
        } catch (BusinessException $e) {
            return ApiResponse::fail(ApiResponse::VALIDATION_FAILED, $e->getMessage());
        } catch (\Throwable $e) {
            Logger::error('notifications.read_failed', ['err' => $e->getMessage()]);
            return ApiResponse::fail(ApiResponse::INTERNAL, 'INTERNAL');
        }
    }
}
