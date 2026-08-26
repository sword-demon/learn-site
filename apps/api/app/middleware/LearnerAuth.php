<?php
declare(strict_types=1);

namespace App\middleware;

use App\service\TokenService;
use App\support\ApiResponse;
use Webman\Http\Request;
use Webman\Http\Response;
use Webman\MiddlewareInterface;

final class LearnerAuth implements MiddlewareInterface
{
    public function __construct(private readonly TokenService $tokens) {}

    public function process(Request $request, callable $handler): Response
    {
        $header = (string) $request->header('authorization', '');
        if (!preg_match('/^Bearer\s+(.+)$/i', $header, $m)) {
            return ApiResponse::fail(ApiResponse::UNAUTHENTICATED, 'UNAUTHENTICATED', $request->request_id ?? null);
        }
        $info = $this->tokens->verifyAccess(trim($m[1]));
        if ($info === null || $info['kind'] !== TokenService::KIND_LEARNER) {
            return ApiResponse::fail(ApiResponse::TOKEN_EXPIRED, 'TOKEN_EXPIRED', $request->request_id ?? null);
        }
        $request->account_id = $info['account_id'];
        $request->family_id = $info['family_id'];
        $request->actor_kind = TokenService::KIND_LEARNER;
        return $handler($request);
    }
}
