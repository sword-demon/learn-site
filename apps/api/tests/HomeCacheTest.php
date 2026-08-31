<?php

declare(strict_types=1);

namespace Tests;

use App\service\HomeService;
use App\support\cache\HomeCache;
use PHPUnit\Framework\TestCase;
use support\App;
use support\think\Db;
use Webman\ThinkOrm\ThinkOrm;

final class HomeCacheTest extends TestCase
{
    private InMemoryRedis $redis;

    public static function setUpBeforeClass(): void
    {
        App::loadAllConfig(['route', 'container']);
        ThinkOrm::start(null);
    }

    protected function setUp(): void
    {
        putenv('HOME_CACHE_ENABLED=1');
        $this->redis = new InMemoryRedis();
        RedisStub::install($this->redis);
        Db::startTrans();
    }

    protected function tearDown(): void
    {
        Db::rollback();
    }

    public function testSiteIntroIsCachedUntilForgotten(): void
    {
        $home = new HomeService();
        $first = $home->siteIntro();
        $second = $home->siteIntro();
        self::assertSame($first, $second);
        self::assertNotFalse($this->redis->get('cache:home:site_intro'));

        (new HomeCache())->forget(HomeCache::KEY_SITE_INTRO);
        self::assertFalse($this->redis->get('cache:home:site_intro'));
    }
}
