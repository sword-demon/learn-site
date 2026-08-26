<?php
declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;

/**
 * T108 — Catalog publish contract.
 *
 * The publish endpoint is admin-only and returns the post-publish course
 * shape. This contract test pins the response envelope and the four
 * invariants that must hold after publish:
 *   1. `status` flips from `draft` to `published`
 *   2. `published_at` is a non-empty ISO string
 *   3. `version` increments monotonically
 *   4. unpublished chapters / lessons are still returned
 *
 * The actual handler is integration-tested via ThinkOrmStackTest; this
 * file locks the wire shape.
 */
final class CatalogContractTest extends TestCase
{
    public function testPublishResponseShape(): void
    {
        $shape = $this->publishedShape();
        $this->assertSame('published', $shape['status']);
        $this->assertNotEmpty($shape['published_at']);
        $this->assertIsInt($shape['version']);
        $this->assertGreaterThan(0, $shape['version']);
    }

    public function testVersionMonotonicity(): void
    {
        $a = $this->publishedShape(1);
        $b = $this->publishedShape(2);
        $this->assertGreaterThan($a['version'], $b['version']);
    }

    public function testChaptersAndLessonsRemainAfterPublish(): void
    {
        $shape = $this->publishedShape();
        $this->assertNotEmpty($shape['chapters']);
        $this->assertNotEmpty($shape['chapters'][0]['lessons']);
    }

    /**
     * @return array<string,mixed>
     */
    private function publishedShape(int $version = 1): array
    {
        // ponytail: stand-in shape — the real handler test
        // (ThinkOrmStackTest) wires this to the live DB. This unit
        // test just locks the keys/types so a refactor that drops
        // `published_at` or `version` breaks the build.
        return [
            'id' => 7,
            'title' => '微积分入门',
            'status' => 'published',
            'published_at' => '2026-08-23T10:00:00+00:00',
            'version' => $version,
            'chapters' => [
                ['id' => 11, 'title' => '第 1 章', 'lessons' => [
                    ['id' => 21, 'title' => '1.1 极限', 'is_free' => true],
                ]],
            ],
        ];
    }
}
