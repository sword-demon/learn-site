<?php
declare(strict_types=1);

namespace App\service;

/**
 * BusinessException — caught at the controller boundary and mapped to an
 * envelope via ApiResponse. `code` is a stable ApiResponse::* constant;
 * `message` is the developer-facing key (e.g. COURSE_NOT_FOUND).
 */
final class BusinessException extends \RuntimeException
{
    public function __construct(public readonly string $apiCode, string $message)
    {
        parent::__construct($message);
    }
}
