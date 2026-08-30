<?php

declare(strict_types=1);

namespace App\support;

use support\Response;

/**
 * Canonical JSON envelope (contracts/conventions.md).
 *
 * Shape: { ok: true, data } | { ok: false, error: { code, message }, meta?: { request_id } }
 *
 * Status codes:
 *   200/201 — success
 *   400     — VALIDATION_FAILED / CAPTCHA_INVALID / LOGIN_INVALID
 *   401     — UNAUTHENTICATED / TOKEN_EXPIRED / TOKEN_REVOKED
 *   403     — FORBIDDEN / LAST_SUPER_ADMIN
 *   404     — NOT_FOUND
 *   409     — CONFLICT / CATEGORY_IN_USE
 *   422     — PAYMENT_UNSETTLED
 *   500     — INTERNAL
 *
 * Course deletion blockers use CONFLICT with stable message keys documented
 * in contracts/admin-api.md so clients can present actionable copy.
 */
final class ApiResponse
{
    public const OK                   = 'OK';
    public const CAPTCHA_INVALID      = 'CAPTCHA_INVALID';
    public const TOKEN_EXPIRED        = 'TOKEN_EXPIRED';
    public const TOKEN_REVOKED        = 'TOKEN_REVOKED';
    public const UNAUTHENTICATED      = 'UNAUTHENTICATED';
    public const FORBIDDEN            = 'FORBIDDEN';
    public const NOT_FOUND            = 'NOT_FOUND';
    public const VALIDATION_FAILED    = 'VALIDATION_FAILED';
    public const LOGIN_INVALID        = 'LOGIN_INVALID';
    public const CONFLICT             = 'CONFLICT';
    public const LAST_SUPER_ADMIN     = 'LAST_SUPER_ADMIN';
    public const CATEGORY_IN_USE      = 'CATEGORY_IN_USE';
    public const PAYMENT_UNSETTLED    = 'PAYMENT_UNSETTLED';
    public const ACCOUNT_DISABLED     = 'ACCOUNT_DISABLED';
    public const ALREADY_CHECKED_IN   = 'ALREADY_CHECKED_IN';
    public const INTERNAL             = 'INTERNAL';

    private const STATUS_BY_CODE = [
        self::CAPTCHA_INVALID   => 400,
        self::VALIDATION_FAILED => 400,
        self::LOGIN_INVALID     => 400,
        self::UNAUTHENTICATED   => 401,
        self::TOKEN_EXPIRED     => 401,
        self::TOKEN_REVOKED     => 401,
        self::FORBIDDEN         => 403,
        self::LAST_SUPER_ADMIN  => 403,
        self::NOT_FOUND         => 404,
        self::CONFLICT          => 409,
        self::CATEGORY_IN_USE   => 409,
        self::PAYMENT_UNSETTLED => 422,
        self::ACCOUNT_DISABLED  => 403,
        self::ALREADY_CHECKED_IN => 409,
        self::INTERNAL          => 500,
    ];

    public static function ok(mixed $data = null, ?string $requestId = null): Response
    {
        return json(self::envelope(true, $data, null, $requestId));
    }

    public static function fail(string $code, string $message, ?string $requestId = null): Response
    {
        $status = self::STATUS_BY_CODE[$code] ?? 400;
        return json(
            self::envelope(false, null, ['code' => $code, 'message' => $message], $requestId),
        )->withStatus($status);
    }

    /**
     * @param array{code: string, message: string}|null $error
     * @return array{
     *     ok: bool,
     *     data: mixed,
     *     error?: array{code: string, message: string},
     *     meta?: array{request_id: string}
     * }
     */
    private static function envelope(bool $ok, mixed $data, ?array $error, ?string $requestId): array
    {
        $body = ['ok' => $ok, 'data' => $data];
        if (!$ok) {
            $body['data']   = null;
            $body['error']  = $error;
        }
        if ($requestId !== null && $requestId !== '') {
            $body['meta'] = ['request_id' => $requestId];
        }
        return $body;
    }
}
