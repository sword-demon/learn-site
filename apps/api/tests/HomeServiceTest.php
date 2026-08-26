<?php
declare(strict_types=1);

namespace Tests;

use App\service\HomeService;
use PHPUnit\Framework\TestCase;

final class HomeServiceTest extends TestCase
{
    public function testNestBuildsThreeLevelsAndStops(): void
    {
        $rows = [
            ['id' => 1, 'parent_id' => 0, 'name' => '理学', 'sort' => 1],
            ['id' => 2, 'parent_id' => 1, 'name' => '数学', 'sort' => 1],
            ['id' => 3, 'parent_id' => 2, 'name' => '微积分', 'sort' => 1],
            ['id' => 4, 'parent_id' => 3, 'name' => '不该出现', 'sort' => 1],
        ];
        $tree = HomeService::nest($rows);
        $this->assertCount(1, $tree);
        $this->assertSame('理学', $tree[0]['name']);
        $this->assertSame('数学', $tree[0]['children'][0]['name']);
        $this->assertSame('微积分', $tree[0]['children'][0]['children'][0]['name']);
        $this->assertSame([], $tree[0]['children'][0]['children'][0]['children']);
    }

    public function testNestSkipsBrokenRows(): void
    {
        $tree = HomeService::nest([
            ['id' => 0, 'parent_id' => 0, 'name' => 'bad'],
            ['id' => 5, 'parent_id' => 0, 'name' => ''],
            ['id' => 6, 'parent_id' => 0, 'name' => '可用'],
        ]);
        $this->assertSame([['id' => 6, 'name' => '可用', 'children' => []]], $tree);
    }
}
