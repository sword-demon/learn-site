<?php

declare(strict_types=1);

namespace App\service;

use support\think\Db;

/**
 * Retention: delete learner inbox rows older than two calendar months.
 */
final class NotificationCleanupService
{
    private const BATCH_SIZE = 500;

    public function purgeExpired(?int $batchSize = null): int
    {
        $batchSize = max(1, min(2000, $batchSize ?? self::BATCH_SIZE));
        $cutoff = date('Y-m-d H:i:s', strtotime('-2 months'));
        $deleted = 0;

        while (true) {
            $ids = Db::name('learner_notifications')
                ->where('created_at', '<', $cutoff)
                ->order('id', 'asc')
                ->limit($batchSize)
                ->column('id');
            if (!is_array($ids) || $ids === []) {
                break;
            }
            $count = Db::name('learner_notifications')->whereIn('id', $ids)->delete();
            $deleted += (int) $count;
            if (count($ids) < $batchSize) {
                break;
            }
        }

        if ($deleted > 0) {
            Db::name('audit_log')->insert([
                'actor_id' => null,
                'action' => 'notification.cleanup',
                'target_type' => 'learner_notifications',
                'target_id' => null,
                'payload_json' => json_encode([
                    'deleted' => $deleted,
                    'cutoff' => $cutoff,
                ], JSON_UNESCAPED_UNICODE),
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        }

        return $deleted;
    }
}
