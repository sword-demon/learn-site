<?php

declare(strict_types=1);

namespace App\controller\learner;

use App\service\ShareEntryService;
use App\support\ApiResponse;
use support\Request;

/**
 * Learner-owned share CRUD. Authenticated routes; only the owner can mint
 * or revoke their own entries.
 */
final class ShareEntryController
{
    public function __construct(
        private readonly ShareEntryService $service,
    ) {
    }

    public function index(Request $request): \support\Response
    {
        $learnerId = (int) $request->account_id;
        $limit = (int) ($request->get('limit', '50'));
        $items = $this->service->listForLearner($learnerId, $limit);
        return ApiResponse::ok(['items' => $items]);
    }

    public function store(Request $request): \support\Response
    {
        $body = self::readJson($request);
        $scope = (string) ($body['scope'] ?? '');
        if ($scope !== 'course' && $scope !== 'site') {
            return ApiResponse::fail(ApiResponse::VALIDATION_FAILED, 'INVALID_SCOPE');
        }
        $courseId = isset($body['course_id']) ? (int) $body['course_id'] : null;
        try {
            $entry = $this->service->create(
                (int) $request->account_id,
                $scope,
                $courseId,
            );
        } catch (\App\service\BusinessException $e) {
            return ApiResponse::fail(self::translateCode($e->apiCode), $e->getMessage());
        }
        return ApiResponse::ok($entry);
    }

    public function revoke(Request $request, int $id): \support\Response
    {
        try {
            $this->service->revoke((int) $request->account_id, $id);
        } catch (\App\service\BusinessException $e) {
            return ApiResponse::fail(self::translateCode($e->apiCode), $e->getMessage());
        }
        return ApiResponse::ok(['revoked' => true, 'id' => $id]);
    }

    /** @return array<string, mixed> */
    private static function readJson(Request $request): array
    {
        $raw = (string) $request->rawBody();
        if ($raw === '') {
            return [];
        }
        $data = json_decode($raw, true);
        return is_array($data) ? $data : [];
    }

    private static function translateCode(string $apiCode): string
    {
        return match ($apiCode) {
            'NOT_FOUND' => ApiResponse::NOT_FOUND,
            'VALIDATION_FAILED' => ApiResponse::VALIDATION_FAILED,
            default => ApiResponse::INTERNAL,
        };
    }
}