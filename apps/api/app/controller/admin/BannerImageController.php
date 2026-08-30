<?php

declare(strict_types=1);

namespace App\controller\admin;

/** Banner upload reuses the course-cover MIME, extension, and size checks. */
final class BannerImageController extends CourseCoverController
{
    protected const ERROR_PREFIX = 'BANNER';
}
