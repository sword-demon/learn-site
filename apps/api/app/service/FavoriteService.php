<?php

declare(strict_types=1);

namespace App\service;

use support\think\Db;

final class FavoriteService
{
    /**
     * @return array{items:list<array<string,mixed>>,total:int,page:int,limit:int}
     */
    public function list(int $learnerId, int $page = 1, int $limit = 20): array
    {
        $page = max(1, $page);
        $limit = min(100, max(1, $limit));
        $query = Db::name('favorites')->where('learner_id', $learnerId);
        $total = (int) (clone $query)->count();
        $rows = $query
            ->alias('f')
            ->join('courses c', 'c.id = f.course_id')
            ->field('f.course_id, f.created_at, c.title, c.cover_url, c.teacher_name, c.price_mode, c.list_price, c.status')
            ->order('f.id', 'desc')
            ->page($page, $limit)
            ->select()
            ->toArray();

        return [
            'items' => array_map(static fn(array $row): array => [
                'course_id' => (int) $row['course_id'],
                'title' => (string) $row['title'],
                'cover_url' => $row['cover_url'] !== null ? (string) $row['cover_url'] : null,
                'teacher_name' => (string) $row['teacher_name'],
                'price_mode' => (string) $row['price_mode'],
                'list_price' => (float) $row['list_price'],
                'status' => (string) $row['status'],
                'favorited_at' => (string) $row['created_at'],
            ], is_array($rows) ? $rows : []),
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
        ];
    }

    /** Returns true only when a new favorite row was created. */
    public function add(int $learnerId, int $courseId): bool
    {
        $published = Db::name('courses')
            ->where('id', $courseId)
            ->where('status', 'published')
            ->find();
        if (!$published) {
            throw new BusinessException('NOT_FOUND', 'COURSE_NOT_FOUND');
        }

        try {
            Db::name('favorites')->insert([
                'learner_id' => $learnerId,
                'course_id' => $courseId,
                'created_at' => date('Y-m-d H:i:s'),
            ]);
            return true;
        } catch (\Throwable $exception) {
            $exists = Db::name('favorites')
                ->where('learner_id', $learnerId)
                ->where('course_id', $courseId)
                ->find();
            if ($exists) {
                return false;
            }
            throw $exception;
        }
    }

    /** Returns true only when an existing favorite row was removed. */
    public function remove(int $learnerId, int $courseId): bool
    {
        return (int) Db::name('favorites')
            ->where('learner_id', $learnerId)
            ->where('course_id', $courseId)
            ->delete() > 0;
    }
}
