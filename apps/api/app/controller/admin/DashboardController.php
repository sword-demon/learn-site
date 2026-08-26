<?php
declare(strict_types=1);

namespace App\controller\admin;

use App\service\DashboardService;
use App\support\ApiResponse;
use support\Request;

final class DashboardController
{
    public function __construct(private readonly DashboardService $dashboard) {}

    public function summary(Request $request): \support\Response
    {
        $staffAccountId = (int) ($request->account_id ?? 0);
        if ($staffAccountId <= 0) {
            return ApiResponse::fail(ApiResponse::UNAUTHENTICATED, 'UNAUTHENTICATED', $request->request_id ?? null);
        }
        return ApiResponse::ok(
            $this->dashboard->summary($staffAccountId, $request->permissions ?? []),
            $request->request_id ?? null,
        );
    }
}
