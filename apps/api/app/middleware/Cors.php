<?php
declare(strict_types=1);

namespace App\middleware;

use Webman\Http\Request;
use Webman\Http\Response;
use Webman\MiddlewareInterface;

final class Cors implements MiddlewareInterface
{
    private const ALLOWED = [
        'http://localhost:8080',
        'http://localhost:8081',
        'http://127.0.0.1:8080',
        'http://127.0.0.1:8081',
    ];

    public function process(Request $request, callable $handler): Response
    {
        if ($request->method() === 'OPTIONS') {
            return $this->preflight($request);
        }
        $response = $handler($request);
        $origin = (string) $request->header('origin', '');
        if ($origin !== '' && in_array($origin, self::ALLOWED, true)) {
            $response->withHeaders([
                'Access-Control-Allow-Origin' => $origin,
                'Vary' => 'Origin',
            ]);
        }
        return $response;
    }

    private function preflight(Request $request): Response
    {
        $origin = (string) $request->header('origin', '');
        $allow = in_array($origin, self::ALLOWED, true) ? $origin : '';
        return new \support\Response(204, [
            'Access-Control-Allow-Origin' => $allow,
            'Access-Control-Allow-Methods' => 'GET, POST, PUT, PATCH, DELETE, OPTIONS',
            'Access-Control-Allow-Headers' => 'Authorization, Content-Type, X-Request-Id',
            'Access-Control-Max-Age' => '600',
            'Vary' => 'Origin',
        ]);
    }
}
