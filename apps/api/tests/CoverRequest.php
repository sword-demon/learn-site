<?php

declare(strict_types=1);

namespace Tests;

use Webman\Http\Request;
use Webman\Http\UploadFile;

final class CoverRequest extends Request
{
    public function __construct(private readonly ?UploadFile $upload)
    {
        parent::__construct("POST /api/admin/v1/course-covers HTTP/1.1\r\nHost: test\r\n\r\n");
    }

    public function file(?string $name = null): array|null|UploadFile
    {
        return $this->upload;
    }
}
