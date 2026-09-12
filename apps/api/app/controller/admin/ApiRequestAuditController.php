<?php

declare(strict_types=1);

namespace App\controller\admin;

use App\service\ApiRequestAuditService;
use App\support\ApiResponse;
use support\Request;

final class ApiRequestAuditController
{
    public function __construct(private readonly ApiRequestAuditService $audits = new ApiRequestAuditService()) {}

    public function index(Request $request): \support\Response
    {
        return ApiResponse::ok($this->audits->list((int) $request->get('page', 1), 20), $request->request_id ?? null);
    }
}
