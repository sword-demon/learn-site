<?php
declare(strict_types=1);

namespace App\controller\admin;

use App\model\Asset;
use App\support\ApiResponse;
use App\support\Logger;
use support\Request;
use support\think\Db;

/**
 * Admin asset upload (Phase 4 / US2 — T038).
 *
 * Single endpoint: POST /api/admin/v1/assets
 *
 *   form fields:
 *     file  — the upload (multipart)
 *     kind  — "pdf" | "video"
 *
 * Validation:
 *   - Size limit pulled from env: ASSET_MAX_PDF_BYTES (default 52_428_800,
 *     50 MiB) and ASSET_MAX_VIDEO_BYTES (default 524_288_000, 500 MiB).
 *   - MIME check: pdf → application/pdf; video → video/mp4 | video/quicktime.
 *   - Empty upload → VALIDATION_FAILED.
 *
 * Storage:
 *   runtime/uploads/{Y/m}/{hex}.{ext} — relative path stored in storage_path.
 *   // ponytail: status will become 'processing' once transcode worker lands (Phase 9).
 */
final class AssetController
{
    private const MIME_PDF   = ['application/pdf'];
    private const MIME_VIDEO = ['video/mp4', 'video/quicktime'];

    public function upload(Request $request): \support\Response
    {
        $kind = (string) $request->post('kind', '');
        if (!in_array($kind, ['pdf', 'video'], true)) {
            return ApiResponse::fail(ApiResponse::VALIDATION_FAILED, 'ASSET_KIND_INVALID');
        }
        $file = $request->file('file');
        if ($file === null || !method_exists($file, 'getSize')) {
            return ApiResponse::fail(ApiResponse::VALIDATION_FAILED, 'ASSET_FILE_REQUIRED');
        }
        try {
            $size = (int) $file->getSize();
        } catch (\Throwable) {
            $size = 0;
        }
        $maxBytes = $kind === 'pdf'
            ? (int) (getenv('ASSET_MAX_PDF_BYTES') ?: 52_428_800)
            : (int) (getenv('ASSET_MAX_VIDEO_BYTES') ?: 524_288_000);
        if ($size <= 0 || $size > $maxBytes) {
            return ApiResponse::fail(ApiResponse::VALIDATION_FAILED, 'ASSET_SIZE_INVALID');
        }
        $mime = '';
        try {
            $mime = (string) $file->getUploadMimeType();
        } catch (\Throwable) {
            $mime = '';
        }
        $allowed = $kind === 'pdf' ? self::MIME_PDF : self::MIME_VIDEO;
        if ($mime !== '' && !in_array($mime, $allowed, true)) {
            return ApiResponse::fail(ApiResponse::VALIDATION_FAILED, 'ASSET_MIME_INVALID');
        }
        $ext = $kind === 'pdf' ? 'pdf' : 'mp4';
        $relDir  = 'uploads/' . date('Y/m') . '/';
        $name    = bin2hex(random_bytes(8)) . '.' . $ext;
        $absDir  = runtime_path($relDir);
        $absPath = $absDir . $name;
        if (!is_dir($absDir)) {
            @mkdir($absDir, 0775, true);
        }
        try {
            $file->move($absPath);
        } catch (\Throwable $e) {
            Logger::error('asset.move_failed', ['err' => $e->getMessage()]);
            return ApiResponse::fail(ApiResponse::INTERNAL, 'ASSET_STORE_FAILED');
        }
        if (!is_file($absPath)) {
            return ApiResponse::fail(ApiResponse::INTERNAL, 'ASSET_STORE_FAILED');
        }
        $storagePath = $relDir . $name;
        $now = date('Y-m-d H:i:s');
        $actor = (int) ($request->account_id ?? 0);
        $id = null;
        try {
            $id = (int) Db::transaction(function () use ($kind, $storagePath, $mime, $size, $actor, $now) {
                return Asset::create([
                    'kind'                => $kind,
                    'storage_path'        => $storagePath,
                    'mime_type'           => $mime !== '' ? $mime : ($kind === 'pdf' ? 'application/pdf' : 'video/mp4'),
                    'size_bytes'          => $size,
                    'status'              => 'ready', // ponytail: 'processing' once worker lands (Phase 9)
                    'created_by_staff_id' => $actor,
                    'created_at'          => $now,
                    'updated_at'          => $now,
                ])->id;
            });
        } finally {
            // ponytail: DB tx can roll back AFTER the file hit disk (DB blip
            // between move() and the insert). Without this, runtime/uploads/
            // grows unbounded across the worker's lifetime. Unlink failure
            // is non-fatal — a stale file is cheaper than a 5xx.
            if ($id === null || $id <= 0) {
                @unlink($absPath);
            }
        }
        Logger::info('asset.uploaded', ['id' => $id, 'kind' => $kind, 'size' => $size]);
        return ApiResponse::ok([
            'id'           => $id,
            'kind'         => $kind,
            'storage_path' => $storagePath,
            'mime_type'    => $mime,
            'size_bytes'   => $size,
            'status'       => 'ready',
        ]);
    }
}
