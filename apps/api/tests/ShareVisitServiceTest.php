<?php

declare(strict_types=1);

namespace Tests;

use App\service\ShareVisitService;
use PHPUnit\Framework\TestCase;
use support\App;
use support\think\Db;
use Webman\ThinkOrm\ThinkOrm;

final class ShareVisitServiceTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        App::loadAllConfig(['route', 'container']);
        ThinkOrm::start(null);
    }

    protected function setUp(): void
    {
        Db::startTrans();
        Db::name('site_settings')->where('key', 'distribution_config')->update([
            'value' => json_encode(['enabled' => true], JSON_THROW_ON_ERROR),
        ]);
    }

    protected function tearDown(): void
    {
        Db::rollback();
    }

    public function testRecordVisitDelegatesToShareEntry(): void
    {
        $now = date('Y-m-d H:i:s');
        $learnerId = (int) Db::name('accounts')->insertGetId([
            'kind' => 'learner',
            'login' => '138' . random_int(10000000, 99999999),
            'password_hash' => 'x',
            'must_change_password' => 0,
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        Db::name('learners')->insert(['account_id' => $learnerId, 'created_at' => $now, 'updated_at' => $now]);
        $shares = new \App\service\ShareEntryService();
        $entry = $shares->create($learnerId, 'site', null);
        $visit = (new ShareVisitService($shares))->recordVisit($entry['plaintext_code'], null, '10.0.0.1', 'ua');
        self::assertSame((int) $entry['id'], $visit['share_entry_id']);
        self::assertNotSame('', $visit['visitor_token']);
    }
}
