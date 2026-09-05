<?php

declare(strict_types=1);

namespace App\controller\learner;

use App\service\BusinessException;
use App\service\LearningActionService;
use App\support\ApiResponse;
use support\Request;

final class LearningActionController
{
    public function __construct(private readonly LearningActionService $actions = new LearningActionService())
    {
    }

    public function index(Request $request): \support\Response
    {
        $response = null;
        try {
            $learnerId = (int) ($request->account_id ?? 0);
            if ($learnerId <= 0) {
                $response = ApiResponse::fail(ApiResponse::UNAUTHENTICATED, 'UNAUTHENTICATED');
            } else {
                $response = ApiResponse::ok($this->actions->nextAction($learnerId), $request->request_id ?? null);
            }
        } catch (BusinessException $e) {
            $response = ApiResponse::fail($e->apiCode, $e->getMessage());
        } catch (\Throwable) {
            $response = ApiResponse::fail(ApiResponse::INTERNAL, 'INTERNAL');
        }
        return $response->withHeader('Cache-Control', 'private, no-store');
    }
}
