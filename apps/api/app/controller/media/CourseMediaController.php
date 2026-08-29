<?php

declare(strict_types=1);

namespace App\controller\media;

use App\service\EntitlementService;
use App\support\ApiResponse;
use App\support\storage\AssetStorage;
use support\Request;
use support\think\Db;

/** Authenticated delivery for PDF/video lesson assets. */
final class CourseMediaController
{
    public function __construct(
        private readonly AssetStorage $storage,
        private readonly EntitlementService $entitlements,
    ) {
    }

    public function show(Request $request, string $id): \support\Response
    {
        if (!ctype_digit($id) || (int) $id <= 0) {
            return ApiResponse::fail(ApiResponse::VALIDATION_FAILED, 'ASSET_ID_INVALID');
        }

        $row = Db::name('assets')
            ->alias('a')
            ->join('lessons l', 'l.asset_id = a.id')
            ->join('chapters ch', 'ch.id = l.chapter_id')
            ->join('courses c', 'c.id = ch.course_id')
            ->field('a.id, a.storage_path, a.mime_type, a.status, l.is_preview, l.status AS lesson_status, c.id AS course_id, c.status AS course_status')
            ->where('a.id', (int) $id)
            ->find();
        if (!is_array($row) || (string) $row['lesson_status'] !== 'enabled') {
            return ApiResponse::fail(ApiResponse::NOT_FOUND, 'ASSET_NOT_FOUND');
        }

        $isPreview = (int) ($row['is_preview'] ?? 0) === 1;
        $learnerId = (int) ($request->account_id ?? 0);
        $authorized = $learnerId > 0
            && $this->entitlements->viewerAuthorized((int) $row['course_id'], $learnerId);
        if ((string) $row['course_status'] !== 'published' && !$authorized) {
            return ApiResponse::fail(ApiResponse::NOT_FOUND, 'ASSET_NOT_FOUND');
        }
        if (!$isPreview && !$authorized) {
            return ApiResponse::fail(ApiResponse::FORBIDDEN, 'ASSET_LOCKED');
        }
        if ((string) $row['status'] !== 'ready') {
            return ApiResponse::fail(ApiResponse::NOT_FOUND, 'ASSET_UNAVAILABLE');
        }

        $resolved = $this->storage->resolve((string) $row['storage_path']);
        if ($resolved === null) {
            return ApiResponse::fail(ApiResponse::NOT_FOUND, 'ASSET_NOT_FOUND');
        }
        return response()->file($resolved['path'])->header('Content-Type', (string) $row['mime_type']);
    }
}
