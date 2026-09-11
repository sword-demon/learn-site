<?php

declare(strict_types=1);

namespace App\service;

use support\think\Db;

final class CommissionReplayService
{
    /** @return array{order_id: int, levels: list<array<string, mixed>>} */
    public function replay(int $orderId): array
    {
        $rows = Db::name('commission_records')->where('order_id', $orderId)->order('level', 'asc')->select()->toArray();
        if ($rows === []) {
            throw new BusinessException('NOT_FOUND', 'ORDER_COMMISSION_NOT_FOUND');
        }
        $first = $rows[0];
        $cfg = json_decode((string) $first['config_snapshot_json'], true);
        if (!is_array($cfg)) {
            throw new BusinessException('VALIDATION_FAILED', 'SNAPSHOT_CORRUPT');
        }
        $paid = (int) $first['order_paid_cents_snapshot'];
        $matches = [];
        foreach ($rows as $row) {
            $pct = (float) ($cfg['level' . (int) $row['level'] . '_pct'] ?? 0);
            $expected = (int) floor($paid * $pct);
            $matches[] = [
                'level' => (int) $row['level'],
                'stored_cents' => (int) $row['amount_cents'],
                'replayed_cents' => $expected,
                'equal' => (int) $row['amount_cents'] === $expected,
            ];
        }
        return ['order_id' => $orderId, 'levels' => $matches];
    }
}
