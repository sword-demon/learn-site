<?php

declare(strict_types=1);

namespace App\controller\admin;

use App\model\Account;
use App\service\CaptchaService;
use App\service\PermissionService;
use App\service\TokenService;
use App\support\ApiResponse;
use App\support\Logger;
use support\Request;

final class AuthController
{
    private const PHONE_REGEX = '/^1[3-9]\d{9}$/';

    public function __construct(
        private readonly TokenService $tokens,
        private readonly CaptchaService $captcha,
        private readonly PermissionService $perms,
    ) {
    }

    public function captcha(Request $request): \support\Response
    {
        $issued = $this->captcha->issue();
        if ($issued === null) {
            return ApiResponse::fail(ApiResponse::INTERNAL, 'captcha_unavailable');
        }
        return ApiResponse::ok($issued);
    }

    public function login(Request $request): \support\Response
    {
        $body = self::readJson($request);
        $account = (string) ($body['account'] ?? '');
        $password = (string) ($body['password'] ?? '');
        $cid = (string) ($body['captcha_id'] ?? '');
        $cans = (string) ($body['captcha_answer'] ?? '');
        if (preg_match(self::PHONE_REGEX, $account)) {
            return ApiResponse::fail(ApiResponse::VALIDATION_FAILED, 'INVALID_LOGIN');
        }
        if (strlen($account) < 3 || strlen($account) > 64) {
            return ApiResponse::fail(ApiResponse::VALIDATION_FAILED, 'INVALID_LOGIN');
        }
        if (strlen($password) < 8 || strlen($password) > 72) {
            return ApiResponse::fail(ApiResponse::VALIDATION_FAILED, 'PASSWORD_LENGTH');
        }
        if (!$this->captcha->consume($cid, $cans)) {
            return ApiResponse::fail(ApiResponse::CAPTCHA_INVALID, 'CAPTCHA_INVALID');
        }
        $acct = Account::where('kind', 'staff')->where('login', $account)->find();
        if (
            !$acct
            || $acct->status !== 'active'
            || !password_verify($password, (string) $acct->password_hash)
        ) {
            return ApiResponse::fail(ApiResponse::LOGIN_INVALID, 'LOGIN_INVALID');
        }
        if (!$this->perms->isStaffActive((int) $acct->id)) {
            return ApiResponse::fail(ApiResponse::FORBIDDEN, 'STAFF_INACTIVE');
        }
        $acct->last_login_at = date('Y-m-d H:i:s');
        $acct->save();
        $pair = $this->tokens->issue((string) $acct->id, TokenService::KIND_STAFF);
        if ($pair === null) {
            return ApiResponse::fail(ApiResponse::INTERNAL, 'token_unavailable');
        }
        $pair['must_change_password'] = (bool) $acct->must_change_password;
        // US13 / T060 — the admin SPA needs the permission list at login
        // time to filter the sidebar and guard routes. Refresh re-hydrates
        // via the same `effectiveCodes` call from AdminAuth middleware.
        $pair['permission_codes'] = $this->perms->effectiveCodes((int) $acct->id);
        return ApiResponse::ok($pair);
    }

    public function refresh(Request $request): \support\Response
    {
        $body = self::readJson($request);
        $refresh = (string) ($body['refresh_token'] ?? '');
        $rotated = $this->tokens->rotate($refresh, TokenService::KIND_STAFF);
        if ($rotated === null) {
            return ApiResponse::fail(ApiResponse::TOKEN_REVOKED, 'TOKEN_REVOKED');
        }
        $accountId = (int) ($rotated['account_id'] ?? 0);
        $account = Account::find($accountId);
        if ($accountId <= 0 || !$account || !$this->perms->isStaffActive($accountId)) {
            if ($accountId > 0) {
                $this->tokens->kickAll((string) $accountId);
            }
            return ApiResponse::fail(ApiResponse::TOKEN_REVOKED, 'TOKEN_REVOKED');
        }
        $rotated['must_change_password'] = (bool) $account->must_change_password;
        $rotated['permission_codes'] = $this->perms->effectiveCodes($accountId);
        unset($rotated['account_id'], $rotated['kind']);
        return ApiResponse::ok($rotated);
    }

    public function logout(Request $request): \support\Response
    {
        $family = (string) ($request->family_id ?? '');
        $acct = (string) ($request->account_id ?? '');
        if ($family !== '' && $acct !== '') {
            $this->tokens->kickFamily($acct, $family);
        }
        return ApiResponse::ok(['logged_out' => true]);
    }

    public function firstPassword(Request $request): \support\Response
    {
        $acct = (int) ($request->account_id ?? 0);
        if ($acct <= 0) {
            return ApiResponse::fail(ApiResponse::UNAUTHENTICATED, 'UNAUTHENTICATED');
        }
        $body = self::readJson($request);
        $current = (string) ($body['current_password'] ?? '');
        $next = (string) ($body['new_password'] ?? '');
        if (strlen($next) < 8 || strlen($next) > 72) {
            return ApiResponse::fail(ApiResponse::VALIDATION_FAILED, 'PASSWORD_LENGTH');
        }
        $a = Account::find($acct);
        if (!$a || !password_verify($current, (string) $a->password_hash)) {
            return ApiResponse::fail(ApiResponse::LOGIN_INVALID, 'LOGIN_INVALID');
        }
        $a->password_hash = password_hash($next, PASSWORD_DEFAULT);
        $a->must_change_password = 0;
        $a->updated_at = date('Y-m-d H:i:s');
        $a->save();
        $this->tokens->kickAll((string) $acct);
        Logger::info('staff.first_password_changed', ['account_id' => $acct]);
        return ApiResponse::ok(['changed' => true]);
    }

    /** @return array<array-key, mixed> */
    private static function readJson(Request $request): array
    {
        $raw = (string) $request->rawBody();
        if ($raw === '') {
            return [];
        }
        $data = json_decode($raw, true);
        return is_array($data) ? $data : [];
    }
}
