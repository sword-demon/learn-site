<?php

declare(strict_types=1);

namespace App\controller\learner;

use App\service\BusinessException;
use App\service\CheckinService;
use App\support\ApiResponse;
use App\support\Logger;
use support\Request;

final class CheckinController
{
    public function __construct(private readonly CheckinService $checkins)
    {
    }

    public function today(Request $request): \support\Response
    {
        return $this->wrap(fn (): array => $this->checkins->getTodayStatus($this->learnerId($request)));
    }

    public function store(Request $request): \support\Response
    {
        return $this->wrapCreated(fn (): array => $this->checkins->create(
            $this->learnerId($request),
            (string) (self::readJson($request)['plan_html'] ?? ''),
        ));
    }

    public function index(Request $request): \support\Response
    {
        return $this->wrap(fn (): array => $this->checkins->listForLearner($this->learnerId($request), [
            'page' => (int) $request->get('page', 1),
            'limit' => (int) $request->get('limit', 20),
        ]));
    }

    public function show(Request $request, string $id): \support\Response
    {
        return $this->wrap(fn (): array => $this->checkins->getForLearner(
            $this->learnerId($request),
            $this->parseId($id),
        ));
    }

    private function learnerId(Request $request): int
    {
        $id = (int) ($request->account_id ?? 0);
        if ($id <= 0) {
            throw new BusinessException('UNAUTHENTICATED', 'UNAUTHENTICATED');
        }
        return $id;
    }

    private function parseId(string $id): int
    {
        if (!ctype_digit($id)) {
            throw new BusinessException('VALIDATION_FAILED', 'INVALID_ID');
        }
        return (int) $id;
    }

    /**
     * @return array<string, mixed>
     */
    private static function readJson(Request $request): array
    {
        $raw = (string) $request->rawBody();
        if ($raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function wrap(callable $operation): \support\Response
    {
        try {
            return ApiResponse::ok($operation(), request()->request_id ?? null);
        } catch (BusinessException $exception) {
            return $this->fail($exception);
        } catch (\Throwable $exception) {
            Logger::error('checkin.learner.failed', ['err' => $exception->getMessage()]);
            return ApiResponse::fail(ApiResponse::INTERNAL, 'INTERNAL', request()->request_id ?? null);
        }
    }

    private function wrapCreated(callable $operation): \support\Response
    {
        try {
            return ApiResponse::ok($operation(), request()->request_id ?? null)->withStatus(201);
        } catch (BusinessException $exception) {
            return $this->fail($exception);
        } catch (\Throwable $exception) {
            Logger::error('checkin.learner.failed', ['err' => $exception->getMessage()]);
            return ApiResponse::fail(ApiResponse::INTERNAL, 'INTERNAL', request()->request_id ?? null);
        }
    }

    private function fail(BusinessException $exception): \support\Response
    {
        $response = ApiResponse::fail(
            $exception->apiCode,
            $exception->getMessage(),
            request()->request_id ?? null,
        );
        return $exception->apiCode === ApiResponse::VALIDATION_FAILED
            ? $response->withStatus(422)
            : $response;
    }
}
