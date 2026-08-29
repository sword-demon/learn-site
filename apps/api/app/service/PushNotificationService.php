<?php

declare(strict_types=1);

namespace App\service;

use App\support\Logger;
use support\think\Db;
use Webman\Push\Api;

/**
 * Real-time learner inbox push via webman/push private channels.
 */
final class PushNotificationService
{
    private const CHANNEL_PREFIX = 'private-learner-';

    public function notifyLearner(int $learnerId, int $notificationId, string $kind, string $title): void
    {
        if ($learnerId <= 0 || $notificationId <= 0) {
            return;
        }
        try {
            $unread = (int) Db::name('learner_notifications')
                ->where('learner_id', $learnerId)
                ->whereNull('read_at')
                ->count();
            $this->api()->trigger(
                self::CHANNEL_PREFIX . $learnerId,
                'notification',
                [
                    'id' => $notificationId,
                    'kind' => $kind,
                    'title' => $title,
                    'unread_count' => $unread,
                ],
            );
        } catch (\Throwable $e) {
            Logger::warning('push.notify_failed', [
                'learner_id' => $learnerId,
                'notification_id' => $notificationId,
                'err' => $e->getMessage(),
            ]);
        }
    }

    public function channelForLearner(int $learnerId): string
    {
        return self::CHANNEL_PREFIX . $learnerId;
    }

    public function learnerIdFromChannel(string $channelName): ?int
    {
        if (!str_starts_with($channelName, self::CHANNEL_PREFIX)) {
            return null;
        }
        $id = substr($channelName, strlen(self::CHANNEL_PREFIX));
        if ($id === '' || !ctype_digit($id)) {
            return null;
        }
        return (int) $id;
    }

    private function api(): Api
    {
        $apiUrl = (string) config('plugin.webman.push.app.api', 'http://127.0.0.1:3232');
        $apiUrl = str_replace('0.0.0.0', '127.0.0.1', $apiUrl);
        return new Api(
            $apiUrl,
            (string) config('plugin.webman.push.app.app_key'),
            (string) config('plugin.webman.push.app.app_secret'),
        );
    }
}
