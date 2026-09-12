<?php

declare(strict_types=1);

namespace App\middleware;

use support\think\Db;
use Webman\Http\Request;
use Webman\Http\Response;
use Webman\MiddlewareInterface;

final class ApiRequestAudit implements MiddlewareInterface
{
    private const METHODS = ['POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'];
    private const MAX_BYTES = 65535;

    public function process(Request $request, callable $handler): Response
    {
        if (!in_array(strtoupper($request->method()), self::METHODS, true)) {
            return $handler($request);
        }
        $start = microtime(true);
        $params = $this->redact($request->all());
        try {
            $response = $handler($request);
        } catch (\Throwable $e) {
            $this->safeWrite($request, $params, ['error' => $e->getMessage()], 500, $start);
            throw $e;
        }
        $this->safeWrite($request, $params, $this->decode((string) $response->rawBody()), $response->getStatusCode(), $start);
        return $response;
    }

    private function safeWrite(Request $request, mixed $params, mixed $response, int $status, float $start): void
    {
        try {
            $this->write($request, $params, $response, $status, $start);
        } catch (\Throwable $e) {
            // 审计是旁路记录；表未迁移或数据库短暂不可用时不能阻断业务请求。
            \App\support\Logger::warning('api_request_audit.write_failed', ['err' => $e->getMessage()]);
        }
    }

    private function write(Request $request, mixed $params, mixed $response, int $status, float $start): void
    {
        Db::name('api_request_audit_log')->insert([
            'request_method' => strtoupper($request->method()),
            'route_path' => (string) $request->path(),
            'request_params' => $this->encode($params),
            'response_data' => $this->encode($response),
            'response_status' => $status,
            'duration_ms' => max(0, (int) round((microtime(true) - $start) * 1000)),
            'actor_id' => isset($request->account_id) ? (int) $request->account_id : null,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    private function encode(mixed $value): string
    {
        $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        return mb_strcut($json === false ? '' : $json, 0, self::MAX_BYTES, 'UTF-8');
    }

    private function decode(string $body): mixed
    {
        $decoded = json_decode($body, true);
        return json_last_error() === JSON_ERROR_NONE ? $decoded : $body;
    }

    private function redact(mixed $value): mixed
    {
        if (!is_array($value)) return $value;
        $sensitive = ['password', 'password_confirmation', 'token', 'access_token', 'refresh_token', 'authorization', 'secret', 'merchant_key'];
        foreach ($value as $key => &$item) {
            $item = in_array(strtolower((string) $key), $sensitive, true) ? '[REDACTED]' : $this->redact($item);
        }
        return $value;
    }
}
