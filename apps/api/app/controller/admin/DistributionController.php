<?php

declare(strict_types=1);

namespace App\controller\admin;

use App\service\BusinessException;
use App\service\CommissionService;
use App\service\DistributionAuditService;
use App\service\DistributionConfigService;
use App\service\DistributionCourseOverrideService;
use App\support\ApiResponse;
use App\support\Logger;
use support\Request;

final class DistributionController
{
    public function __construct(
        private readonly DistributionConfigService $config,
        private readonly DistributionCourseOverrideService $overrides,
        private readonly CommissionService $commissions,
        private readonly DistributionAuditService $audits = new DistributionAuditService(),
    ) {
    }

    public function getConfig(Request $request): \support\Response
    {
        return $this->wrap(fn (): array => $this->config->getConfig());
    }

    public function updateConfig(Request $request): \support\Response
    {
        return $this->wrap(fn (): array => $this->config->updateConfig(
            (int) ($request->account_id ?? 0),
            self::readJson($request),
        ));
    }

    public function listOverrides(Request $request): \support\Response
    {
        $page = (int) ($request->get('page', '1'));
        $limit = (int) ($request->get('limit', '20'));
        return $this->wrap(fn (): array => $this->overrides->list($page, $limit));
    }

    public function upsertOverride(Request $request, int $courseId): \support\Response
    {
        return $this->wrap(fn (): array => $this->overrides->upsert(
            $courseId,
            self::readJson($request),
            (int) ($request->account_id ?? 0),
        ));
    }

    public function reconcileByOrder(Request $request, int $orderId): \support\Response
    {
        return $this->wrap(fn (): array => $this->commissions->getByOrder($orderId));
    }

    public function listCommissions(Request $request): \support\Response
    {
        $page = (int) ($request->get('page', '1'));
        $limit = (int) ($request->get('limit', '20'));
        $filter = [
            'learner_id' => $request->get('learner_id'),
            'course_id' => $request->get('course_id'),
            'status' => $request->get('status'),
        ];
        return $this->wrap(fn (): array => $this->commissions->listForAdmin($filter, $page, $limit));
    }

    public function voidCommission(Request $request, int $id): \support\Response
    {
        $body = self::readJson($request);
        return $this->wrap(fn (): array => $this->commissions->voidByAdmin(
            $id,
            (int) ($request->account_id ?? 0),
            (string) ($body['reason'] ?? ''),
        ));
    }

    public function audit(Request $request): \support\Response
    {
        $page = max(1, (int) ($request->get('page', '1')));
        $limit = max(1, min(200, (int) ($request->get('limit', '20'))));
        $action = (string) $request->get('action', '');
        return $this->wrap(fn (): array => $this->audits->list($page, $limit, $action !== '' ? $action : null));
    }

    public function exportCommissions(Request $request): \support\Response
    {
        $filter = [
            'learner_id' => $request->get('learner_id'),
            'course_id' => $request->get('course_id'),
            'status' => $request->get('status'),
        ];
        try {
            $csv = $this->commissions->exportCsv($filter);
        } catch (BusinessException $e) {
            return ApiResponse::fail(ApiResponse::VALIDATION_FAILED, $e->getMessage());
        }
        return response($csv, 200, [
            'Content-Type' => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="commissions.csv"',
        ]);
    }

    private function wrap(callable $operation): \support\Response
    {
        try {
            return ApiResponse::ok($operation(), request()->request_id ?? null);
        } catch (BusinessException $exception) {
            $http = match ($exception->apiCode) {
                'UNAUTHENTICATED' => ApiResponse::UNAUTHENTICATED,
                'FORBIDDEN' => ApiResponse::FORBIDDEN,
                'NOT_FOUND' => ApiResponse::NOT_FOUND,
                default => ApiResponse::VALIDATION_FAILED,
            };
            return ApiResponse::fail($http, $exception->getMessage(), request()->request_id ?? null);
        } catch (\Throwable $exception) {
            Logger::error('distribution.admin.failed', ['err' => $exception->getMessage()]);
            return ApiResponse::fail(ApiResponse::INTERNAL, 'INTERNAL', request()->request_id ?? null);
        }
    }

    /** @return array<string, mixed> */
    private static function readJson(Request $request): array
    {
        $decoded = json_decode((string) $request->rawBody(), true);
        return is_array($decoded) ? $decoded : [];
    }
}
