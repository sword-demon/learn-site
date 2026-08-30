<?php

declare(strict_types=1);

namespace App\controller\media;

use App\support\ApiResponse;
use App\support\storage\ImageStorage;

final class CourseCoverMediaController
{
    public function __construct(private readonly ImageStorage $storage)
    {
    }

    public function show(string $key): \support\Response
    {
        $resolved = $this->storage->resolve($key);
        if ($resolved === null) {
            return ApiResponse::fail(ApiResponse::NOT_FOUND, 'COVER_NOT_FOUND');
        }

        return response()->file($resolved['path'])->header('Content-Type', $resolved['mime_type']);
    }
}
