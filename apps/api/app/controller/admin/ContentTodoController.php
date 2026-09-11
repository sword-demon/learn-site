<?php

declare(strict_types=1);

namespace App\controller\admin;

use App\service\BusinessException;
use App\service\ContentTodoService;
use App\support\ApiResponse;
use App\support\Logger;
use support\Request;

final class ContentTodoController
{
    public function __construct(private readonly ContentTodoService $service)
    {
    }

    public function index(Request $request): \support\Response
    {
        return $this->wrap(fn (): array => $this->service->list(
            $this->staffId($request),
            $this->permissions($request),
            [
                'source_type' => $this->optionalString($request->get('source_type')),
                'workflow_status' => $this->optionalString($request->get('workflow_status')),
                'page' => (int) $request->get('page', 1),
                'limit' => (int) $request->get('limit', 20),
            ],
        ));
    }

    public function show(Request $request, string $id): \support\Response
    {
        return $this->wrap(fn (): array => $this->service->get(
            $this->staffId($request),
            $this->id($id),
            $this->permissions($request),
        ));
    }

    public function patch(Request $request, string $id): \support\Response
    {
        return $this->wrap(fn (): array => $this->service->triage(
            $this->staffId($request),
            $this->id($id),
            $this->readJson($request),
            $this->permissions($request),
        ));
    }

    public function respond(Request $request, string $id): \support\Response
    {
        return $this->wrap(fn (): array => $this->service->respond(
            $this->staffId($request),
            $this->id($id),
            $this->readJson($request),
            $this->permissions($request),
        ));
    }

    public function generateCandidate(Request $request, string $id): \support\Response
    {
        return $this->wrap(fn (): array => $this->service->generateCandidate(
            $this->staffId($request),
            $this->id($id),
            $this->readJson($request),
            $this->permissions($request),
        ));
    }

    public function editCandidate(Request $request, string $id, string $candidateId): \support\Response
    {
        return $this->wrap(fn (): array => $this->service->editCandidate(
            $this->staffId($request),
            $this->id($id),
            $this->id($candidateId),
            $this->readJson($request),
            $this->permissions($request),
        ));
    }

    public function approveCandidate(Request $request, string $id, string $candidateId): \support\Response
    {
        return $this->wrap(fn (): array => $this->service->approveCandidate(
            $this->staffId($request),
            $this->id($id),
            $this->id($candidateId),
            $this->readJson($request),
            $this->permissions($request),
        ));
    }

    public function rejectCandidate(Request $request, string $id, string $candidateId): \support\Response
    {
        $body = $this->readJson($request);
        return $this->wrap(fn (): array => $this->service->rejectCandidate(
            $this->staffId($request),
            $this->id($id),
            $this->id($candidateId),
            (string) ($body['reason'] ?? ''),
            $this->permissions($request),
        ));
    }

    public function close(Request $request, string $id): \support\Response
    {
        return $this->wrap(fn (): array => $this->service->close(
            $this->staffId($request),
            $this->id($id),
            $this->readJson($request),
            $this->permissions($request),
        ));
    }

    private function staffId(Request $request): int
    {
        $id = (int) ($request->account_id ?? 0);
        if ($id <= 0) {
            throw new BusinessException('UNAUTHENTICATED', 'UNAUTHENTICATED');
        }
        return $id;
    }

    /** @return list<string> */
    private function permissions(Request $request): array
    {
        $permissions = $request->permissions ?? [];
        return is_array($permissions) ? array_values(array_filter($permissions, 'is_string')) : [];
    }

    private function id(string $raw): int
    {
        if (!ctype_digit($raw) || (int) $raw <= 0) {
            throw new BusinessException('NOT_FOUND', 'INVALID_ID');
        }
        return (int) $raw;
    }

    private function optionalString(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        return (string) $value;
    }

    /** @return array<string,mixed> */
    private function readJson(Request $request): array
    {
        $raw = (string) $request->rawBody();
        if ($raw === '') {
            return [];
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            throw new BusinessException('VALIDATION_FAILED', 'INVALID_JSON');
        }
        return $data;
    }

    private function wrap(callable $operation): \support\Response
    {
        try {
            return ApiResponse::ok($operation(), request()->request_id ?? null);
        } catch (BusinessException $exception) {
            $code = match ($exception->apiCode) {
                'UNAUTHENTICATED' => ApiResponse::UNAUTHENTICATED,
                'NOT_FOUND' => ApiResponse::NOT_FOUND,
                'FORBIDDEN' => ApiResponse::FORBIDDEN,
                'CONFLICT' => ApiResponse::CONFLICT,
                default => ApiResponse::VALIDATION_FAILED,
            };
            return ApiResponse::fail($code, $exception->getMessage(), request()->request_id ?? null);
        } catch (\Throwable $exception) {
            Logger::error('content_todo.admin.failed', ['err' => $exception->getMessage()]);
            return ApiResponse::fail(ApiResponse::INTERNAL, 'INTERNAL', request()->request_id ?? null);
        }
    }
}
