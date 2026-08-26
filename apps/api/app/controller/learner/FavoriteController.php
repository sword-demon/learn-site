<?php
declare(strict_types=1);

namespace App\controller\learner;

use App\service\BusinessException;
use App\support\ApiResponse;
use App\support\Logger;
use support\Request;
use support\think\Db;

/**
 * Learner favorites (Phase 17 / US7 — T088).
 *
 *   GET    /api/learner/v1/me/favorites?page=&limit=
 *   POST   /api/learner/v1/courses/{id}/favorite   (idempotent toggle → on)
 *   DELETE /api/learner/v1/courses/{id}/favorite   (idempotent toggle → off)
 *
 * Data model is intentionally minimal: a (learner_id, course_id) tuple
 * with created_at. No service layer — the invariants are 1 row per
 * pair and we already enforce that with a unique index.
 */
final class FavoriteController
{
    public function index(Request $request): \support\Response
    {
        return $this->wrap(function () use ($request) {
            $learnerId = $this->viewerId($request);
            $page  = max(1, (int) $request->get('page', 1));
            $limit = max(1, min(100, (int) $request->get('limit', 20)));
            $total = (int) Db::name('favorites')->where('learner_id', $learnerId)->count();
            $rows = Db::name('favorites')
                ->alias('f')
                ->join('courses c', 'c.id = f.course_id')
                ->where('f.learner_id', $learnerId)
                ->field('f.course_id, f.created_at, c.title, c.cover_url, c.teacher_name, c.price_mode, c.list_price, c.status')
                ->order('f.id', 'desc')
                ->page($page, $limit)
                ->select()
                ->toArray();
            $items = array_map(static fn($r) => [
                'course_id'    => (int) $r['course_id'],
                'title'        => (string) $r['title'],
                'cover_url'    => $r['cover_url'] ? (string) $r['cover_url'] : null,
                'teacher_name' => (string) $r['teacher_name'],
                'price_mode'   => (string) $r['price_mode'],
                'list_price'   => (float) $r['list_price'],
                'status'       => (string) $r['status'],
                'favorited_at' => (string) $r['created_at'],
            ], is_array($rows) ? $rows : []);
            return [
                'items' => $items,
                'total' => $total,
                'page'  => $page,
                'limit' => $limit,
            ];
        });
    }

    public function add(Request $request, string $courseId): \support\Response
    {
        return $this->wrap(function () use ($request, $courseId) {
            $learnerId = $this->viewerId($request);
            $cid = $this->id($courseId);
            $exists = Db::name('courses')
                ->where('id', $cid)
                ->where('status', 'published')
                ->find();
            if (!$exists) {
                throw new BusinessException('NOT_FOUND', 'COURSE_NOT_FOUND');
            }
            $now = date('Y-m-d H:i:s');
            Db::name('favorites')->insert([
                'learner_id' => $learnerId,
                'course_id'  => $cid,
                'created_at' => $now,
            ]);
            return ['course_id' => $cid, 'favorited' => true];
        });
    }

    public function remove(Request $request, string $courseId): \support\Response
    {
        return $this->wrap(function () use ($request, $courseId) {
            $learnerId = $this->viewerId($request);
            $cid = $this->id($courseId);
            Db::name('favorites')
                ->where('learner_id', $learnerId)
                ->where('course_id', $cid)
                ->delete();
            return ['course_id' => $cid, 'favorited' => false];
        });
    }

    // ─── helpers ─────────────────────────────────────────────────────

    private function viewerId(Request $request): int
    {
        $v = (int) ($request->account_id ?? 0);
        if ($v <= 0) {
            throw new BusinessException('UNAUTHENTICATED', 'UNAUTHENTICATED');
        }
        return $v;
    }

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
            Logger::error('favorite.learner.failed', ['err' => $e->getMessage()]);
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