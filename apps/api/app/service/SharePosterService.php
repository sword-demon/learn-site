<?php

declare(strict_types=1);

namespace App\service;

use Closure;
use support\think\Db;

final class SharePosterService
{
    /** @param null|Closure(array<string,mixed>):bool $renderer */
    public function __construct(private readonly ?Closure $renderer = null)
    {
    }

    /** @return array{course_id:int,share_url:string} */
    public function createShareLink(int $courseId): array
    {
        $course = $this->publishedCourse($courseId);

        return [
            'course_id' => (int) $course['id'],
            'share_url' => '/courses/' . $course['id'],
        ];
    }

    /**
     * @return array{
     *   poster_id:int|null,
     *   token:string|null,
     *   share_url:string,
     *   render_status:'ready'|'failed',
     *   snapshot:array{cover_url:string|null,title:string,teacher_name:string,price_label:string}
     * }
     */
    public function createPoster(int $courseId): array
    {
        $course = $this->publishedCourse($courseId);
        $shareUrl = '/courses/' . $courseId;
        $price = $this->currentPrice($course);
        $snapshot = [
            'cover_url' => $course['cover_url'] !== null ? (string) $course['cover_url'] : null,
            'title' => (string) $course['title'],
            'teacher_name' => (string) $course['teacher_name'],
            'price_label' => $course['price_mode'] === 'free' ? '免费' : sprintf('¥%.2f', $price),
        ];

        $rendered = true;
        try {
            if ($this->renderer !== null) {
                $rendered = (bool) ($this->renderer)($snapshot);
            }
        } catch (\Throwable) {
            $rendered = false;
        }

        $token = bin2hex(random_bytes(16));
        $status = $rendered ? 'ready' : 'failed';
        try {
            $posterId = (int) Db::name('share_posters')->insertGetId([
                'course_id' => $courseId,
                'token' => $token,
                'cover_url' => $snapshot['cover_url'],
                'title_snapshot' => $snapshot['title'],
                'teacher_snapshot' => $snapshot['teacher_name'],
                'price_snapshot' => $price,
                'render_status' => $status,
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable) {
            $posterId = null;
            $token = null;
            $status = 'failed';
        }

        return [
            'poster_id' => $posterId,
            'token' => $token,
            'share_url' => $shareUrl,
            'render_status' => $status,
            'snapshot' => $snapshot,
        ];
    }

    /** @return array<string,mixed> */
    private function publishedCourse(int $courseId): array
    {
        $course = Db::name('courses')
            ->where('id', $courseId)
            ->where('status', 'published')
            ->field('id, title, teacher_name, price_mode, list_price, sale_price, sale_start_at, sale_end_at, cover_url')
            ->find();
        if (!$course) {
            throw new BusinessException('NOT_FOUND', 'COURSE_NOT_FOUND');
        }
        return $course;
    }

    /** @param array<string,mixed> $course */
    private function currentPrice(array $course): float
    {
        if (($course['price_mode'] ?? null) === 'free') {
            return 0.0;
        }

        $listPrice = (float) ($course['list_price'] ?? 0);
        $salePrice = (float) ($course['sale_price'] ?? 0);
        $start = isset($course['sale_start_at']) ? strtotime((string) $course['sale_start_at']) : false;
        $end = isset($course['sale_end_at']) ? strtotime((string) $course['sale_end_at']) : false;
        $now = time();
        if ($salePrice > 0 && $start !== false && $end !== false && $start <= $now && $now < $end) {
            return $salePrice;
        }
        return $listPrice;
    }
}
