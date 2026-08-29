<?php

declare(strict_types=1);

namespace App\support\storage;

use Webman\Http\UploadFile;

interface AssetStorage
{
    /** @return array{storage_path: string, mime_type: string, size_bytes: int} */
    public function store(UploadFile $file, string $kind, string $mime, string $extension): array;

    /** @return array{path: string, mime_type: string}|null */
    public function resolve(string $storagePath): ?array;
}
