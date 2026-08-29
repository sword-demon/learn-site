<?php
declare(strict_types=1);

namespace App\controller\admin;

use App\service\BusinessException;
use App\service\CourseService;
use App\support\ApiResponse;
use App\support\Logger;
use support\Request;

/**
 * Admin course + chapter + lesson CRUD (Phase 4 / US2).
 *
 * The controller is thin: parse input, hand to CourseService, map
 * BusinessException → envelope, otherwise return OK with the service's
 * shaped payload.
 */
final class CourseController
{
    public function __construct(
        private readonly CourseService $service,
    ) {}

    public function index(Request $request): \support\Response
    {
        return $this->wrap(fn() => $this->service->listForAdmin(
            (int) ($request->account_id ?? 0),
            [
                'status'      => $request->get('status'),
                'category_id' => $request->get('category_id'),
                'q'           => $request->get('q'),
                'page'        => (int) $request->get('page', 1),
                'limit'       => (int) $request->get('limit', 20),
            ],
        ));
    }

    public function show(Request $request, string $id): \support\Response
    {
        return $this->wrap(fn() => $this->service->getCourseTree($this->id($id)));
    }

    public function create(Request $request): \support\Response
    {
        return $this->wrap(fn() => $this->service->createCourse(
            self::readJson($request),
            (int) ($request->account_id ?? 0),
        ));
    }

    public function update(Request $request, string $id): \support\Response
    {
        return $this->wrap(fn() => $this->service->updateCourse(
            $this->id($id),
            self::readJson($request),
            (int) ($request->account_id ?? 0),
        ));
    }

    public function publish(Request $request, string $id): \support\Response
    {
        return $this->wrap(fn() => $this->service->publishCourse($this->id($id)));
    }

    public function unpublish(Request $request, string $id): \support\Response
    {
        return $this->wrap(fn() => $this->service->unpublishCourse($this->id($id)));
    }

    public function destroy(Request $request, string $id): \support\Response
    {
        return $this->wrap(function () use ($request, $id) {
            $this->service->deleteCourse(
                $this->id($id),
                (int) ($request->account_id ?? 0),
            );

            return ['deleted' => true];
        });
    }

    // ─── Chapters ─────────────────────────────────────────────────────

    public function listChapters(Request $request, string $id): \support\Response
    {
        return $this->wrap(function () use ($id) {
            $tree = $this->service->getCourseTree($this->id($id));
            return ['items' => $tree['chapters'] ?? []];
        });
    }

    public function createChapter(Request $request, string $id): \support\Response
    {
        return $this->wrap(fn() => $this->service->createChapter($this->id($id), self::readJson($request)));
    }

    public function updateChapter(Request $request, string $id, string $chapterId): \support\Response
    {
        return $this->wrap(fn() => $this->service->updateChapter($this->id($chapterId), self::readJson($request)));
    }

    public function deleteChapter(Request $request, string $id, string $chapterId): \support\Response
    {
        return $this->wrap(function () use ($chapterId) {
            $this->service->deleteChapter($this->id($chapterId));
            return ['deleted' => true];
        });
    }

    // ─── Lessons ──────────────────────────────────────────────────────

    public function listLessons(Request $request, string $id): \support\Response
    {
        return $this->wrap(function () use ($request, $id) {
            $tree = $this->service->getCourseTree($this->id($id));
            $chapterId = (int) $request->get('chapter_id', 0);
            $all = [];
            foreach ($tree['chapters'] ?? [] as $ch) {
                if ($chapterId > 0 && (int) $ch['id'] !== $chapterId) {
                    continue;
                }
                foreach ($ch['lessons'] ?? [] as $ls) {
                    $ls['chapter_id'] = (int) $ch['id'];
                    $all[] = $ls;
                }
            }
            return ['items' => $all];
        });
    }

    public function createLesson(Request $request, string $id): \support\Response
    {
        return $this->wrap(fn() => $this->service->createLesson($this->id($id), self::readJson($request)));
    }

    public function updateLesson(Request $request, string $id, string $lessonId): \support\Response
    {
        return $this->wrap(fn() => $this->service->updateLesson($this->id($lessonId), self::readJson($request)));
    }

    public function deleteLesson(Request $request, string $id, string $lessonId): \support\Response
    {
        return $this->wrap(function () use ($lessonId) {
            $this->service->deleteLesson($this->id($lessonId));
            return ['deleted' => true];
        });
    }

    // ─── Helpers ──────────────────────────────────────────────────────

    private function wrap(callable $fn): \support\Response
    {
        try {
            return ApiResponse::ok($fn());
        } catch (BusinessException $e) {
            return ApiResponse::fail(
                $this->mapApiCode($e->apiCode),
                $e->getMessage(),
                request()->request_id ?? null,
            );
        } catch (\Throwable $e) {
            Logger::error('course.controller.failed', [
                'err' => $e->getMessage(),
                'class' => $e::class,
            ]);
            return ApiResponse::fail(ApiResponse::INTERNAL, 'INTERNAL', request()->request_id ?? null);
        }
    }

    private function mapApiCode(string $code): string
    {
        return match ($code) {
            'NOT_FOUND'       => ApiResponse::NOT_FOUND,
            'CONFLICT'        => ApiResponse::CONFLICT,
            'CATEGORY_IN_USE' => ApiResponse::CATEGORY_IN_USE,
            'FORBIDDEN'       => ApiResponse::FORBIDDEN,
            default           => ApiResponse::VALIDATION_FAILED,
        };
    }

    private function id(string $raw): int
    {
        if (!ctype_digit($raw)) {
            throw new BusinessException('NOT_FOUND', 'INVALID_ID');
        }
        $n = (int) $raw;
        if ($n <= 0) {
            throw new BusinessException('NOT_FOUND', 'INVALID_ID');
        }
        return $n;
    }

    /** @return array<string, mixed> */
    private static function readJson(Request $request): array
    {
        $raw = (string) $request->rawBody();
        if ($raw === '') {
            return [];
        }
        $data = json_decode($raw, true);
        return is_array($data) ? $data : [];
    }
}
