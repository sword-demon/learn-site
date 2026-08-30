<?php

declare(strict_types=1);

namespace Tests;

use App\controller\admin\CheckinController as AdminCheckinController;
use App\controller\learner\CheckinController;
use App\middleware\Authorize;
use App\service\BusinessException;
use App\service\CheckinService;
use App\support\ApiResponse;
use PHPUnit\Framework\TestCase;
use support\App;
use support\Request;
use support\think\Db;
use think\db\BaseQuery;
use Webman\ThinkOrm\ThinkOrm;

final class DailyCheckinTest extends TestCase
{
    private int $learnerA;
    private int $learnerB;
    private int $staffId;

    public static function setUpBeforeClass(): void
    {
        App::loadAllConfig(['route', 'container']);
        ThinkOrm::start(null);
    }

    protected function setUp(): void
    {
        Db::startTrans();
        $this->learnerA = $this->insertLearner();
        $this->learnerB = $this->insertLearner();
        $this->staffId = $this->insertStaff();
    }

    protected function tearDown(): void
    {
        Db::rollback();
    }

    public function testCreateCheckinStoresSanitizedPlan(): void
    {
        $service = new CheckinService();
        $result = $service->create($this->learnerA, '<p>今日计划</p><script>alert(1)</script>');

        self::assertSame(
            $this->learnerA,
            (int) Db::name('learner_daily_checkins')->where('id', $result['id'])->value('learner_id'),
        );
        self::assertStringContainsString('今日计划', $result['plan_html']);
        self::assertStringNotContainsString('<script>', $result['plan_html']);
        self::assertSame(
            1,
            Db::name('audit_log')->where('action', 'checkin.create')->where('target_id', $result['id'])->count(),
        );
    }

    public function testDuplicateCheckinRejected(): void
    {
        $service = new CheckinService();
        $first = $service->create($this->learnerA, '<p>第一次</p>');

        try {
            $service->create($this->learnerA, '<p>第二次</p>');
            self::fail('Expected duplicate check-in to be rejected');
        } catch (BusinessException $exception) {
            self::assertSame('ALREADY_CHECKED_IN', $exception->apiCode);
            self::assertSame('ALREADY_CHECKED_IN', $exception->getMessage());
        }

        self::assertSame(
            1,
            Db::name('audit_log')
                ->where('action', 'checkin.duplicate_rejected')
                ->where('actor_id', $this->learnerA)
                ->where('target_id', $first['id'])
                ->count(),
        );
    }

    public function testUnexpectedInsertFailureIsNotReportedAsDuplicate(): void
    {
        $failNextCheckinInsert = true;
        Db::event('after_insert', static function (BaseQuery $query) use (&$failNextCheckinInsert): void {
            if ($failNextCheckinInsert && $query->getTable() === 'learner_daily_checkins') {
                $failNextCheckinInsert = false;
                throw new \RuntimeException('simulated check-in insert failure');
            }
        });

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('simulated check-in insert failure');
        (new CheckinService())->create($this->learnerA, '<p>计划</p>');
    }

    public function testCreateRollsBackWhenAuditWriteFails(): void
    {
        Db::rollback();
        $learnerId = $this->insertLearner();
        $failNextAuditInsert = true;
        Db::event('after_insert', static function (BaseQuery $query) use (&$failNextAuditInsert): void {
            if ($failNextAuditInsert && $query->getTable() === 'audit_log') {
                $failNextAuditInsert = false;
                throw new \RuntimeException('simulated audit failure');
            }
        });

        try {
            (new CheckinService())->create($learnerId, '<p>计划</p>');
            self::fail('Expected audit write failure');
        } catch (\RuntimeException $exception) {
            self::assertSame('simulated audit failure', $exception->getMessage());
            self::assertSame(
                0,
                Db::name('learner_daily_checkins')->where('learner_id', $learnerId)->count(),
            );
        } finally {
            $this->deleteLearnerFixture($learnerId);
        }

        Db::startTrans();
        $this->learnerA = $this->insertLearner();
        $this->learnerB = $this->insertLearner();
        $this->staffId = $this->insertStaff();
    }

    public function testEmptyPlanRejected(): void
    {
        $service = new CheckinService();
        $this->expectException(\App\service\BusinessException::class);
        $this->expectExceptionMessage('PLAN_HTML_REQUIRED');
        $service->create($this->learnerA, '<p><br></p>');
    }

