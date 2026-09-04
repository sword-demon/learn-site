<?php

declare(strict_types=1);

namespace App\support\Crypto;

use App\service\BusinessException;

/**
 * AES-256-GCM symmetric encryption helper.
 *
 * Provides static encrypt/decrypt methods using PHP's openssl extension.
 * Payload format: `v1:<b64_iv>:<b64_cipher>:<b64_tag>`
 *
 * Constitution §V compliance:
 * - All sensitive payment merchant_key values MUST use this helper before storing to DB
 * - The raw plaintext NEVER appears in logs, audit trails, or API responses
 * - Key comes from env PAYMENT_KEY_ENC_KEY (32-byte base64)
 */
final class AesGcmCipher
{
    public const VERSION = 'v1';
    public const VERSION_PREFIX_LEN = 3; // length of "v1:"

    /**
     * Encrypt plaintext with AES-256-GCM.
     *
     * @param string $plain   Plaintext string (e.g., merchant_key value)
     * @param string $keyB64  Base64-encoded 32-byte key from PAYMENT_KEY_ENC_KEY
     *
     * @return string Encrypted payload in format: v1:<b64_iv>:<b64_cipher>:<b64_tag>
     *
     * @throws BusinessException If encryption fails or key is missing
     */
    public static function encrypt(string $plain, string $keyB64): string
    {
        if ($plain === '') {
            throw new BusinessException('BAD_INPUT', 'Plaintext cannot be empty');
        }

        if ($keyB64 === '') {
            throw new BusinessException(
                'BAD_INPUT',
                'PAYMENT_KEY_ENC_KEY must be non-empty base64 string'
            );
        }

        $key = base64_decode($keyB64, true);
        if ($key === false || strlen($key) !== 32) {
            throw new BusinessException(
                'BAD_INPUT',
                'PAYMENT_KEY_ENC_KEY must decode to 32 bytes'
            );
        }

        $iv = random_bytes(12); // 96-bit IV for GCM
        $tag = '';
        $cipher = openssl_encrypt(
            $plain,
            'aes-256-gcm',
            $key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            '',
            16,
        );

        if ($cipher === false) {
            throw new BusinessException(
                'INTERNAL_ERROR',
                'AES encryption failed: ' . openssl_error_string()
            );
        }

        return self::VERSION . ':' . base64_encode($iv) . ':' . base64_encode($cipher) . ':' . base64_encode($tag);
    }

    /**
     * Decrypt payload to plaintext.
     *
     * @param string $payload Encrypted payload in format: v1:<b64_iv>:<b64_cipher>:<b64_tag>
     * @param string $keyB64  Base64-encoded 32-byte key from PAYMENT_KEY_ENC_KEY
     *
     * @return string Decrypted plaintext
     *
     * @throws BusinessException If decryption fails, version unsupported, or key is missing
     */
    public static function decrypt(string $payload, string $keyB64): string
    {
        if ($keyB64 === '') {
            throw new BusinessException(
                'BAD_INPUT',
                'PAYMENT_KEY_ENC_KEY must be non-empty base64 string'
            );
        }

        $parts = explode(':', $payload, 4);
        if (count($parts) !== 4) {
            throw new BusinessException(
                'DECRYPTION_FAILED',
                'Invalid payload format: expected 4 parts'
            );
        }

        [$version, $ivB64, $ctB64, $tagB64] = $parts;
        if ($version !== self::VERSION) {
            throw new BusinessException(
                'DECRYPTION_FAILED',
                'Unsupported version: ' . $version
            );
        }

        $key = base64_decode($keyB64, true);
        if ($key === false || strlen($key) !== 32) {
            throw new BusinessException(
                'DECRYPTION_FAILED',
                'Invalid key encoding'
            );
        }

        $iv = base64_decode($ivB64, true);
        $ct = base64_decode($ctB64, true);
        $tag = base64_decode($tagB64, true);

        if ($iv === false || $ct === false || $tag === false) {
            throw new BusinessException(
                'DECRYPTION_FAILED',
                'Base64 decoding failed'
            );
        }

        if (strlen($iv) !== 12 || strlen($tag) !== 16) {
            throw new BusinessException('DECRYPTION_FAILED', 'Invalid AES-GCM payload');
        }

        $plain = openssl_decrypt(
            $ct,
            'aes-256-gcm',
            $key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
        );

        if ($plain === false) {
            throw new BusinessException(
                'DECRYPTION_FAILED',
                'Decryption failed: ' . openssl_error_string()
            );
        }

        return $plain;
    }

    private function __construct() {}
}
