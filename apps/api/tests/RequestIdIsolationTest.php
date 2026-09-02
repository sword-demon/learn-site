<?php

declare(strict_types=1);

namespace Tests;

use App\middleware\RequestLogger;
use App\support\ApiResponse;
use App\support\RequestId;
use PHPUnit\Framework\TestCase;
use Webman\Http\Request;
use Webman\Http\Response;

/**
 * ponytail: Webman workers are long-lived. The RequestId carrier lives on a
 * static field, so without an explicit reset() the next request in the same
 * worker inherits the prior request's id — visible in any Logger line that
 * reads RequestId::get() instead of $request->request_id.
 */
final class RequestIdIsolationTest extends TestCase
{
    public function testResetClearsStaticIdAfterRequestCompletes(): void
    {
        $request = new Request("GET /api/learner/v1/me HTTP/1.1\r\nHost: test\r\nx-request-id: abc-1\r\n\r\n");

        (new RequestLogger())->process(
            $request,
            static fn (Request $request): Response => ApiResponse::ok(['handled' => true]),
        );

        self::assertNull(RequestId::get(), 'RequestId must be reset between requests handled by the same long-lived worker');
    }

    public function testResetClearsStaticIdAfterHandlerThrows(): void
    {
        $request = new Request("GET /api/learner/v1/me HTTP/1.1\r\nHost: test\r\nx-request-id: abc-2\r\n\r\n");

        try {
            (new RequestLogger())->process(
                $request,
                static function (Request $request): Response {
                    throw new \RuntimeException('business_failed');
                },
            );
            self::fail('Expected handler exception to propagate');
        } catch (\RuntimeException $exception) {
            self::assertSame('business_failed', $exception->getMessage());
        }

        self::assertNull(RequestId::get(), 'RequestId must be reset even when handler throws');
    }

    public function testRequestIdInHeaderMatchesTheOneSetDuringRequest(): void
    {
        $request = new Request("GET /api/learner/v1/me HTTP/1.1\r\nHost: test\r\nx-request-id: trace-xyz\r\n\r\n");

        (new RequestLogger())->process(
            $request,
            static function (Request $request): Response {
                // ponytail: while the request is in flight, RequestId::get()
                // must mirror $request->request_id so log lines can correlate.
                self::assertSame('trace-xyz', RequestId::get());
                self::assertSame('trace-xyz', $request->request_id);
                return ApiResponse::ok(['handled' => true]);
            },
        );

        self::assertSame('trace-xyz', $request->request_id, 'handler must keep the id on the request object');
    }
}
