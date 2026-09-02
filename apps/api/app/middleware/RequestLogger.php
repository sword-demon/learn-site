<?php
declare(strict_types=1);

namespace App\middleware;

use App\support\Logger;
use App\support\RequestId;
use Webman\Http\Request;
use Webman\Http\Response;
use Webman\MiddlewareInterface;

final class RequestLogger implements MiddlewareInterface
{
    public function process(Request $request, callable $handler): Response
    {
        $rid = (string) ($request->header('x-request-id', '') ?: bin2hex(random_bytes(8)));
        // ponytail: webman workers are long-lived; without reset(), the next
        // request in this worker would inherit the prior request's id. Read
        // sites already prefer $request->request_id, so clearing is enough.
        RequestId::set($rid);
        $request->request_id = $rid;
        $start = microtime(true);
        try {
            $response = $handler($request);
            $response->withHeader('x-request-id', $rid);
            Logger::info('http.request', [
                'request_id' => $rid,
                'method' => $request->method(),
                'path' => $request->path(),
                'status' => $response->getStatusCode(),
                'duration_ms' => (int) ((microtime(true) - $start) * 1000),
                'actor_kind' => $request->actor_kind ?? null,
                'actor_id' => isset($request->account_id) ? (int) $request->account_id : null,
                'action' => $request->path(),
            ]);
            return $response;
        } catch (\Throwable $e) {
            Logger::error('http.exception', [
                'request_id' => $rid,
                'method' => $request->method(),
                'path' => $request->path(),
                'duration_ms' => (int) ((microtime(true) - $start) * 1000),
                'err' => $e->getMessage(),
            ]);
            throw $e;
        } finally {
            RequestId::reset();
        }
    }
}