    public function testTodayStatusReflectsCheckin(): void
    {
        $service = new CheckinService();
        $before = $service->getTodayStatus($this->learnerA);
        self::assertFalse($before['checked_in']);

        $service->create($this->learnerA, '<p>计划</p>');
        $after = $service->getTodayStatus($this->learnerA);
        self::assertTrue($after['checked_in']);
        self::assertNotNull($after['record']);
        self::assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+08:00$/',
            (string) $after['record']['checked_in_at'],
        );
    }

    public function testLearnerListIsScopedAndOrdered(): void
    {
        $service = new CheckinService();
        $service->create($this->learnerA, '<p>A-今日</p>');

        Db::name('learner_daily_checkins')->insert([
            'learner_id' => $this->learnerA,
            'checkin_date' => date('Y-m-d', strtotime('-1 day')),
            'plan_html' => '<p>A-昨日</p>',
            'checked_in_at' => date('Y-m-d H:i:s', strtotime('-1 day')),
            'created_at' => date('Y-m-d H:i:s', strtotime('-1 day')),
        ]);

        $list = $service->listForLearner($this->learnerA, ['page' => 1, 'limit' => 20]);
        self::assertSame(2, $list['total']);
        self::assertSame(date('Y-m-d'), $list['items'][0]['checkin_date']);

        $other = $service->listForLearner($this->learnerB, ['page' => 1, 'limit' => 20]);
        self::assertSame(0, $other['total']);
    }

    public function testLearnerCannotReadOthersRecord(): void
    {
        $service = new CheckinService();
        $row = $service->create($this->learnerA, '<p>私密</p>');

        $this->expectException(\App\service\BusinessException::class);
        $this->expectExceptionMessage('CHECKIN_NOT_FOUND');
        $service->getForLearner($this->learnerB, (int) $row['id']);
    }

    public function testAdminListFilterAndDeleteAllowsRecheckin(): void
    {
        $service = new CheckinService();
        $row = $service->create($this->learnerA, '<p>待删</p>');

        $list = $service->listForAdmin([
            'learner_id' => $this->learnerA,
            'page' => 1,
            'limit' => 20,
        ]);
        self::assertSame(1, $list['total']);
        self::assertStringContainsString('待删', $list['items'][0]['plan_summary']);

        $service->deleteForAdmin($this->staffId, (int) $row['id']);
        self::assertSame(
            1,
            Db::name('audit_log')->where('action', 'checkin.delete')->where('target_id', $row['id'])->count(),
        );

        $status = $service->getTodayStatus($this->learnerA);
        self::assertFalse($status['checked_in']);

        $again = $service->create($this->learnerA, '<p>重签</p>');
        self::assertNotSame($row['id'], $again['id']);
    }

    public function testDeleteRollsBackWhenAuditWriteFails(): void
    {
        Db::rollback();
        $learnerId = $this->insertLearner();
        $staffId = $this->insertStaff();
        $row = (new CheckinService())->create($learnerId, '<p>保留</p>');
        $failNextAuditInsert = true;
        Db::event('after_insert', static function (BaseQuery $query) use (&$failNextAuditInsert): void {
            if ($failNextAuditInsert && $query->getTable() === 'audit_log') {
                $failNextAuditInsert = false;
                throw new \RuntimeException('simulated delete audit failure');
            }
        });

        try {
            (new CheckinService())->deleteForAdmin($staffId, (int) $row['id']);
            self::fail('Expected delete audit write failure');
        } catch (\RuntimeException $exception) {
            self::assertSame('simulated delete audit failure', $exception->getMessage());
            self::assertSame(
                1,
                Db::name('learner_daily_checkins')->where('id', $row['id'])->count(),
            );
        } finally {
            Db::name('audit_log')->where('target_id', $row['id'])->delete();
            Db::name('learner_daily_checkins')->where('id', $row['id'])->delete();
            $this->deleteStaffFixture($staffId);
            $this->deleteLearnerFixture($learnerId);
        }

        Db::startTrans();
        $this->learnerA = $this->insertLearner();
        $this->learnerB = $this->insertLearner();
        $this->staffId = $this->insertStaff();
    }

    public function testCheckinRoutesRequireManagePermission(): void
    {
        self::assertSame('checkin.manage', Authorize::permissionFor('/api/admin/v1/checkins', 'GET'));
        self::assertSame('checkin.manage', Authorize::permissionFor('/api/admin/v1/checkins/42', 'DELETE'));
    }

    public function testLearnerStoreEndpointReturns201(): void
    {
        $body = json_encode(['plan_html' => '<p>API 签到</p>'], JSON_THROW_ON_ERROR);
        $request = new Request(
            "POST /api/learner/v1/checkins HTTP/1.1\r\n"
            . "Host: test\r\nContent-Type: application/json\r\n\r\n"
            . $body,
        );
        /** @phpstan-ignore-next-line */
        $request->account_id = $this->learnerA;

        $response = (new CheckinController(new CheckinService()))->store($request);
        self::assertSame(201, $response->getStatusCode());
        $payload = json_decode((string) $response->rawBody(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('2026-', substr((string) $payload['data']['checked_in_at'], 0, 5));
        self::assertStringContainsString('T', (string) $payload['data']['checked_in_at']);
        self::assertStringEndsWith('+08:00', (string) $payload['data']['checked_in_at']);
    }

    public function testEmptyPlanEndpointReturns422(): void
    {
        $body = json_encode(['plan_html' => '<p><br></p>'], JSON_THROW_ON_ERROR);
        $request = new Request(
            "POST /api/learner/v1/checkins HTTP/1.1\r\nHost: test\r\n"
            . "Content-Type: application/json\r\n\r\n{$body}",
        );
        /** @phpstan-ignore-next-line */
        $request->account_id = $this->learnerA;

        $response = (new CheckinController(new CheckinService()))->store($request);
        self::assertSame(422, $response->getStatusCode());
        $payload = json_decode((string) $response->rawBody(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(ApiResponse::VALIDATION_FAILED, $payload['error']['code']);
        self::assertSame('PLAN_HTML_REQUIRED', $payload['error']['message']);
    }

    public function testAdminDestroyEndpointDeletesRecord(): void
    {
        $service = new CheckinService();
        $row = $service->create($this->learnerA, '<p>删除测试</p>');

        $request = new Request("DELETE /api/admin/v1/checkins/{$row['id']} HTTP/1.1\r\nHost: test\r\n\r\n");
        /** @phpstan-ignore-next-line */
        $request->account_id = $this->staffId;
        $response = (new AdminCheckinController(new CheckinService()))->destroy($request, (string) $row['id']);
        self::assertSame(204, $response->getStatusCode());
        self::assertSame('', (string) $response->rawBody());
        self::assertSame(0, Db::name('learner_daily_checkins')->where('id', $row['id'])->count());
    }

    private function insertLearner(): int
    {
        $now = date('Y-m-d H:i:s');
        $id = (int) Db::name('accounts')->insertGetId([
            'kind' => 'learner',
            'login' => '13' . random_int(100000000, 999999999),
            'password_hash' => 'hash',
            'must_change_password' => 0,
            'status' => 'active',
            'last_login_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        Db::name('learners')->insert([
            'account_id' => $id,
            'nickname' => '学员' . $id,
            'avatar_url' => null,
            'show_on_course' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        return $id;
    }

    private function insertStaff(): int
    {
        $now = date('Y-m-d H:i:s');
        $id = (int) Db::name('accounts')->insertGetId([
            'kind' => 'staff',
            'login' => 'checkin-admin-' . bin2hex(random_bytes(4)),
            'password_hash' => 'hash',
            'must_change_password' => 0,
            'status' => 'active',
            'last_login_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        Db::name('staff_users')->insert([
            'account_id' => $id,
            'is_super_admin' => 1,
            'department_id' => null,
            'display_name' => 'Checkin Admin',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        return $id;
    }

    private function deleteLearnerFixture(int $learnerId): void
    {
        Db::name('audit_log')->where('actor_id', $learnerId)->delete();
        Db::name('learner_daily_checkins')->where('learner_id', $learnerId)->delete();
        Db::name('learners')->where('account_id', $learnerId)->delete();
        Db::name('accounts')->where('id', $learnerId)->delete();
    }

    private function deleteStaffFixture(int $staffId): void
    {
        Db::name('audit_log')->where('actor_id', $staffId)->delete();
        Db::name('staff_users')->where('account_id', $staffId)->delete();
        Db::name('accounts')->where('id', $staffId)->delete();
    }
}
