<?php

declare(strict_types=1);

namespace App\support\storage;

use Webman\Http\UploadFile;

interface ImageStorage
{
    /** @return array{key: string, url: string, mime_type: string, size_bytes: int} */
    public function store(UploadFile $file, string $mime, string $extension): array;

    /** @return array{path: string, mime_type: string}|null */
    public function resolve(string $key): ?array;
}
