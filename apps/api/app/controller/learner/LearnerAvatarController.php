<?php

declare(strict_types=1);

namespace App\controller\learner;

use App\controller\admin\CourseCoverController;
use App\model\Account;
use App\model\Learner;
use App\support\ApiResponse;
use Webman\Http\Request;

use function nowDatetime;

final class LearnerAvatarController extends CourseCoverController
{
    protected const ERROR_PREFIX = 'AVATAR';

    public function upload(Request $request): \support\Response
    {
        $learner = $this->requireLearner($request);
        if ($learner instanceof \support\Response) {
            return $learner;
        }

        $stored = $this->storeUploadedImage($request);
        if ($stored instanceof \support\Response) {
            return $stored;
        }

        $learner->avatar_url = $stored['url'];
        $learner->updated_at = nowDatetime();
        $learner->save();

        return ApiResponse::ok($this->profile((int) $learner->account_id), $request->request_id ?? null);
    }

    public function destroy(Request $request): \support\Response
    {
        $learner = $this->requireLearner($request);
        if ($learner instanceof \support\Response) {
            return $learner;
        }

        $learner->avatar_url = null;
        $learner->updated_at = nowDatetime();
        $learner->save();

        return ApiResponse::ok($this->profile((int) $learner->account_id), $request->request_id ?? null);
    }

    private function requireLearner(Request $request): Learner|\support\Response
    {
        $accountId = (int) ($request->account_id ?? 0);
        if ($accountId <= 0) {
            return ApiResponse::fail(ApiResponse::NOT_FOUND, 'LEARNER_NOT_FOUND');
        }
        $learner = Learner::where('account_id', $accountId)->find();
        if (!$learner) {
            return ApiResponse::fail(ApiResponse::NOT_FOUND, 'LEARNER_NOT_FOUND');
        }
        return $learner;
    }

    /** @return array<string, mixed> */
    private function profile(int $accountId): array
    {
        $account = Account::where('id', $accountId)->where('kind', 'learner')->find();
        $learner = Learner::where('account_id', $accountId)->find();
        return [
            'account_id' => $accountId,
            'phone' => $account ? (string) $account->login : '',
            'nickname' => $learner && $learner->nickname !== null ? (string) $learner->nickname : null,
            'avatar_url' => $learner && $learner->avatar_url !== null ? (string) $learner->avatar_url : null,
            'show_on_course' => $learner !== null && (int) $learner->show_on_course === 1,
            'status' => $account ? (string) $account->status : 'disabled',
            'created_at' => $account ? (string) $account->created_at : '',
        ];
    }
}
