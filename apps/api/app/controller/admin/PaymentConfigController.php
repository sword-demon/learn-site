<?php

declare(strict_types=1);

namespace App\controller\admin;

use App\service\BusinessException;
use App\service\PaymentConfigService;
use App\support\ApiResponse;
use App\support\Logger;
use support\Request;

/**
 * PaymentConfigController — admin interface for Z-Pay merchant configuration.
 * 
 * Routes:
 *   GET /api/admin/v1/payment/config — Retrieve current config (masked key)
 *   PATCH /api/admin/v1/payment/config — Update configuration with encrypted key
 *
 * Security:
 *   - AdminAuth middleware enforces staff authentication
 *   - Merchant key encrypted before storage via AES-256-GCM
 *   - API response never exposes plaintext key
 */
final class PaymentConfigController
{
    public function __construct(
        private readonly PaymentConfigService $configService,
    ) {}

    /**
     * Get current payment configuration.
     * Returns masked merchant_key only (never plaintext).
     */
    public function get(Request $request): \support\Response
    {
        return $this->wrap(fn(): ?array => $this->configService->getActive(), $request);
    }

    /**
     * Update payment configuration.
     * Validates input, encrypts merchant_key, returns masked response.
     */
    public function update(Request $request): \support\Response
    {
        $input = self::readJson($request);
        foreach (['enabled', 'api_url', 'pid', 'merchant_key', 'notify_url', 'return_url', 'enabled_channels', 'whitelist_only'] as $field) {
            if (!array_key_exists($field, $input)) {
                return $this->fail('MISSING_FIELD:' . $field, $request);
            }
        }

        foreach (['enabled', 'whitelist_only'] as $booleanField) {
            if (!is_bool($input[$booleanField])) {
                return $this->fail('INVALID_' . strtoupper($booleanField), $request);
            }
        }

        if (array_key_exists('version', $input)
            && (!is_int($input['version']) || $input['version'] <= 0)) {
            return $this->fail('INVALID_VERSION', $request);
        }

        // Validate channels
        $validChannels = ['wxpay', 'alipay'];
        $channels = $input['enabled_channels'];
        if (!is_array($channels) || count($channels) === 0) {
            return $this->fail('INVALID_ENABLED_CHANNELS', $request);
        }
        foreach ($channels as $c) {
            if (!in_array($c, $validChannels, true)) {
                return $this->fail('INVALID_CHANNEL_VALUE', $request);
            }
        }
        $input['enabled_channels'] = array_values(array_unique($channels));

        // Validate merchant configuration
        $pid = $input['pid'];
        if (!is_string($pid) || strlen($pid) < 8 || strlen($pid) > 64) {
            return $this->fail('INVALID_PID', $request);
        }
        $input['pid'] = trim($pid);
        if (strlen($input['pid']) < 8) {
            return $this->fail('INVALID_PID', $request);
        }

        $key = $input['merchant_key'];
        if (!is_string($key) || ($key !== '' && (strlen($key) < 8 || strlen($key) > 64))) {
            return $this->fail('INVALID_MERCHANT_KEY_VALUE', $request);
        }
        $input['merchant_key'] = trim($key);

        // Validate URLs
        foreach (['api_url', 'notify_url', 'return_url'] as $urlField) {
            $url = $input[$urlField];
            if (!is_string($url) || !filter_var($url, FILTER_VALIDATE_URL)) {
                return $this->fail("INVALID_URL:$urlField", $request);
            }
            $url = trim($url);
            if ($urlField === 'api_url'
                && (strtolower((string) parse_url($url, PHP_URL_SCHEME)) !== 'https'
                    || !str_ends_with($url, '/'))) {
                return $this->fail('INVALID_URL:api_url', $request);
            }
            $input[$urlField] = $url;
        }

        try {
            $staffId = (int) ($request->account_id ?? 0);
            if ($staffId <= 0) {
                throw new BusinessException('FORBIDDEN', 'UNAUTHORIZED');
            }

            return $this->wrap(fn() => $this->configService->update($staffId, $input), $request);
        } catch (BusinessException $e) {
            return ApiResponse::fail($this->mapCode($e->apiCode), $e->getMessage(), $request->request_id ?? null);
        } catch (\Throwable $e) {
            Logger::error('payment_config.admin.update_failed', ['err' => $e->getMessage()]);
            return ApiResponse::fail(ApiResponse::INTERNAL, 'INTERNAL', $request->request_id ?? null);
        }
    }

    /** @param callable():mixed $operation */
    private function wrap(callable $operation, Request $request): \support\Response
    {
        try {
            return ApiResponse::ok($operation(), $request->request_id ?? null);
        } catch (BusinessException $exception) {
            return ApiResponse::fail(
                $this->mapCode($exception->apiCode),
                $exception->getMessage(),
                $request->request_id ?? null,
            );
        } catch (\Throwable $exception) {
            Logger::error('payment_config.admin.failed', ['err' => $exception->getMessage()]);
            return ApiResponse::fail(ApiResponse::INTERNAL, 'INTERNAL', $request->request_id ?? null);
        }
    }

    private function fail(string $message, Request $request): \support\Response
    {
        return ApiResponse::fail(ApiResponse::VALIDATION_FAILED, $message, $request->request_id ?? null);
    }

    private function mapCode(string $code): string
    {
        return match ($code) {
            ApiResponse::UNAUTHENTICATED => ApiResponse::UNAUTHENTICATED,
            ApiResponse::FORBIDDEN => ApiResponse::FORBIDDEN,
            ApiResponse::NOT_FOUND => ApiResponse::NOT_FOUND,
            ApiResponse::CONFLICT => ApiResponse::CONFLICT,
            ApiResponse::INTERNAL => ApiResponse::INTERNAL,
            default => ApiResponse::VALIDATION_FAILED,
        };
    }

    /** @return array<string, mixed> */
    private static function readJson(Request $request): array
    {
        $decoded = json_decode((string) $request->rawBody(), true);
        return is_array($decoded) ? $decoded : [];
    }
}
