<?php

declare(strict_types=1);

namespace App\service;

use App\support\Crypto\AesGcmCipher;
use support\think\Db;

/**
 * PaymentConfigService — reads/writes Z-Pay merchant configuration.
 * 
 * Key responsibilities:
 *   - Load active config from payment_config (singleton id=1)
 *   - Encrypt plaintext merchant_key before persisting
 *   - Return masked version of merchant_key in API responses
 *   - Whitelist check for payment access control
 */
final class PaymentConfigService
{
    /** @var array<string, mixed>|null */
    private ?array $active = null;
    private string $keyB64;
    private int $cacheTtl = 60;
    private int $cacheAt = 0;

    public function __construct()
    {
        $this->keyB64 = (string) getenv('PAYMENT_KEY_ENC_KEY');
    }

    /**
     * Get the active payment configuration.
     * Returns PaymentConfig shape with merchant_key_masked (never plaintext).
     */
    /** @return array<string, mixed>|null */
    public function getActive(): ?array
    {
        if ($this->active !== null && time() - $this->cacheAt < $this->cacheTtl) {
            return $this->active;
        }

        $row = Db::name('payment_config')->where('id', 1)->find();
        if (!$row || empty($row['pid'])) {
            return null;
        }

        $merchantKey = $this->decryptKey((string) $row['merchant_key_cipher']);
        $channels = json_decode((string) $row['enabled_channels'], true);
        if (!is_array($channels)) {
            $channels = [];
        }

        $this->active = [
            'enabled' => (bool) $row['enabled'],
            'api_url' => $this->normalizeApiUrl((string) $row['api_url']),
            'pid' => (string)$row['pid'],
            'merchant_key_masked' => $this->maskKey($merchantKey),
            'notify_url' => (string)$row['notify_url'],
            'return_url' => (string)$row['return_url'],
            'enabled_channels' => array_values($channels),
            'whitelist_only' => (bool)$row['whitelist_only'],
            'version' => (int) ($row['version'] ?? 1),
            'updated_at' => $this->iso8601($row['updated_at'] ?? null),
        ];

        $this->cacheAt = time();
        return $this->active;
    }

    /**
     * Update payment configuration with encrypted merchant_key.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function update(int $staffId, array $input): array
    {
        $this->requireEncryptionKey();
        // Validate required fields
        foreach (['enabled', 'api_url', 'pid', 'merchant_key', 'notify_url', 'return_url', 'enabled_channels', 'whitelist_only'] as $k) {
            if (!array_key_exists($k, $input)) {
                throw new BusinessException('VALIDATION_FAILED', "MISSING_FIELD:$k");
            }
        }

        $plainKey = trim((string) $input['merchant_key']);
        if ($plainKey !== '' && (strlen($plainKey) < 8 || strlen($plainKey) > 64)) {
            throw new BusinessException('VALIDATION_FAILED', 'INVALID_MERCHANT_KEY_VALUE');
        }
        $expectedVersion = $input['version'] ?? null;
        if ($expectedVersion !== null && (!is_int($expectedVersion) || $expectedVersion <= 0)) {
            throw new BusinessException('VALIDATION_FAILED', 'INVALID_VERSION');
        }
        $now = date('Y-m-d H:i:s');

        Db::transaction(function () use ($plainKey, $now, $input, $staffId, $expectedVersion): void {
            $existing = Db::name('payment_config')->where('id', 1)->lock(true)->find();
            $currentVersion = (int) ($existing['version'] ?? 1);
            if ($expectedVersion !== null && ($existing === null || $expectedVersion !== $currentVersion)) {
                throw new BusinessException('CONFLICT', 'PAYMENT_CONFIG_VERSION_CONFLICT');
            }
            if ($plainKey === '') {
                if (!is_array($existing) || !isset($existing['merchant_key_cipher'])) {
                    throw new BusinessException('VALIDATION_FAILED', 'INVALID_MERCHANT_KEY_VALUE');
                }
                $cipher = (string) $existing['merchant_key_cipher'];
            } else {
                try {
                    $cipher = AesGcmCipher::encrypt($plainKey, $this->keyB64);
                } catch (BusinessException) {
                    throw new BusinessException('INTERNAL', 'ENCRYPTION_FAILED');
                }
            }
            $data = [
                'enabled' => (bool)$input['enabled'],
                'api_url' => $this->normalizeApiUrl((string) $input['api_url']),
                'pid' => (string)$input['pid'],
                'merchant_key_cipher' => $cipher,
                'notify_url' => (string)$input['notify_url'],
                'return_url' => (string)$input['return_url'],
                'enabled_channels' => json_encode(array_values($input['enabled_channels']), JSON_THROW_ON_ERROR),
                'whitelist_only' => (bool)$input['whitelist_only'],
                'version' => $existing ? $currentVersion + 1 : 1,
                'updated_by_staff_id' => $staffId,
                'updated_at' => $now,
            ];

            if ($existing) {
                Db::name('payment_config')->where('id', 1)->update($data);
            } else {
                $data['id'] = 1;
                $data['created_at'] = $now;
                Db::name('payment_config')->insert($data);
            }
            $this->writeAudit($staffId, 'payment_config.update', 1, [
                'enabled' => (bool) $input['enabled'],
                'pid' => (string) $input['pid'],
                'enabled_channels' => array_values($input['enabled_channels']),
                'whitelist_only' => (bool) $input['whitelist_only'],
            ]);
        });

        $this->active = null; // Invalidate cache
        $updated = $this->getActive();
        if ($updated === null) {
            throw new BusinessException('INTERNAL', 'PAYMENT_CONFIG_SAVE_FAILED');
        }
        return $updated;
    }

    /**
     * Check if whitelist mode is enabled.
     */
    public function isWhitelistOnly(): bool
    {
        $config = $this->getActive();
        return $config !== null && (bool)$config['whitelist_only'];
    }

    public function merchantKey(): string
    {
        $row = Db::name('payment_config')->where('id', 1)->find();
        if (!is_array($row) || !isset($row['merchant_key_cipher'])) {
            throw new BusinessException('CONFLICT', 'PAYMENT_NOT_CONFIGURED');
        }
        return $this->decryptKey((string) $row['merchant_key_cipher']);
    }

    /**
     * Mask cipher for display (last 4 chars visible).
     */
    private function maskKey(string $plain): string
    {
        return str_repeat('*', 8) . substr($plain, -4);
    }

    private function decryptKey(string $cipher): string
    {
        try {
            return AesGcmCipher::decrypt($cipher, $this->keyB64);
        } catch (BusinessException) {
            throw new BusinessException('INTERNAL', 'PAYMENT_KEY_DECRYPTION_FAILED');
        }
    }

    private function requireEncryptionKey(): void
    {
        if ($this->keyB64 === '') {
            throw new BusinessException('INTERNAL', 'PAYMENT_KEY_ENC_KEY_NOT_CONFIGURED');
        }
    }

    private function normalizeApiUrl(string $url): string
    {
        return rtrim(trim($url), '/') . '/';
    }

    private function iso8601(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        try {
            return (new \DateTimeImmutable((string) $value, new \DateTimeZone('UTC')))->format(DATE_ATOM);
        } catch (\Exception) {
            return null;
        }
    }

    /** @param array<string, mixed> $payload */
    private function writeAudit(int $staffId, string $action, int $targetId, array $payload): void
    {
        Db::name('audit_log')->insert([
            'actor_id' => $staffId,
            'action' => $action,
            'target_type' => 'payment_config',
            'target_id' => $targetId,
            'payload_json' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }
}
