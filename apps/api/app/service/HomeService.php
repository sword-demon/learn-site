<?php

declare(strict_types=1);

namespace App\service;

use App\model\Category;
use App\support\cache\HomeCache;

final class HomeService
{
    public function __construct(
        private readonly BannerService $bannerService = new BannerService(),
        private readonly HomeCache $cache = new HomeCache(),
    ) {
    }

    /**
     * Public site profile. Returns a default shape when the table is
     * missing (first boot) so the home payload contract is stable.
     *
     * @return array{title:string,subtitle:string,body_html:string,contact_email:string,updated_at:?string}
     */
    public function siteIntro(): array
    {
        return $this->cache->remember(
            HomeCache::KEY_SITE_INTRO,
            HomeCache::ttlSiteIntro(),
            function (): array {
                try {
                    $row = \support\think\Db::name('site_profile')->where('id', 1)->find();
                } catch (\Throwable $e) {
                    $row = null;
                }
                if (!is_array($row)) {
                    return [
                        'title' => '学习平台',
                        'subtitle' => '选课、学习、交流',
                        'body_html' => '',
                        'contact_email' => '',
                        'updated_at' => null,
                    ];
                }
                return [
                    'title' => (string) ($row['title'] ?? '学习平台'),
                    'subtitle' => (string) ($row['subtitle'] ?? ''),
                    'body_html' => (string) ($row['body_html'] ?? ''),
                    'contact_email' => (string) ($row['contact_email'] ?? ''),
                    'updated_at' => isset($row['updated_at']) && $row['updated_at'] !== null
                        ? (string) $row['updated_at']
                        : null,
                ];
            },
        );
    }

    /**
     * Enabled categories as a tree (max three levels). Missing table → empty tree.
     *
     * @return list<array{id:int,name:string,children:list<array{id:int,name:string,children:list<array{id:int,name:string,children:list<empty>}>}>}>
     */
    public function categoryTree(): array
    {
        /** @var list<array{id:int,name:string,children:list<mixed>}> */
        return $this->cache->remember(
            HomeCache::KEY_CATEGORY_TREE,
            HomeCache::ttlCategoryTree(),
            function (): array {
                try {
                    $rows = Category::where('status', 'enabled')
                        ->order('sort', 'asc')
                        ->order('id', 'asc')
                        ->select()
                        ->toArray();
                } catch (\Throwable $e) {
                    return [];
                }
                if (!is_array($rows)) {
                    return [];
                }
                return self::nest($rows);
            },
        );
    }

    /** @return list<array{id:int,image_url:string,link_url:?string,sort_order:int}> */
    public function banners(): array
    {
        return $this->cache->remember(
            HomeCache::KEY_BANNERS,
            HomeCache::ttlBanners(),
            function (): array {
                try {
                    return $this->bannerService->listPublic();
                } catch (\Throwable) {
                    return [];
                }
            },
        );
    }

    /**
     * Top-N published learning maps for the home recommendation rail.
     * Anonymous-friendly — enrollment lookup is omitted here; the detail
     * endpoint enriches it.
     *
     * @return list<array<string,mixed>>
     */
    public function recommendedMaps(int $limit = 3): array
    {
        $limit = max(1, min(10, $limit));
        try {
            $rows = \support\think\Db::name('learning_maps')
                ->where('status', 'published')
                ->order('id', 'desc')
                ->limit($limit)
                ->select()
                ->toArray();
        } catch (\Throwable) {
            return [];
        }
        if (!is_array($rows)) {
            return [];
        }
        $out = [];
        foreach ($rows as $r) {
            $out[] = self::shapeMapSummary($r) + ['enrollment' => null];
        }
        return $out;
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private static function shapeMapSummary(array $row): array
    {
        return [
            'id'            => (int) ($row['id'] ?? 0),
            'department_id' => (int) ($row['department_id'] ?? 0),
            'title'         => (string) ($row['title'] ?? ''),
            'summary'       => isset($row['summary']) ? (string) $row['summary'] : null,
            'cover_url'     => isset($row['cover_url']) ? (string) $row['cover_url'] : null,
            'objective'     => isset($row['objective']) ? (string) $row['objective'] : null,
            'audience'      => isset($row['audience']) ? (string) $row['audience'] : null,
            'status'        => (string) ($row['status'] ?? 'published'),
            'created_at'    => (string) ($row['created_at'] ?? ''),
            'updated_at'    => (string) ($row['updated_at'] ?? ''),
        ];
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @return list<array{id:int,name:string,children:list<mixed>}>
     */
    public static function nest(array $rows, int $parentId = 0, int $depth = 1): array
    {
        if ($depth > 3) {
            return [];
        }
        $out = [];
        foreach ($rows as $row) {
            $rawParent = $row['parent_id'] ?? 0;
            $pid = $rawParent === '' ? 0 : (int) $rawParent;
            if ($pid !== $parentId) {
                continue;
            }
            $id = (int) ($row['id'] ?? 0);
            $name = (string) ($row['name'] ?? '');
            if ($id <= 0 || $name === '') {
                continue;
            }
            $out[] = [
                'id' => $id,
                'name' => $name,
                'children' => self::nest($rows, $id, $depth + 1),
            ];
        }
        return $out;
    }
}
