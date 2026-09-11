<?php

declare(strict_types=1);

namespace App\controller\learner;

use App\service\BusinessException;
use App\service\CommissionService;
use App\service\DistributionConfigService;
use App\service\ShareEntryService;
use App\support\ApiResponse;
use support\Request;

final class DistributionController
{
    public function __construct(
        private readonly ShareEntryService $shares,
        private readonly CommissionService $commissions,
        private readonly DistributionConfigService $config,
    ) {
    }

    public function status(Request $request): \support\Response
    {
        $cfg = $this->config->getConfig();
        return ApiResponse::ok([
            'enabled' => !empty($cfg['enabled']),
            'learner_can_view_detail' => !empty($cfg['learner_can_view_detail']),
        ]);
    }

    public function shareEntries(Request $request): \support\Response
    {
        $items = $this->shares->listForLearner((int) $request->account_id);
        return ApiResponse::ok(['items' => $items]);
    }

    public function createShareEntry(Request $request): \support\Response
    {
        $body = self::readJson($request);
        $scope = (string) ($body['scope'] ?? '');
        $courseId = isset($body['course_id']) ? (int) $body['course_id'] : null;
        try {
            $entry = $this->shares->create((int) $request->account_id, $scope, $courseId);
        } catch (BusinessException $e) {
            $code = $e->apiCode === 'FORBIDDEN' ? ApiResponse::CONFLICT : ApiResponse::VALIDATION_FAILED;
            if ($e->apiCode === 'NOT_FOUND') {
                $code = ApiResponse::NOT_FOUND;
            }
            return ApiResponse::fail($code, $e->getMessage());
        }
        return ApiResponse::ok($entry);
    }

    public function revokeShareEntry(Request $request, int $id): \support\Response
    {
        try {
            $this->shares->revoke((int) $request->account_id, $id);
        } catch (BusinessException $e) {
            return ApiResponse::fail(ApiResponse::NOT_FOUND, $e->getMessage());
        }
        return ApiResponse::ok(['ok' => true]);
    }

    public function commissions(Request $request): \support\Response
    {
        $cfg = $this->config->getConfig();
        $page = (int) ($request->get('page', '1'));
        $limit = (int) ($request->get('limit', '20'));
        $status = $request->get('status');
        $data = $this->commissions->listForLearner(
            (int) $request->account_id,
            $page,
            $limit,
            is_string($status) ? $status : null,
            !empty($cfg['learner_can_view_detail']),
        );
        return ApiResponse::ok($data);
    }

    public function downline(Request $request): \support\Response
    {
        $cfg = $this->config->getConfig();
        $level = $request->get('level');
        $data = $this->commissions->downline(
            (int) $request->account_id,
            $level !== null && $level !== '' ? (int) $level : null,
            !empty($cfg['learner_can_view_detail']),
        );
        return ApiResponse::ok($data);
    }

    /** @return array<string, mixed> */
    private static function readJson(Request $request): array
    {
        $decoded = json_decode((string) $request->rawBody(), true);
        return is_array($decoded) ? $decoded : [];
    }
}
