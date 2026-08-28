<?php
declare(strict_types=1);

namespace App\controller\learner;

use App\model\Account;
use App\model\Learner;
use App\service\CaptchaService;
use App\service\TokenService;
use App\support\ApiResponse;
use App\support\Logger;
use support\Request;
use support\think\Db;

final class AuthController
{
    private const PHONE_REGEX = '/^1[3-9]\d{9}$/';

    public function __construct(
        private readonly TokenService $tokens,
        private readonly CaptchaService $captcha,
    ) {}

    public function captcha(Request $request): \support\Response
    {
        $issued = $this->captcha->issue();
        if ($issued === null) {
            return ApiResponse::fail(ApiResponse::INTERNAL, 'captcha_unavailable');
        }
        return ApiResponse::ok($issued);
    }

    public function register(Request $request): \support\Response
    {
        $body = self::readJson($request);
        $phone = (string) ($body['phone'] ?? '');
        $password = (string) ($body['password'] ?? '');
        $cid = (string) ($body['captcha_id'] ?? '');
        $cans = (string) ($body['captcha_answer'] ?? '');
        if (!preg_match(self::PHONE_REGEX, $phone)) {
            return ApiResponse::fail(ApiResponse::VALIDATION_FAILED, 'INVALID_PHONE');
        }
        if (strlen($password) < 8 || strlen($password) > 72) {
            return ApiResponse::fail(ApiResponse::VALIDATION_FAILED, 'PASSWORD_LENGTH');
        }
        if (!$this->captcha->consume($cid, $cans)) {
            return ApiResponse::fail(ApiResponse::CAPTCHA_INVALID, 'CAPTCHA_INVALID');
        }
        $existing = Account::where('kind', 'learner')->where('login', $phone)->find();
        if ($existing) {
            return ApiResponse::fail(ApiResponse::CONFLICT, 'PHONE_TAKEN');
        }
        try {
            $accountId = (int) Db::transaction(function () use ($phone, $password) {
                $now = date('Y-m-d H:i:s');
                $account = Account::create([
                    'kind' => 'learner',
                    'login' => $phone,
                    'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                    'status' => 'active',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                $id = (int) $account->id;
                Learner::create([
                    'account_id' => $id,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                return $id;
            });
        } catch (\Throwable $e) {
            Logger::error('learner.register.failed', ['err' => $e->getMessage()]);
            return ApiResponse::fail(ApiResponse::INTERNAL, 'register_failed');
        }
        $pair = $this->tokens->issue((string) $accountId, TokenService::KIND_LEARNER);
        if ($pair === null) {
            return ApiResponse::fail(ApiResponse::INTERNAL, 'token_unavailable');
        }
        return ApiResponse::ok($pair);
    }

    public function login(Request $request): \support\Response
    {
        $body = self::readJson($request);
        $phone = (string) ($body['phone'] ?? '');
        $password = (string) ($body['password'] ?? '');
        $cid = (string) ($body['captcha_id'] ?? '');
        $cans = (string) ($body['captcha_answer'] ?? '');
        if (!preg_match(self::PHONE_REGEX, $phone)) {
            return ApiResponse::fail(ApiResponse::VALIDATION_FAILED, 'INVALID_PHONE');
        }
        if (!$this->captcha->consume($cid, $cans)) {
            return ApiResponse::fail(ApiResponse::CAPTCHA_INVALID, 'CAPTCHA_INVALID');
        }
        $account = Account::where('kind', 'learner')->where('login', $phone)->find();
        if (!$account || $account->status !== 'active'
            || !password_verify($password, (string) $account->password_hash)) {
            return ApiResponse::fail(ApiResponse::LOGIN_INVALID, 'LOGIN_INVALID');
        }
        $account->last_login_at = date('Y-m-d H:i:s');
        $account->save();
        $pair = $this->tokens->issue((string) $account->id, TokenService::KIND_LEARNER);
        if ($pair === null) {
            return ApiResponse::fail(ApiResponse::INTERNAL, 'token_unavailable');
        }
        return ApiResponse::ok($pair);
    }

    public function refresh(Request $request): \support\Response
    {
        $body = self::readJson($request);
        $refresh = (string) ($body['refresh_token'] ?? '');
        $rotated = $this->tokens->rotate($refresh, TokenService::KIND_LEARNER);
        if ($rotated === null) {
            return ApiResponse::fail(ApiResponse::TOKEN_REVOKED, 'TOKEN_REVOKED');
        }
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

    /** @return array<string, mixed> */
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
