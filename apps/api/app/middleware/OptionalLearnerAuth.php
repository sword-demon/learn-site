<?php

declare(strict_types=1);

namespace App\middleware;

use App\service\TokenService;
use App\support\ApiResponse;
use Webman\Http\Request;
use Webman\Http\Response;
use Webman\MiddlewareInterface;

/**
 * Adds learner identity to public requests when a bearer token is present.
 */
final class OptionalLearnerAuth implements MiddlewareInterface
{
    public function __construct(private readonly TokenService $tokens)
    {
    }

    public function process(Request $request, callable $handler): Response
    {
        $header = trim((string) $request->header('authorization', ''));
        if ($header === '') {
            return $handler($request);
        }
        if (!preg_match('/^Bearer\s+(.+)$/i', $header, $matches)) {
            return ApiResponse::fail(
                ApiResponse::UNAUTHENTICATED,
                'UNAUTHENTICATED',
                $request->request_id ?? null,
            );
        }

        $info = $this->tokens->verifyAccess(trim($matches[1]));
        if ($info === null || $info['kind'] !== TokenService::KIND_LEARNER) {
            return ApiResponse::fail(
                ApiResponse::TOKEN_EXPIRED,
                'TOKEN_EXPIRED',
                $request->request_id ?? null,
            );
        }

        // Webman exposes request-scoped context through runtime properties.
        /** @phpstan-ignore-next-line */
        $request->account_id = $info['account_id'];
        /** @phpstan-ignore-next-line */
        $request->family_id = $info['family_id'];
        /** @phpstan-ignore-next-line */
        $request->actor_kind = TokenService::KIND_LEARNER;
        return $handler($request);
    }
}
