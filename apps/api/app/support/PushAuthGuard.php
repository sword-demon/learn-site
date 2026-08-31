<?php

declare(strict_types=1);

namespace App\support;

use App\service\PushNotificationService;
use App\service\TokenService;

final class PushAuthGuard
{
    public function __construct(
        private readonly TokenService $tokens = new TokenService(),
        private readonly PushNotificationService $push = new PushNotificationService(),
    ) {
    }

    /**
     * @return array{account_id: int, channel_name: string}|null
     */
    public function authorize(string $authorizationHeader, ?string $accessToken, string $channelName): ?array
    {
        $token = $this->extractAccessToken($authorizationHeader, $accessToken);
        if ($token === null) {
            return null;
        }

        $info = $this->tokens->verifyAccess($token);
        if ($info === null || $info['kind'] !== TokenService::KIND_LEARNER) {
            return null;
        }

        $learnerId = $this->push->learnerIdFromChannel($channelName);
        $accountId = (int) $info['account_id'];
        if ($learnerId === null || $learnerId !== $accountId) {
            return null;
        }

        return [
            'account_id' => $accountId,
            'channel_name' => $channelName,
        ];
    }

    private function extractAccessToken(string $authorizationHeader, ?string $accessToken): ?string
    {
        if (preg_match('/^Bearer\s+(.+)$/i', $authorizationHeader, $matches) === 1) {
            $token = trim($matches[1]);
            return $token !== '' ? $token : null;
        }

        $token = trim((string) $accessToken);
        return $token !== '' ? $token : null;
    }
}
