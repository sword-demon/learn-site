<?php

declare(strict_types=1);

namespace App\controller\learner;

use App\service\HomeService;
use App\service\PublicCatalogService;
use App\support\ApiResponse;
use support\Request;

final class HomeController
{
    public function __construct(
        private readonly HomeService $home,
        private readonly PublicCatalogService $catalog,
    ) {
    }

    public function home(Request $request): \support\Response
    {
        return ApiResponse::ok([
            'categories' => $this->home->categoryTree(),
            'site_intro' => $this->home->siteIntro(),
            'recent_courses' => $this->catalog->recentPublishedCourses(12),
            'banners' => $this->home->banners(),
            'recommended_maps' => $this->home->recommendedMaps(3),
        ], $request->request_id ?? null);
    }
}
