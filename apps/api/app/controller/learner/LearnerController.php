<?php

declare(strict_types=1);

namespace App\controller\learner;

use App\model\Account;
use App\model\Learner;
use App\support\ApiResponse;
use support\Request;

/** Learner-owned profile endpoints. */
final class LearnerController
{
    public function me(Request $request): \support\Response
    {
        $accountId = $this->accountId($request);
        $profile = $this->profile($accountId);
        if ($profile === null) {
            return ApiResponse::fail(ApiResponse::NOT_FOUND, 'LEARNER_NOT_FOUND');
        }
        return ApiResponse::ok($profile);
    }

    public function updateMe(Request $request): \support\Response
    {
        $accountId = $this->accountId($request);
        $account = Account::where('id', $accountId)->where('kind', 'learner')->find();
        $learner = Learner::where('account_id', $accountId)->find();
        if (!$account || !$learner) {
            return ApiResponse::fail(ApiResponse::NOT_FOUND, 'LEARNER_NOT_FOUND');
        }

        $body = self::readJson($request);
        $hasNickname = array_key_exists('nickname', $body);
        $hasVisibility = array_key_exists('show_on_course', $body);
        if (!$hasNickname && !$hasVisibility) {
            return ApiResponse::fail(ApiResponse::VALIDATION_FAILED, 'PROFILE_EMPTY');
        }

        if ($hasNickname) {
            $nickname = $body['nickname'];
            if ($nickname !== null && !is_string($nickname)) {
                return ApiResponse::fail(ApiResponse::VALIDATION_FAILED, 'NICKNAME_INVALID');
            }
            $nickname = $nickname === null ? null : trim($nickname);
            if ($nickname !== null && (mb_strlen($nickname) < 1 || mb_strlen($nickname) > 32)) {
                return ApiResponse::fail(ApiResponse::VALIDATION_FAILED, 'NICKNAME_INVALID');
            }
            $learner->nickname = $nickname;
        }

        if ($hasVisibility) {
            $visibility = $body['show_on_course'];
            if (!is_bool($visibility) && !in_array($visibility, [0, 1, '0', '1'], true)) {
                return ApiResponse::fail(ApiResponse::VALIDATION_FAILED, 'SHOW_ON_COURSE_INVALID');
            }
            $learner->show_on_course = filter_var($visibility, FILTER_VALIDATE_BOOL) ? 1 : 0;
        }

        $learner->updated_at = date('Y-m-d H:i:s');
        $learner->save();
        return ApiResponse::ok($this->profile($accountId));
    }

    /** @return array<string, mixed>|null */
    private function profile(int $accountId): ?array
    {
        $account = Account::where('id', $accountId)->where('kind', 'learner')->find();
        $learner = Learner::where('account_id', $accountId)->find();
        if (!$account || !$learner) {
            return null;
        }
        return [
            'account_id' => $accountId,
            'phone' => (string) $account->login,
            'nickname' => $learner->nickname !== null ? (string) $learner->nickname : null,
            'avatar_url' => $learner->avatar_url !== null ? (string) $learner->avatar_url : null,
            'show_on_course' => (int) $learner->show_on_course === 1,
            'status' => (string) $account->status,
            'created_at' => (string) $account->created_at,
        ];
    }

    private function accountId(Request $request): int
    {
        $accountId = (int) ($request->account_id ?? 0);
        return $accountId > 0 ? $accountId : 0;
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
