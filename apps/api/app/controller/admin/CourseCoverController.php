<?php

declare(strict_types=1);

namespace App\controller\admin;

use App\support\ApiResponse;
use App\support\storage\ImageStorage;
use Throwable;
use Webman\Http\Request;
use Webman\Http\UploadFile;

class CourseCoverController
{
    private const MAX_BYTES = 5 * 1024 * 1024;
    protected const ERROR_PREFIX = 'COVER';
    /** @var array<string, string[]> */
    private const MIME_EXTENSIONS = [
        'image/jpeg' => ['jpg', 'jpeg'],
        'image/png' => ['png'],
        'image/webp' => ['webp'],
    ];

    public function __construct(private readonly ImageStorage $storage)
    {
    }

    public function upload(Request $request): \support\Response
    {
        return $this->uploadImage($request);
    }

    protected function uploadImage(Request $request): \support\Response
    {
        $file = $request->file('file');
        if (!$file instanceof UploadFile || !$file->isValid()) {
            return ApiResponse::fail(
                ApiResponse::VALIDATION_FAILED,
                static::ERROR_PREFIX . '_FILE_REQUIRED',
                $request->request_id ?? null,
            );
        }

        $size = (int) $file->getSize();
        $envName = static::ERROR_PREFIX . '_MAX_BYTES';
        $maxBytes = max(1, (int) (getenv($envName) ?: self::MAX_BYTES));
        if ($size <= 0 || $size > $maxBytes) {
            return ApiResponse::fail(
                ApiResponse::VALIDATION_FAILED,
                static::ERROR_PREFIX . '_SIZE_INVALID',
                $request->request_id ?? null,
            );
        }

        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($file->getPathname());
        if (!is_string($mime) || !array_key_exists($mime, self::MIME_EXTENSIONS)) {
            return ApiResponse::fail(
                ApiResponse::VALIDATION_FAILED,
                static::ERROR_PREFIX . '_MIME_INVALID',
                $request->request_id ?? null,
            );
        }
        $extension = strtolower($file->getUploadExtension());
        if (!in_array($extension, self::MIME_EXTENSIONS[$mime], true)) {
            return ApiResponse::fail(
                ApiResponse::VALIDATION_FAILED,
                static::ERROR_PREFIX . '_EXTENSION_INVALID',
                $request->request_id ?? null,
            );
        }

        try {
            return ApiResponse::ok($this->storage->store($file, $mime, $extension), $request->request_id ?? null);
        } catch (Throwable) {
            return ApiResponse::fail(
                ApiResponse::INTERNAL,
                static::ERROR_PREFIX . '_STORE_FAILED',
                $request->request_id ?? null,
            );
        }
    }
}
