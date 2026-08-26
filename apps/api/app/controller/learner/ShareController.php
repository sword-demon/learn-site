<?php
declare(strict_types=1);

namespace App\controller\learner;

use App\service\BusinessException;
use App\support\ApiResponse;
use App\support\Logger;
use support\Request;
use support\think\Db;

/**
 * Learner share — Phase 17 / US7 (T088).
 *
 *   POST /api/learner/v1/courses/{id}/share
 *
 * Returns a tokenised share URL pointing at the public landing page
 * (route: /share/{token}). Poster rendering is a follow-up job that
 * may fill in `cover_url` / `render_status='ready'` later; this
 * endpoint always succeeds once the course exists and is published.
 */
final class ShareController
{
    public function create(Request $request, string $courseId): \support\Response
    {
        return $this->wrap(function () use ($courseId) {
            $cid = $this->id($courseId);
            $course = Db::name('courses')
                ->where('id', $cid)
                ->where('status', 'published')
                ->field('id, title, teacher_name, list_price, cover_url')
                ->find();
            if (!$course) {
                throw new BusinessException('NOT_FOUND', 'COURSE_NOT_FOUND');
            }
            $token = bin2hex(random_bytes(16));
            $now = date('Y-m-d H:i:s');
            Db::name('share_posters')->insert([
                'course_id'        => $cid,
                'token'            => $token,
                'cover_url'        => $course['cover_url'] ?: null,
                'title_snapshot'   => (string) $course['title'],
                'teacher_snapshot' => (string) ($course['teacher_name'] ?? ''),
                'price_snapshot'   => (float) ($course['list_price'] ?? 0),
                'render_status'    => 'pending',
                'created_at'       => $now,
            ]);
            return [
                'token'      => $token,
                'share_url'  => '/share/' . $token,
                'render_status' => 'pending',
            ];
        });
    }

    // ─── helpers ─────────────────────────────────────────────────────

    private function id(string $raw): int
    {
        if (!ctype_digit($raw)) {
            throw new BusinessException('VALIDATION_FAILED', 'INVALID_ID');
        }
        $n = (int) $raw;
        if ($n <= 0) {
            throw new BusinessException('VALIDATION_FAILED', 'INVALID_ID');
        }
        return $n;
    }

    private function wrap(callable $fn): \support\Response
    {
        try {
            return ApiResponse::ok($fn(), request()->request_id ?? null);
        } catch (BusinessException $e) {
            return ApiResponse::fail(
                $this->mapApiCode($e->apiCode),
                $e->getMessage(),
                request()->request_id ?? null,
            );
        } catch (\Throwable $e) {
            Logger::error('share.learner.failed', ['err' => $e->getMessage()]);
            return ApiResponse::fail(ApiResponse::INTERNAL, 'INTERNAL', request()->request_id ?? null);
        }
    }

    private function mapApiCode(string $code): string
    {
        return match ($code) {
            'UNAUTHENTICATED'  => ApiResponse::UNAUTHENTICATED,
            'NOT_FOUND'        => ApiResponse::NOT_FOUND,
            'FORBIDDEN'        => ApiResponse::FORBIDDEN,
            'VALIDATION_FAILED' => ApiResponse::VALIDATION_FAILED,
            'CONFLICT'         => ApiResponse::CONFLICT,
            default            => ApiResponse::VALIDATION_FAILED,
        };
    }
}