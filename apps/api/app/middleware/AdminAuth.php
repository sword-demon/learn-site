<?php
declare(strict_types=1);

namespace App\middleware;

use App\model\Account;
use App\service\PermissionService;
use App\service\TokenService;
use App\support\ApiResponse;
use Webman\Http\Request;
use Webman\Http\Response;
use Webman\MiddlewareInterface;

final class AdminAuth implements MiddlewareInterface
{
    public function __construct(
        private readonly TokenService $tokens,
        private readonly PermissionService $perms,
    ) {}

    public static function allowsWhileMustChangePassword(string $path, string $method): bool
    {
        return $method === 'POST' && $path === '/api/admin/v1/auth/password/first';
    }

    /** @param array<string, mixed>|null $info */
    public static function acceptsTokenInfo(?array $info): bool
    {
        return $info !== null && ($info['kind'] ?? null) === TokenService::KIND_STAFF;
    }

    public function process(Request $request, callable $handler): Response
    {
        $header = (string) $request->header('authorization', '');
        if (!preg_match('/^Bearer\s+(.+)$/i', $header, $m)) {
            return ApiResponse::fail(ApiResponse::UNAUTHENTICATED, 'UNAUTHENTICATED', $request->request_id ?? null);
        }
        $info = $this->tokens->verifyAccess(trim($m[1]));
        if ($info === null) {
            return ApiResponse::fail(ApiResponse::TOKEN_EXPIRED, 'TOKEN_EXPIRED', $request->request_id ?? null);
        }
        if (!self::acceptsTokenInfo($info)) {
            return ApiResponse::fail(ApiResponse::UNAUTHENTICATED, 'UNAUTHENTICATED', $request->request_id ?? null);
        }
        if (!$this->perms->isStaffActive((int) $info['account_id'])) {
            return ApiResponse::fail(ApiResponse::FORBIDDEN, 'STAFF_INACTIVE', $request->request_id ?? null);
        }
        $account = Account::find((int) $info['account_id']);
        if ($account && (int) $account->must_change_password === 1) {
            $path = (string) $request->path();
            if (!self::allowsWhileMustChangePassword($path, $request->method())) {
                return ApiResponse::fail(ApiResponse::FORBIDDEN, 'MUST_CHANGE_PASSWORD', $request->request_id ?? null);
            }
        }
        $request->account_id = (int) $info['account_id'];
        $request->family_id = (string) $info['family_id'];
        $request->actor_kind = TokenService::KIND_STAFF;
        $request->permissions = $this->perms->effectiveCodes((int) $info['account_id']);
        return $handler($request);
    }
}
