<?php

declare(strict_types=1);

namespace App\queue\redis;

use App\service\PushNotificationService;
use Webman\RedisQueue\Consumer;

final class PushNotificationConsumer implements Consumer
{
    public string $queue = 'notification.push';

    public string $connection = 'default';

    public function consume($data): void
    {
        if (!is_array($data)) {
            return;
        }
        $learnerId = (int) ($data['learner_id'] ?? 0);
        $notificationId = (int) ($data['notification_id'] ?? 0);
        $kind = (string) ($data['kind'] ?? '');
        $title = (string) ($data['title'] ?? '');
        $unread = isset($data['unread_count']) ? (int) $data['unread_count'] : null;
        (new PushNotificationService())->triggerNotification(
            $learnerId,
            $notificationId,
            $kind,
            $title,
            $unread,
        );
    }
}
