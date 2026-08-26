<?php
declare(strict_types=1);

namespace App\controller\learner;

use App\service\HomeService;
use App\support\ApiResponse;
use support\Request;

final class HomeController
{
    public function __construct(private readonly HomeService $home) {}

    public function home(Request $request): \support\Response
    {
        return ApiResponse::ok([
            'categories' => $this->home->categoryTree(),
            'site_intro' => $this->home->siteIntro(),
        ], $request->request_id ?? null);
    }
}
