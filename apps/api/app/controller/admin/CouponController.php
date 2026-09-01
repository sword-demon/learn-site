<?php

declare(strict_types=1);

namespace App\controller\admin;

use App\service\BusinessException;
use App\service\CouponService;
use App\support\ApiResponse;
use App\support\Logger;
use support\Request;

final class CouponController
{
    public function __construct(private readonly CouponService $coupons)
    {
    }

    public function index(Request $request): \support\Response
    {
        return $this->wrap(fn (): array => $this->coupons->listCampaignsForAdmin([
            'page' => (int) $request->get('page', 1),
            'limit' => (int) $request->get('limit', 20),
            'scope_type' => $request->get('scope_type'),
            'status' => $request->get('status'),
        ]), $request);
    }

    public function show(Request $request, string $id): \support\Response
    {
        return $this->wrap(fn (): array => $this->coupons->showCampaign($this->parseId($id)), $request);
    }

    public function store(Request $request): \support\Response
    {
        $body = self::readJson($request);
        return $this->wrapCreated(
            fn (): array => $this->coupons->createCampaign($body, $this->actorId($request)),
            $request,
        );
    }

    public function update(Request $request, string $id): \support\Response
    {
        $body = self::readJson($request);
        return $this->wrap(
            fn (): array => $this->coupons->patchCampaign(
                $this->parseId($id),
                $body,
                $this->actorId($request),
            ),
            $request,
        );
    }

    public function disable(Request $request, string $id): \support\Response
    {
        try {
            $this->coupons->disableCampaign($this->parseId($id), $this->actorId($request));
            return response('', 204);
        } catch (BusinessException $exception) {
            return $this->fail($exception, $request);
        } catch (\Throwable $exception) {
            Logger::error('coupon.admin.failed', ['err' => $exception->getMessage()]);
            return ApiResponse::fail(ApiResponse::INTERNAL, 'INTERNAL', $request->request_id ?? null);
        }
    }

    public function grants(Request $request, string $id): \support\Response
    {
        $body = self::readJson($request);
        return $this->wrap(
            fn (): array => $this->coupons->grantToLearners(
                $this->parseId($id),
                $body,
                $this->actorId($request),
            ),
            $request,
        );
    }

    public function redemptions(Request $request): \support\Response
    {
        return $this->wrap(fn (): array => $this->coupons->listRedemptions([
            'page' => (int) $request->get('page', 1),
            'limit' => (int) $request->get('limit', 20),
            'campaign_id' => $request->get('campaign_id') !== null && $request->get('campaign_id') !== ''
                ? (int) $request->get('campaign_id')
                : null,
            'learner_id' => $request->get('learner_id') !== null && $request->get('learner_id') !== ''
                ? (int) $request->get('learner_id')
                : null,
            'from' => $request->get('from'),
            'to' => $request->get('to'),
        ]), $request);
    }

    private function parseId(string $id): int
    {
        if (!ctype_digit($id) || (int) $id <= 0) {
            throw new BusinessException('VALIDATION_FAILED', 'INVALID_ID');
        }
        return (int) $id;
    }

    private function actorId(Request $request): int
    {
        return (int) ($request->account_id ?? 0);
    }

    /** @param callable():array<string,mixed> $operation */
    private function wrap(callable $operation, Request $request): \support\Response
    {
        try {
            return ApiResponse::ok($operation(), $request->request_id ?? null);
        } catch (BusinessException $exception) {
            return $this->fail($exception, $request);
        } catch (\Throwable $exception) {
            Logger::error('coupon.admin.failed', ['err' => $exception->getMessage()]);
            return ApiResponse::fail(ApiResponse::INTERNAL, 'INTERNAL', $request->request_id ?? null);
        }
    }

    /** @param callable():array<string,mixed> $operation */
    private function wrapCreated(callable $operation, Request $request): \support\Response
    {
        try {
            return ApiResponse::ok($operation(), $request->request_id ?? null)->withStatus(201);
        } catch (BusinessException $exception) {
            return $this->fail($exception, $request);
        } catch (\Throwable $exception) {
            Logger::error('coupon.admin.failed', ['err' => $exception->getMessage()]);
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

    /** @return array<string,mixed> */
    private static function readJson(Request $request): array
    {
        $decoded = json_decode((string) $request->rawBody(), true);
        return is_array($decoded) ? $decoded : [];
    }
}