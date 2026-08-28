<?php
declare(strict_types=1);

namespace support;

/**
 * Request context properties are attached by middleware for the duration of
 * one request. Webman intentionally keeps the base request open-ended.
 *
 * @property int|string|null $account_id
 * @property string|null $family_id
 * @property string|null $actor_kind
 * @property list<string> $permissions
 * @property string|null $request_id
 */
class Request
{
    public function route(?string $name = null, mixed $default = null): mixed {}
}

namespace Webman\Http;

/**
 * @property int|string|null $account_id
 * @property string|null $family_id
 * @property string|null $actor_kind
 * @property list<string> $permissions
 * @property string|null $request_id
 */
class Request
{
}
