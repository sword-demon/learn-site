<?php

declare(strict_types=1);

namespace App\controller\learner;

use App\service\BusinessException;
use App\service\CouponService;
use App\support\ApiResponse;
use App\support\Logger;
use support\Request;

/**
 * Learner-facing coupon endpoints (009-learner-coupons).
 *
 * Routes (authenticated — learner access token required):
 *
 *   GET  /api/learner/v1/coupons/claimable
 *   POST /api/learner/v1/coupons/{campaignId}/claim
 *   GET  /api/learner/v1/my/coupons
 *   GET  /api/learner/v1/courses/{courseId}/checkout-coupons
 *
 * Business rules live in CouponService; this controller only parses
 * request params and maps BusinessException to API error envelopes.
 */
final class CouponController
{
    public function __construct(private readonly CouponService $coupons)
    {
    }

    /**
     * GET /api/learner/v1/coupons/claimable
     *
     * Public-claim campaigns the caller can still pick up.
     *
     * Success `200`: `{ items: CouponPublicDTO[] }`
     */
    public function claimable(Request $request): \support\Response
    {
        return $this->wrap(
            fn (): array => ['items' => $this->coupons->listClaimable($this->learnerId($request))],
            $request,
        );
    }

    /**
     * POST /api/learner/v1/coupons/{campaignId}/claim
     *
     * Claim one campaign instance for the caller.
     *
     * Success `201`: `LearnerCouponDTO`
     *
     * Errors:
     *   - `VALIDATION_FAILED` — COUPON_NOT_CLAIMABLE, COUPON_QUOTA_EXCEEDED,
     *     COUPON_CLAIM_LIMIT_EXCEEDED
     *   - `CONFLICT` — COUPON_ALREADY_CLAIMED
     */
    public function claim(Request $request, string $campaignId): \support\Response
    {
        return $this->wrapCreated(
            fn (): array => $this->coupons->claimByLearner(
                $this->parseId($campaignId),
                $this->learnerId($request),
            ),
            $request,
        );
    }

    /**
     * GET /api/learner/v1/my/coupons
     *
     * Paginated list of coupons held by the caller.
     *
     * Query: `status?` (unused|used|expired), `page`, `limit`
     *
     * Success `200`: `{ items: LearnerCouponDTO[], total, page, limit }`
     */
    public function mine(Request $request): \support\Response
    {
        return $this->wrap(
            fn (): array => $this->coupons->listMyCoupons($this->learnerId($request), [
                'page' => (int) $request->get('page', 1),
                'limit' => (int) $request->get('limit', 20),
                'status' => $request->get('status'),
            ]),
            $request,
        );
    }

    /**
     * GET /api/learner/v1/courses/{courseId}/checkout-coupons
     *
     * Checkout preview: course price plus caller's coupons with eligibility
     * and payable_preview for each instance.
     *
     * Success `200`: `{ base_price, list_price, sale_price, items[] }`
     */
    public function checkoutOptions(Request $request, string $courseId): \support\Response
    {
        return $this->wrap(
            fn (): array => $this->coupons->listCheckoutOptions(
                $this->learnerId($request),
                $this->parseId($courseId),
            ),
            $request,
        );
    }

    // ─── helpers ─────────────────────────────────────────────────────

    private function learnerId(Request $request): int
    {
        return (int) ($request->account_id ?? 0);
    }

    private function parseId(string $id): int
    {
        if (!ctype_digit($id) || (int) $id <= 0) {
            throw new BusinessException('VALIDATION_FAILED', 'INVALID_ID');
        }
        return (int) $id;
    }

    /**
     * Run a read operation; map BusinessException to the matching HTTP envelope.
     *
     * @param callable():array<string,mixed> $operation
     */
    private function wrap(callable $operation, Request $request): \support\Response
    {
        try {
            return ApiResponse::ok($operation(), $request->request_id ?? null);
        } catch (BusinessException $exception) {
            return $this->fail($exception, $request);
        } catch (\Throwable $exception) {
            Logger::error('coupon.learner.failed', ['err' => $exception->getMessage()]);
            return ApiResponse::fail(ApiResponse::INTERNAL, 'INTERNAL', $request->request_id ?? null);
        }
    }

    /**
     * Same as wrap(), but returns HTTP 201 for create/claim actions.
     *
     * @param callable():array<string,mixed> $operation
     */
    private function wrapCreated(callable $operation, Request $request): \support\Response
    {
        try {
            return ApiResponse::ok($operation(), $request->request_id ?? null)->withStatus(201);
        } catch (BusinessException $exception) {
            return $this->fail($exception, $request);
        } catch (\Throwable $exception) {
            Logger::error('coupon.learner.failed', ['err' => $exception->getMessage()]);
            return ApiResponse::fail(ApiResponse::INTERNAL, 'INTERNAL', $request->request_id ?? null);
        }
    }

    private function fail(BusinessException $exception, Request $request): \support\Response
    {
        $code = match ($exception->apiCode) {
            ApiResponse::UNAUTHENTICATED => ApiResponse::UNAUTHENTICATED,
            ApiResponse::NOT_FOUND => ApiResponse::NOT_FOUND,
            ApiResponse::CONFLICT => ApiResponse::CONFLICT,
            ApiResponse::FORBIDDEN => ApiResponse::FORBIDDEN,
            default => ApiResponse::VALIDATION_FAILED,
        };
        return ApiResponse::fail($code, $exception->getMessage(), $request->request_id ?? null);
    }
}