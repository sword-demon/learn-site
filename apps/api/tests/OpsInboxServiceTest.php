<?php

declare(strict_types=1);

namespace Tests;

use App\service\BusinessException;
use App\service\OpsInboxService;
use PHPUnit\Framework\TestCase;
use support\App;
use support\think\Db;
use Webman\ThinkOrm\ThinkOrm;

final class OpsInboxServiceTest extends TestCase
{
    private int $staffId;
    private int $categoryId;

    public static function setUpBeforeClass(): void
    {
        App::loadAllConfig(['route', 'container']);
        ThinkOrm::start(null);
    }

    protected function setUp(): void
    {
        Db::startTrans();
        $now = date('Y-m-d H:i:s');
        $this->staffId = (int) Db::name('accounts')->insertGetId([
            'kind' => 'staff',
            'login' => 'ops-inbox-' . bin2hex(random_bytes(4)),
            'password_hash' => 'hash',
            'must_change_password' => 0,
            'status' => 'active',
            'last_login_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        Db::name('staff_users')->insert([
            'account_id' => $this->staffId,
            'is_super_admin' => 1,
            'department_id' => null,
            'display_name' => 'Ops Inbox Test',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $this->categoryId = (int) Db::name('categories')->insertGetId([
            'parent_id' => 0,
            'name' => 'ops-inbox-' . bin2hex(random_bytes(4)),
            'path' => '/',
            'depth' => 1,
            'sort' => 0,
            'status' => 'enabled',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    protected function tearDown(): void
    {
        Db::rollback();
    }

    public function testListSortsOldestRowsWithinASourceAndHonoursPermissionFilter(): void
    {
        $old = date('Y-m-d H:i:s', strtotime('-24 hours'));
        $new = date('Y-m-d H:i:s');
        $oldId = $this->insertCourse('旧课程', $old);
        $this->insertCourse('新课程', $new);

        $result = (new OpsInboxService())->list($this->staffId, ['course.view'], [
            'source_type' => 'course_unpublished',
            'limit' => 50,
        ]);

        self::assertSame(2, $result['total']);
        self::assertSame((string) $oldId, $result['items'][0]['source_key']);
        self::assertSame('1 天', $result['items'][0]['age_label']);
        self::assertArrayNotHasKey('payment_unknown', $result['counts_by_source']);
    }

    public function testTransitionRejectsMissingSourceAndTooShortSnooze(): void
    {
        $this->insertCourse('待处理课程', date('Y-m-d H:i:s'));
        $service = new OpsInboxService();

        try {
            $service->transitionState($this->staffId, 'course_unpublished:999999999', ['to_state' => 'resolved']);
            self::fail('missing source should be rejected');
        } catch (BusinessException $exception) {
            self::assertSame('OPS_NOT_FOUND', $exception->getMessage());
        }

        $courseId = $this->insertCourse('短搁置课程', date('Y-m-d H:i:s'));
        $this->expectExceptionMessage('OPS_SNOOZE_TOO_SHORT');
        $service->transitionState($this->staffId, 'course_unpublished:' . $courseId, [
            'to_state' => 'snoozed',
            'snooze_until' => date(DATE_ATOM, time() + 30),
        ]);
    }

    public function testAutoRetryPersistsExponentialScheduleWithoutImmediateRequeue(): void
    {
        $now = date('Y-m-d H:i:s');
        $dispatchId = (int) Db::name('notification_dispatches')->insertGetId([
            'type' => 'announcement',
            'title' => '失败通知',
            'body' => '正文',
            'sender_staff_id' => $this->staffId,
            'recipient_mode' => 'all',
            'recipient_count' => 0,
            'fan_out_status' => 'failed',
            'fan_out_done_count' => 0,
            'fan_out_error' => 'temporary gateway error',
            'fan_out_started_at' => null,
            'fan_out_finished_at' => $now,
            'created_at' => $now,
            'retry_count' => 0,
            'retry_at' => null,
        ]);

        self::assertSame('scheduled', (new OpsInboxService())->maybeAutoRetry($dispatchId));
        $row = Db::name('notification_dispatches')->where('id', $dispatchId)->find();
        self::assertSame(1, (int) $row['retry_count']);
        self::assertNotNull($row['retry_at']);
    }

    public function testRetryRejectsDispatchOutsideActorScope(): void
    {
        $now = date('Y-m-d H:i:s');
        $insideDepartmentId = (int) Db::name('departments')->insertGetId([
            'parent_id' => null,
            'name' => 'ops-inbox-inside-' . bin2hex(random_bytes(3)),
            'path' => '/',
            'depth' => 1,
            'sort' => 0,
            'status' => 'enabled',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $outsideDepartmentId = (int) Db::name('departments')->insertGetId([
            'parent_id' => null,
            'name' => 'ops-inbox-outside-' . bin2hex(random_bytes(3)),
            'path' => '/',
            'depth' => 1,
            'sort' => 0,
            'status' => 'enabled',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $permissionId = (int) Db::name('permissions')->where('code', 'notification.manage')->value('id');
        $roleId = (int) Db::name('roles')->insertGetId([
            'name' => 'ops-inbox-scope-' . bin2hex(random_bytes(3)),
            'code' => 'ops-inbox-scope-' . bin2hex(random_bytes(3)),
            'data_scope' => 'dept',
            'status' => 'enabled',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        Db::name('role_permission')->insert(['role_id' => $roleId, 'permission_id' => $permissionId]);

        $actorId = (int) Db::name('accounts')->insertGetId([
            'kind' => 'staff',
            'login' => 'ops-inbox-scope-actor-' . bin2hex(random_bytes(3)),
            'password_hash' => 'hash',
            'must_change_password' => 0,
            'status' => 'active',
            'last_login_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        Db::name('staff_users')->insert([
            'account_id' => $actorId,
            'is_super_admin' => 0,
            'department_id' => $insideDepartmentId,
            'display_name' => 'Ops Inbox Scoped Actor',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        Db::name('staff_role')->insert(['staff_user_id' => $actorId, 'role_id' => $roleId]);

        $senderId = (int) Db::name('accounts')->insertGetId([
            'kind' => 'staff',
            'login' => 'ops-inbox-scope-sender-' . bin2hex(random_bytes(3)),
            'password_hash' => 'hash',
            'must_change_password' => 0,
            'status' => 'active',
            'last_login_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        Db::name('staff_users')->insert([
            'account_id' => $senderId,
            'is_super_admin' => 0,
            'department_id' => $outsideDepartmentId,
            'display_name' => 'Ops Inbox Out-of-Scope Sender',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $dispatchId = (int) Db::name('notification_dispatches')->insertGetId([
            'type' => 'announcement',
            'title' => '范围外失败通知',
            'body' => '正文',
            'sender_staff_id' => $senderId,
            'recipient_mode' => 'all',
            'recipient_count' => 0,
            'fan_out_status' => 'failed',
            'fan_out_done_count' => 0,
            'fan_out_error' => 'temporary gateway error',
            'fan_out_started_at' => null,
            'fan_out_finished_at' => $now,
            'resource_type' => null,
            'resource_id' => null,
            'recipient_snapshot_max_id' => null,
            'created_at' => $now,
            'retry_count' => 0,
            'retry_at' => null,
        ]);

        try {
            (new OpsInboxService())->retry($actorId, (string) $dispatchId, ['notification.manage']);
            self::fail('out-of-scope dispatch should be rejected');
        } catch (BusinessException $exception) {
            self::assertSame('FORBIDDEN', $exception->apiCode);
            self::assertSame('DEPARTMENT_OUT_OF_SCOPE', $exception->getMessage());
        }
    }

    private function insertCourse(string $title, string $createdAt): int
    {
        return (int) Db::name('courses')->insertGetId([
            'department_id' => null,
            'category_id' => $this->categoryId,
            'title' => $title,
            'cover_url' => null,
            'teacher_name' => '测试讲师',
            'summary' => '',
            'intro_rich_text' => null,
            'status' => 'draft',
            'price_mode' => 'free',
            'list_price' => 0,
            'sale_price' => 0,
            'sale_start_at' => null,
            'sale_end_at' => null,
            'created_by_staff_id' => $this->staffId,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);
    }
}
