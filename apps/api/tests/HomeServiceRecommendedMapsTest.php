<?php
declare(strict_types=1);

namespace Tests;

use App\service\HomeService;
use PHPUnit\Framework\TestCase;

final class HomeServiceRecommendedMapsTest extends TestCase
{
    public function testShapeMapSummaryMapsAllFields(): void
    {
        $row = [
            'id'            => 12,
            'department_id' => 3,
            'title'         => 'Go 工程进阶',
            'summary'       => 'tested map',
            'cover_url'     => 'https://img.test/12.png',
            'objective'     => '面向资深',
            'audience'      => '工程师',
            'status'        => 'published',
            'created_at'    => '2026-08-30 10:00:00',
            'updated_at'    => '2026-08-31 10:00:00',
        ];
        $reflection = new \ReflectionClass(HomeService::class);
        $method = $reflection->getMethod('shapeMapSummary');
        $method->setAccessible(true);
        $out = $method->invoke(null, $row);
        $this->assertSame(12, $out['id']);
        $this->assertSame(3, $out['department_id']);
        $this->assertSame('Go 工程进阶', $out['title']);
        $this->assertSame('published', $out['status']);
        $this->assertSame('tested map', $out['summary']);
        $this->assertSame('https://img.test/12.png', $out['cover_url']);
    }

    public function testShapeMapSummaryHandlesNulls(): void
    {
        $row = [
            'id'            => 1,
            'department_id' => 1,
            'title'         => 't',
            'created_at'    => '',
            'updated_at'    => '',
        ];
        $reflection = new \ReflectionClass(HomeService::class);
        $method = $reflection->getMethod('shapeMapSummary');
        $method->setAccessible(true);
        $out = $method->invoke(null, $row);
        $this->assertNull($out['summary']);
        $this->assertNull($out['cover_url']);
        $this->assertNull($out['objective']);
        $this->assertNull($out['audience']);
        $this->assertSame('published', $out['status']);
    }
}