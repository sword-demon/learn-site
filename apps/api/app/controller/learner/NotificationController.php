<?php
declare(strict_types=1);

namespace App\controller\learner;

use App\service\BusinessException;
use App\service\OrderService;
use App\service\UnreadCounterService;
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
    public function __construct(private readonly UnreadCounterService $unread = new UnreadCounterService())
    {
    }

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
                'dispatch_id' => $r['dispatch_id'] !== null ? (int) $r['dispatch_id'] : null,
                'resource_type' => $r['resource_type'] !== null ? (string) $r['resource_type'] : null,
                'resource_id' => $r['resource_id'] !== null ? (int) $r['resource_id'] : null,
                'resource_path' => $this->resourcePath(
                    $r['resource_type'] !== null ? (string) $r['resource_type'] : null,
                    $r['resource_id'] !== null ? (int) $r['resource_id'] : null,
                    $aid,
                ),
                'resource_available' => $this->resourceAvailable(
                    $r['resource_type'] !== null ? (string) $r['resource_type'] : null,
                    $r['resource_id'] !== null ? (int) $r['resource_id'] : null,
                    $aid,
                ),
                'resource_unavailable_reason' => $this->resourceUnavailableReason(
                    $r['resource_type'] !== null ? (string) $r['resource_type'] : null,
                    $r['resource_id'] !== null ? (int) $r['resource_id'] : null,
                    $aid,
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

    public function unreadCount(Request $request): \support\Response
    {
        try {
            $aid = (int) ($request->account_id ?? 0);
            if ($aid <= 0) {
                return ApiResponse::fail(ApiResponse::UNAUTHENTICATED, 'UNAUTHENTICATED');
            }
            $count = $this->unread->get($aid);
            return ApiResponse::ok(['count' => $count]);
        } catch (\Throwable $e) {
            Logger::error('notifications.unread_count_failed', ['err' => $e->getMessage()]);
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
                $this->unread->decrement($aid);
            }
            return ApiResponse::ok(['read' => true]);
        } catch (BusinessException $e) {
            return ApiResponse::fail(ApiResponse::VALIDATION_FAILED, $e->getMessage());
        } catch (\Throwable $e) {
            Logger::error('notifications.read_failed', ['err' => $e->getMessage()]);
            return ApiResponse::fail(ApiResponse::INTERNAL, 'INTERNAL');
        }
    }

    private function resourceAvailable(?string $type, ?int $id, int $learnerId): bool
    {
        if (in_array($type, ['course_list', 'map_list', 'coupon_list', 'order_list'], true)) {
            return true;
        }
        if ($type === null || $id === null || $id <= 0) {
            return false;
        }
        return match ($type) {
            'question' => $this->questionPath($learnerId, $id) !== null,
            'course' => Db::name('courses')->where('id', $id)->where('status', 'published')->count() > 0,
            'lesson' => $this->lessonAvailable($learnerId, $id),
            'learning_map' => Db::name('learning_maps')->where('id', $id)->where('status', 'published')->count() > 0,
            'order' => $this->orderAvailable($learnerId, $id),
            'coupon' => $this->couponAvailable($learnerId, $id),
            default => false,
        };
    }

    private function resourcePath(?string $type, ?int $id, int $learnerId): ?string
    {
        if (in_array($type, ['course_list', 'map_list', 'coupon_list', 'order_list'], true)) {
            return match ($type) {
                'course_list' => '/',
                'map_list' => '/maps',
                'coupon_list' => '/me/coupons',
                'order_list' => '/me/orders',
            };
        }
        if (!$this->resourceAvailable($type, $id, $learnerId)) {
            return match ($type) {
                'question', 'course', 'lesson' => '/',
                'learning_map' => '/maps',
                'order' => '/me/orders',
                'coupon' => '/me/coupons',
                default => null,
            };
        }
        return match ($type) {
            'question' => $this->questionPath($learnerId, $id),
            'course' => '/courses/' . $id,
            'lesson' => $this->lessonPath($learnerId, $id),
            'learning_map' => '/maps/' . $id,
            'order' => '/me/orders/' . $id,
            'coupon' => '/me/coupons',
            'question' => '/',
            default => null,
        };
    }

    private function resourceUnavailableReason(?string $type, ?int $id, int $learnerId): ?string
    {
        if ($this->resourceAvailable($type, $id, $learnerId)) {
            return null;
        }
        return match ($type) {
            'question' => '问题或关联课程已不可用',
            'order' => '订单已不可继续支付',
            'coupon' => '优惠券已不可使用',
            'course', 'lesson' => '课程或课节已不可学习',
            'learning_map' => '学习地图已不可用',
            'course_list', 'map_list', 'coupon_list', 'order_list' => null,
            default => '资源暂时不可用',
        };
    }

    private function orderAvailable(int $learnerId, int $orderId): bool
    {
        $row = Db::name('orders')
            ->where('id', $orderId)
            ->where('learner_id', $learnerId)
            ->where('status', 'pending')
            ->find();
        if (!$row) return false;
        return OrderService::isPendingWithin(
            new \DateTimeImmutable((string) $row['created_at'], new \DateTimeZone('UTC')),
            new \DateTimeImmutable('now', new \DateTimeZone('Asia/Shanghai')),
        );
    }

    private function couponAvailable(int $learnerId, int $couponId): bool
    {
        $row = Db::name('learner_coupons')->alias('lc')
            ->join('coupon_campaigns c', 'c.id = lc.campaign_id')
            ->where('lc.id', $couponId)
            ->where('lc.learner_id', $learnerId)
            ->where('lc.status', 'unused')
            ->where('lc.expires_at', '>', gmdate('Y-m-d H:i:s'))
            ->where('c.status', 'active')
            ->field('lc.*, c.scope_type')
            ->find();
        if ($row === null) return false;
        $query = Db::name('courses')->where('courses.status', 'published')->where('courses.price_mode', 'paid');
        $scope = (string) ($row['scope_type'] ?? '');
        if ($scope === 'all') return $query->count() > 0;
        if ($scope === 'course') {
            $query->join('coupon_campaign_courses ccc', 'ccc.course_id = courses.id')
                ->where('ccc.campaign_id', (int) $row['campaign_id']);
        } elseif ($scope === 'category') {
            $query->join('coupon_campaign_categories ccc', 'ccc.category_id = courses.category_id')
                ->where('ccc.campaign_id', (int) $row['campaign_id']);
        } else {
            return false;
        }
        return $query->count() > 0;
    }

    private function questionPath(int $learnerId, int $questionId): ?string
    {
        $row = Db::name('questions')->alias('q')
            ->join('courses c', 'c.id = q.course_id')
            ->where('q.id', $questionId)
            ->where('q.learner_id', $learnerId)
            ->where('c.status', 'published')
            ->field('q.course_id')
            ->find();
        return $row ? '/courses/' . (int) $row['course_id'] : null;
    }

    private function lessonAvailable(int $learnerId, int $lessonId): bool
    {
        $row = Db::name('lessons')->alias('l')
            ->join('chapters ch', 'ch.id = l.chapter_id')
            ->join('courses c', 'c.id = ch.course_id')
            ->where('l.id', $lessonId)
            ->where('l.status', 'enabled')
            ->where('ch.status', 'enabled')
            ->where('c.status', 'published')
            ->find();
        if (!$row) return false;
        return (int) $row['is_preview'] === 1 || Db::name('course_entitlements')
            ->where('learner_id', $learnerId)
            ->where('course_id', (int) $row['course_id'])
            ->where('status', 'active')
            ->count() > 0;
    }

    private function lessonPath(int $learnerId, ?int $lessonId): ?string
    {
        if ($lessonId === null) return null;
        $row = Db::name('lessons')->alias('l')
            ->join('chapters ch', 'ch.id = l.chapter_id')
            ->where('l.id', $lessonId)
            ->field('ch.course_id')
            ->find();
        return $row ? '/learn/' . (int) $row['course_id'] . '/' . $lessonId : null;
    }
}
