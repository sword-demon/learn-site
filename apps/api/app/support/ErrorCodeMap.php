<?php
declare(strict_types=1);

namespace App\support;

final class ErrorCodeMap
{
    private const MESSAGES = [
        'FORBIDDEN' => '没有权限执行此操作。',
        'UNAUTHENTICATED' => '请先登录。',
        'TOKEN_EXPIRED' => '登录状态已过期，请重新登录。',
        'NOT_FOUND' => '请求的资源不存在。',
        'COURSE_NOT_FOUND' => '课程不存在或已下架。',
        'SALE_PRICE_INVALID' => '销售价必须低于列表价。',
        'SALE_WINDOW_INVALID' => '销售时段无效，请检查开始和结束时间。',
        'INVALID_ID' => '请求参数无效。',
        'INTERNAL' => '系统暂时不可用，请稍后再试。',
    ];

    public static function message(string $code, string $fallback): string
    {
        return self::MESSAGES[$code] ?? $fallback;
    }
}
