<?php
declare(strict_types=1);

namespace support\think;

/**
 * Webman delegates these facade calls to think-orm connections, which return
 * the concrete Query type rather than the less capable BaseQuery mixin.
 *
 * @method static \think\db\Query name(string $name)
 * @method static \think\db\Query table(string|array|\think\db\Raw $table)
 * @method static \think\db\Raw raw(mixed $value)
 * @method static mixed transaction(callable $callback)
 * @method static list<array<string, mixed>> query(string $sql, array $bind = [])
 */
class Db
{
}
