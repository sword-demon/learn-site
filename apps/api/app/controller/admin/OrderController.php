<?php
declare(strict_types=1);

namespace App\controller\admin;

use App\service\BusinessException;
use App\service\DataScopeService;
use App\service\OrderService;
use App\support\ApiResponse;
use App\support\Logger;
use support\Request;

/**
 * Admin orders — Phase 14 / US10 (T077/T078/T079).
 *
 * Read-only surface by design. Order state transitions are owned by
 * PaymentAdapter::onNotify() → OrderService::markSucceeded / markFailed.
 * There is intentionally no mark-as-paid endpoint here, so the admin can
 * observe but not manipulate the order book.
 *
 *   GET /api/admin/v1/orders?status=&course_id=&learner_id=&page=&limit=
 *   GET /api/admin/v1/orders/{id}
 *
 * Both endpoints require `order.view` (Authorize middleware).
 */
final class OrderController
{
    public function __construct(
        private readonly OrderService $orders,
        private readonly DataScopeService $scope,
    ) {}

    public function index(Request $request): \support\Response
    {
        return $this->wrap(function () use ($request) {
            $page  = max(1, (int) $request->get('page', 1));
            $limit = max(1, min(200, (int) $request->get('limit', 20)));
            return $this->orders->adminList(
                $this->staffId($request),
                $this->status($request->get('status')),
                $this->idOrNull($request->get('course_id')),
                $this->idOrNull($request->get('learner_id')),
                $page,
                $limit,
                $this->scope,
            );
        });
    }

    public function show(Request $request, string $id): \support\Response
    {
        return $this->wrap(function () use ($request, $id) {
            return $this->orders->adminShow(
                $this->staffId($request),
                $this->id($id),
                $this->scope,
            );
        });
    }

    // ─── helpers ─────────────────────────────────────────────────────

    private function staffId(Request $request): int
    {
        $v = (int) ($request->account_id ?? 0);
        if ($v <= 0) {
            throw new BusinessException('UNAUTHENTICATED', 'UNAUTHENTICATED');
        }
        return $v;
    }

    private function id(string $raw): int
    {
        if (!ctype_digit($raw)) {
            throw new BusinessException('VALIDATION_FAILED', 'INVALID_ID');
        }
        $n = (int) $raw;
        if ($n <= 0) {
            throw new BusinessException('VALIDATION_FAILED', 'INVALID_ID');
        }
        return $n;
    }

    private function idOrNull(mixed $raw): ?int
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        if (is_int($raw)) {
            $n = $raw;
        } elseif (is_string($raw) && ctype_digit($raw)) {
            $n = (int) $raw;
        } else {
            throw new BusinessException('VALIDATION_FAILED', 'INVALID_ID');
        }
        return $n > 0 ? $n : null;
    }

    private function status(mixed $raw): ?string
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        $s = (string) $raw;
        if (!in_array($s, ['pending', 'succeeded', 'failed', 'cancelled', 'unknown'], true)) {
            throw new BusinessException('VALIDATION_FAILED', 'ORDER_STATUS_INVALID');
        }
        return $s;
    }

    private function wrap(callable $fn): \support\Response
    {
        try {
            return ApiResponse::ok($fn(), request()->request_id ?? null);
        } catch (BusinessException $e) {
            return ApiResponse::fail(
                $this->mapApiCode($e->apiCode),
                $e->getMessage(),
                request()->request_id ?? null,
            );
        } catch (\Throwable $e) {
            Logger::error('order.admin.failed', ['err' => $e->getMessage()]);
            return ApiResponse::fail(ApiResponse::INTERNAL, 'INTERNAL', request()->request_id ?? null);
        }
    }

    private function mapApiCode(string $code): string
    {
        return match ($code) {
            'UNAUTHENTICATED' => ApiResponse::UNAUTHENTICATED,
            'NOT_FOUND'       => ApiResponse::NOT_FOUND,
            'FORBIDDEN'       => ApiResponse::FORBIDDEN,
            'VALIDATION_FAILED' => ApiResponse::VALIDATION_FAILED,
            'CONFLICT'        => ApiResponse::CONFLICT,
            default           => ApiResponse::VALIDATION_FAILED,
        };
    }
}
