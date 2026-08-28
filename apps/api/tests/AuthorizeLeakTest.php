<?php

declare(strict_types=1);

namespace App\tests;

use App\middleware\Authorize;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use App\support\ApiResponse;
use Webman\Http\Request;
use Webman\Http\Response;

/**
 * US13 / T061 — Authorize 403 envelope must not leak server-side state.
 *
 * The independent test for US13 (员工仅获得问答权限时,看不到课程维护和订单)
 * also requires that direct URL access returns 403 with a body that does not
 * reveal the missing permission code, the staff id, or the request path.
 * If a future refactor accidentally includes any of those in the response,
 * the assertions below fail and the change is caught at CI time.
 *
 * The test exercises `ApiResponse::fail(...)` directly, the same call the
 * middleware makes — no test-only handle on the production class.
 */
final class AuthorizeLeakTest extends TestCase
{
    #[DataProvider('permissionCases')]
    public function testRoutesMapToTheNarrowestPermission(string $path, string $method, string $expected): void
    {
        self::assertSame($expected, Authorize::permissionFor($path, $method));
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function permissionCases(): iterable
    {
        yield 'course list' => ['/api/admin/v1/courses', 'GET', 'course.view'];
        yield 'course create' => ['/api/admin/v1/courses', 'POST', 'course.manage'];
        yield 'course update' => ['/api/admin/v1/courses/42', 'PATCH', 'course.manage'];
        yield 'course publish' => ['/api/admin/v1/courses/42/publish', 'POST', 'course.publish'];
        yield 'course unpublish' => ['/api/admin/v1/courses/42/unpublish', 'POST', 'course.publish'];
        yield 'asset upload' => ['/api/admin/v1/assets', 'POST', 'asset.upload'];
        yield 'course cover upload' => ['/api/admin/v1/course-covers', 'POST', 'course.manage'];
        yield 'map cover upload' => ['/api/admin/v1/map-covers', 'POST', 'map.manage'];
        yield 'map list' => ['/api/admin/v1/learning-maps', 'GET', 'map.view'];
        yield 'map update' => ['/api/admin/v1/learning-maps/9', 'PATCH', 'map.manage'];
        yield 'map publish' => ['/api/admin/v1/learning-maps/9/publish', 'POST', 'map.publish'];
        yield 'audit list' => ['/api/admin/v1/audit', 'GET', 'audit.view'];
    }

    public function testMiddlewareReturnsFixed403WithoutCallingHandler(): void
    {
        $request = new Request("PATCH /api/admin/v1/courses/42 HTTP/1.1\r\nHost: test\r\n\r\n");
        $request->permissions = ['qa.view', 'qa.answer'];
        $request->request_id = 'req-phase-9';
        $called = false;

        $response = (new Authorize())->process($request, static function () use (&$called): Response {
            $called = true;
            return ApiResponse::ok(['secret' => 'course body']);
        });

        self::assertFalse($called);
        self::assertSame(403, $response->getStatusCode());
        self::assertSame([
            'ok' => false,
            'data' => null,
            'error' => ['code' => 'FORBIDDEN', 'message' => 'FORBIDDEN'],
            'meta' => ['request_id' => 'req-phase-9'],
        ], self::decode($response));
    }

    public function testSuperAdminBypassesPermissionMapping(): void
    {
        $request = new Request("PATCH /api/admin/v1/courses/42 HTTP/1.1\r\nHost: test\r\n\r\n");
        $request->permissions = ['*'];

        $response = (new Authorize())->process(
            $request,
            static fn (): Response => ApiResponse::ok(['allowed' => true]),
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['ok' => true, 'data' => ['allowed' => true]], self::decode($response));
    }

    public function testForbiddenEnvelopeShapeIsFixed(): void
    {
        $response = ApiResponse::fail(ApiResponse::FORBIDDEN, 'FORBIDDEN', 'req-abc-123');
        $body = self::decode($response);

        // Canonical envelope: ok=false, data=null, error.code,
        // error.message, meta.request_id. Nothing else.
        self::assertSame(false, $body['ok']);
        self::assertNull($body['data']);
        self::assertSame('FORBIDDEN', $body['error']['code']);
        self::assertSame('FORBIDDEN', $body['error']['message']);
        self::assertSame('req-abc-123', $body['meta']['request_id']);

        // No additional top-level keys beyond ok / data / error / meta.
        self::assertSame(
            ['ok', 'data', 'error', 'meta'],
            array_keys($body),
            '403 envelope must not introduce extra keys',
        );
        self::assertSame(['code', 'message'], array_keys($body['error']));
    }

    public function testForbiddenEnvelopeOmitsRequestIdWhenAbsent(): void
    {
        $response = ApiResponse::fail(ApiResponse::FORBIDDEN, 'FORBIDDEN', null);
        $body = self::decode($response);

        self::assertSame(false, $body['ok']);
        self::assertSame('FORBIDDEN', $body['error']['code']);
        self::assertArrayNotHasKey('meta', $body);
    }

    public function testForbiddenEnvelopeDoesNotLeakPathOrMissingCode(): void
    {
        $response = ApiResponse::fail(ApiResponse::FORBIDDEN, 'FORBIDDEN', 'req-abc-123');
        $serialised = strtolower((string) $response->rawBody());

        // The leaked field is the path the user tried to access — that
        // reveals which admin endpoints exist. The other is the missing
        // permission code, which discloses the catalog to anyone with a
        // token. Both must stay out of the response.
        self::assertStringNotContainsString('course.publish', $serialised);
        self::assertStringNotContainsString('/api/admin/v1/courses', $serialised);
        self::assertStringNotContainsString('staff_id', $serialised);
        self::assertStringNotContainsString('account_id', $serialised);
        // The held-permissions array and the missing permission name would
        // both disclose the granted set to a probing client.
        self::assertStringNotContainsString('permission', $serialised);
        self::assertStringNotContainsString('granted', $serialised);
    }

    private static function decode(Response $response): array
    {
        $data = json_decode((string) $response->rawBody(), true);
        self::assertIsArray($data, '403 response body must be JSON-parseable');
        return $data;
    }
}
