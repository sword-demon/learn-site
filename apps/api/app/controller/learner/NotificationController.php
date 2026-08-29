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
            $page = max(1, (int) $request->get('page', 1));
            $limit = max(1, min(100, (int) $request->get('limit', 20)));
            $query = Db::name('learner_notifications')
                ->where('learner_id', $aid)
                ->order('id', 'desc');
            $total = (int) (clone $query)->count();
            $rows = $query
                ->order('id', 'desc')
                ->page($page, $limit)
                ->select()
                ->toArray();
            $items = array_map(fn ($r) => [
                'id' => (int) $r['id'],
                'kind' => (string) $r['kind'],
                'title' => (string) $r['title'],
                'body' => $r['body'] !== null ? (string) $r['body'] : null,
                'resource_type' => $r['resource_type'] !== null ? (string) $r['resource_type'] : null,
                'resource_id' => $r['resource_id'] !== null ? (int) $r['resource_id'] : null,
                'resource_available' => $this->resourceAvailable(
                    $r['resource_type'] !== null ? (string) $r['resource_type'] : null,
                    $r['resource_id'] !== null ? (int) $r['resource_id'] : null,
                ),
                'payload' => $r['payload_json'] !== null ? json_decode((string) $r['payload_json'], true) : null,
                'read' => $r['read_at'] !== null,
                'created_at' => (string) $r['created_at'],
            ], is_array($rows) ? $rows : []);
            return ApiResponse::ok([
                'items' => $items,
                'total' => $total,
                'page' => $page,
                'limit' => $limit,
            ]);
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
            $row = Db::name('learner_notifications')
                ->where('id', (int) $id)
                ->where('learner_id', $aid)
                ->find();
            if (!$row) {
                return ApiResponse::fail(ApiResponse::NOT_FOUND, 'MESSAGE_NOT_FOUND');
            }
            if ($row['read_at'] === null) {
                Db::name('learner_notifications')
                    ->where('id', (int) $id)
                    ->where('learner_id', $aid)
                    ->update(['read_at' => date('Y-m-d H:i:s')]);
            }
            return ApiResponse::ok(['read' => true]);
        } catch (BusinessException $e) {
            return ApiResponse::fail(ApiResponse::VALIDATION_FAILED, $e->getMessage());
        } catch (\Throwable $e) {
            Logger::error('notifications.read_failed', ['err' => $e->getMessage()]);
            return ApiResponse::fail(ApiResponse::INTERNAL, 'INTERNAL');
        }
    }

    private function resourceAvailable(?string $type, ?int $id): bool
    {
        if ($type === null || $id === null || $id <= 0) {
            return false;
        }
        return match ($type) {
            'question' => Db::name('questions')->where('id', $id)->count() > 0,
            'course' => Db::name('courses')->where('id', $id)->where('status', 'published')->count() > 0,
            default => false,
        };
    }
}
