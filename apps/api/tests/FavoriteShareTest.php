<?php

declare(strict_types=1);

namespace Tests;

use App\service\BusinessException;
use App\service\FavoriteService;
use App\service\SharePosterService;
use PHPUnit\Framework\TestCase;
use support\App;
use support\think\Db;
use Throwable;
use Webman\ThinkOrm\ThinkOrm;

final class FavoriteShareTest extends TestCase
{
    private int $courseId;
    private int $learnerId;

    public static function setUpBeforeClass(): void
    {
        App::loadAllConfig(['route', 'container']);
        ThinkOrm::start(null);
    }

    protected function setUp(): void
    {
        Db::startTrans();
        try {
            $this->seedFixture();
        } catch (Throwable $exception) {
            Db::rollback();
            throw $exception;
        }
    }

    protected function tearDown(): void
    {
        Db::rollback();
    }

    public function testFavoriteWritesAndRemovalAreIdempotent(): void
    {
        $service = new FavoriteService();
        self::assertTrue(method_exists($service, 'remove'), 'FavoriteService must own removal idempotency');

        self::assertTrue($service->add($this->learnerId, $this->courseId));
        self::assertFalse($service->add($this->learnerId, $this->courseId));
        self::assertSame(1, Db::name('favorites')
            ->where('learner_id', $this->learnerId)
            ->where('course_id', $this->courseId)
            ->count());
        self::assertTrue($service->remove($this->learnerId, $this->courseId));
        self::assertFalse($service->remove($this->learnerId, $this->courseId));
    }

    public function testFavoriteListRetainsUnpublishedCourseAsUnavailable(): void
    {
        $service = new FavoriteService();
        self::assertTrue(method_exists($service, 'list'), 'FavoriteService must own favorite queries');
        $service->add($this->learnerId, $this->courseId);
        Db::name('courses')->where('id', $this->courseId)->update(['status' => 'unpublished']);

        $result = $service->list($this->learnerId, 1, 20);

        self::assertSame(1, $result['total']);
        self::assertSame('unpublished', $result['items'][0]['status']);
        self::assertSame('分享课程', $result['items'][0]['title']);
    }

    public function testPosterPersistsCourseAndCurrentPriceSnapshots(): void
    {
        self::assertTrue(class_exists(SharePosterService::class), 'SharePosterService must own share snapshots');
        $service = new SharePosterService();

        $result = $service->createPoster($this->courseId);

        self::assertSame('/courses/' . $this->courseId, $result['share_url']);
        self::assertSame('ready', $result['render_status']);
        self::assertSame([
            'cover_url' => '/api/media/covers/2026/08/aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa.png',
            'title' => '分享课程',
            'teacher_name' => '分享教师',
            'price_label' => '¥69.00',
        ], $result['snapshot']);

        $stored = Db::name('share_posters')->where('id', $result['poster_id'])->find();
        self::assertIsArray($stored);
        self::assertSame('分享课程', $stored['title_snapshot']);
        self::assertSame('分享教师', $stored['teacher_snapshot']);
        self::assertSame(69.0, (float) $stored['price_snapshot']);
        self::assertSame('ready', $stored['render_status']);
    }

    public function testPosterFailureStillReturnsStableShareLink(): void
    {
        self::assertTrue(class_exists(SharePosterService::class), 'SharePosterService must provide fallback');
        $service = new SharePosterService(static fn(array $snapshot): bool => false);

        $result = $service->createPoster($this->courseId);

        self::assertSame('/courses/' . $this->courseId, $result['share_url']);
        self::assertSame('failed', $result['render_status']);
        self::assertSame('failed', Db::name('share_posters')->where('id', $result['poster_id'])->value('render_status'));
    }

    public function testUnpublishedCourseCannotCreateNewShareEntry(): void
    {
        self::assertTrue(class_exists(SharePosterService::class), 'SharePosterService must protect unpublished courses');
        Db::name('courses')->where('id', $this->courseId)->update(['status' => 'unpublished']);

        try {
            (new SharePosterService())->createShareLink($this->courseId);
            self::fail('Expected unpublished course share to be rejected');
        } catch (BusinessException $exception) {
            self::assertSame('NOT_FOUND', $exception->apiCode);
            self::assertSame('COURSE_NOT_FOUND', $exception->getMessage());
        }
    }

    private function seedFixture(): void
    {
        $now = date('Y-m-d H:i:s');
        $suffix = bin2hex(random_bytes(5));
        $staffId = (int) Db::name('accounts')->insertGetId([
            'kind' => 'staff',
            'login' => "share-staff-{$suffix}",
            'password_hash' => password_hash('test-password', PASSWORD_DEFAULT),
            'must_change_password' => 0,
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        Db::name('staff_users')->insert([
            'account_id' => $staffId,
            'is_super_admin' => 1,
            'department_id' => null,
            'display_name' => '分享管理员',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $departmentId = (int) Db::name('departments')->insertGetId([
            'parent_id' => null,
            'name' => "分享部门 {$suffix}",
            'path' => '/',
            'depth' => 1,
            'sort' => 0,
            'status' => 'enabled',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        Db::name('departments')->where('id', $departmentId)->update(['path' => "/{$departmentId}"]);
        $categoryId = (int) Db::name('categories')->insertGetId([
            'parent_id' => 0,
            'name' => "分享分类 {$suffix}",
            'path' => '/',
            'depth' => 1,
            'sort' => 0,
            'status' => 'enabled',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $this->courseId = (int) Db::name('courses')->insertGetId([
            'department_id' => $departmentId,
            'category_id' => $categoryId,
            'title' => '分享课程',
            'cover_url' => '/api/media/covers/2026/08/aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa.png',
            'teacher_name' => '分享教师',
            'summary' => '分享摘要',
            'intro_rich_text' => '<p>分享介绍</p>',
            'status' => 'published',
            'price_mode' => 'paid',
            'list_price' => 99,
            'sale_price' => 69,
            'sale_start_at' => date('Y-m-d H:i:s', time() - 3600),
            'sale_end_at' => date('Y-m-d H:i:s', time() + 3600),
            'created_by_staff_id' => $staffId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $this->learnerId = (int) Db::name('accounts')->insertGetId([
            'kind' => 'learner',
            'login' => '139' . str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT),
            'password_hash' => password_hash('test-password', PASSWORD_DEFAULT),
            'must_change_password' => 0,
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        Db::name('learners')->insert([
            'account_id' => $this->learnerId,
            'nickname' => '收藏学员',
            'avatar_url' => null,
            'show_on_course' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
}
