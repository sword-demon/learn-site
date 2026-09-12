<?php

declare(strict_types=1);

namespace App\service;

use support\think\Db;

final class ApiRequestAuditService
{
    /**
     * @return array{items: list<array<string, mixed>>, total: int, page: int, limit: int}
     */
    public function list(int $page = 1, int $limit = 20): array
    {
        $page = max(1, $page);
        $limit = 20;
        $query = Db::name('api_request_audit_log');
        $total = (int) (clone $query)->count();
        $rows = $query->order('created_at', 'desc')->order('id', 'desc')->page($page, $limit)->select()->toArray();
        return ['items' => $rows, 'total' => $total, 'page' => $page, 'limit' => $limit];
    }
}
