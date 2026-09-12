<?php

declare(strict_types=1);

namespace Tests;

use App\service\BusinessException;
use App\service\CourseService;
use PHPUnit\Framework\TestCase;
use support\App;
use support\think\Db;
use Webman\ThinkOrm\ThinkOrm;

final class CourseStartupPolicyTest extends TestCase
{
    private int $staffId;
    private int $departmentId;
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
            'login' => 'startup-admin-' . bin2hex(random_bytes(4)),
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
            'display_name' => 'Startup Admin',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $this->departmentId = (int) Db::name('departments')->insertGetId([
            'parent_id' => null,
            'name' => 'startup-dept-' . bin2hex(random_bytes(3)),
            'path' => '/',
            'depth' => 1,
            'sort' => 0,
            'status' => 'enabled',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        Db::name('departments')->where('id', $this->departmentId)->update([
            'path' => '/' . $this->departmentId,
        ]);
        $this->categoryId = (int) Db::name('categories')->insertGetId([
            'parent_id' => 0,
            'name' => 'startup-cat-' . bin2hex(random_bytes(3)),
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

    public function testSaveValidStartupPolicyAndReadItBack(): void
    {
        $courseId = $this->insertCourse();

        $updated = (new CourseService())->updateCourse(
            $courseId,
            [
                'idle_threshold_hours' => 48,
                'reminder_frequency_hours' => 24,
                'reminder_cap' => 2,
            ],
            $this->staffId,
        );

        self::assertSame(48, $updated['idle_threshold_hours']);
        self::assertSame(24, $updated['reminder_frequency_hours']);
        self::assertSame(2, $updated['reminder_cap']);

        $tree = (new CourseService())->getCourseTree($courseId);
        self::assertSame(48, $tree['idle_threshold_hours']);
        self::assertSame(24, $tree['reminder_frequency_hours']);
        self::assertSame(2, $tree['reminder_cap']);
    }

    public function testRejectsZeroNegativeAndContradictoryPolicyValues(): void
    {
        $courseId = $this->insertCourse();
        $service = new CourseService();

        foreach (
            [
                ['idle_threshold_hours' => 0, 'code' => 'IDLE_THRESHOLD_INVALID'],
                ['idle_threshold_hours' => -1, 'code' => 'IDLE_THRESHOLD_INVALID'],
                ['reminder_frequency_hours' => 0, 'code' => 'REMINDER_FREQUENCY_INVALID'],
                ['reminder_frequency_hours' => -8, 'code' => 'REMINDER_FREQUENCY_INVALID'],
                ['reminder_cap' => 0, 'code' => 'REMINDER_CAP_INVALID'],
                ['reminder_cap' => -2, 'code' => 'REMINDER_CAP_INVALID'],
                [
                    'idle_threshold_hours' => 24,
                    'reminder_frequency_hours' => 48,
                    'code' => 'REMINDER_FREQUENCY_EXCEEDS_THRESHOLD',
                ],
            ] as $case
        ) {
            $code = $case['code'];
            unset($case['code']);
            try {
                $service->updateCourse($courseId, $case, $this->staffId);
                self::fail('Expected invalid startup policy to be rejected for ' . $code);
            } catch (BusinessException $exception) {
                self::assertSame('VALIDATION_FAILED', $exception->apiCode);
                self::assertSame($code, $exception->getMessage());
            }
        }

        $tree = $service->getCourseTree($courseId);
        self::assertSame(72, $tree['idle_threshold_hours']);
        self::assertSame(72, $tree['reminder_frequency_hours']);
        self::assertSame(3, $tree['reminder_cap']);
    }

    public function testPartialPolicyUpdateValidatesAgainstStoredValues(): void
    {
        $courseId = $this->insertCourse();
        $service = new CourseService();
        $service->updateCourse(
            $courseId,
            [
                'idle_threshold_hours' => 48,
                'reminder_frequency_hours' => 24,
                'reminder_cap' => 2,
            ],
            $this->staffId,
        );

        try {
            $service->updateCourse($courseId, ['idle_threshold_hours' => 12], $this->staffId);
            self::fail('Expected a partial idle-threshold drop to keep the stored frequency in view.');
        } catch (BusinessException $exception) {
            self::assertSame('VALIDATION_FAILED', $exception->apiCode);
            self::assertSame('REMINDER_FREQUENCY_EXCEEDS_THRESHOLD', $exception->getMessage());
        }

        $tree = $service->getCourseTree($courseId);
        self::assertSame(48, $tree['idle_threshold_hours']);
        self::assertSame(24, $tree['reminder_frequency_hours']);
        self::assertSame(2, $tree['reminder_cap']);
    }

    public function testExistingCourseUsesDocumentedDefaultsWithoutTouchingLearningFacts(): void
    {
        $courseId = $this->insertCourse();
        $now = date('Y-m-d H:i:s');
        $learnerId = (int) Db::name('accounts')->insertGetId([
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
            'account_id' => $learnerId,
            'nickname' => '启动策略学员',
            'avatar_url' => null,
            'show_on_course' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $entitlementId = (int) Db::name('course_entitlements')->insertGetId([
            'learner_id' => $learnerId,
            'course_id' => $courseId,
            'source' => 'purchase',
            'order_id' => null,
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $tree = (new CourseService())->getCourseTree($courseId);

        self::assertSame(72, $tree['idle_threshold_hours']);
        self::assertSame(72, $tree['reminder_frequency_hours']);
        self::assertSame(3, $tree['reminder_cap']);
        $entitlement = Db::name('course_entitlements')->where('id', $entitlementId)->find();
        self::assertSame($now, (string) $entitlement['created_at']);
        self::assertSame($now, (string) $entitlement['updated_at']);
        self::assertSame('active', (string) $entitlement['status']);
        self::assertSame(0, (int) Db::name('lesson_progresses')->where('learner_id', $learnerId)->count());
        self::assertSame(0, (int) Db::name('course_enrollments')->where('learner_id', $learnerId)->where('course_id', $courseId)->count());
    }

    public function testOutOfScopeStaffCannotUpdateStartupPolicy(): void
    {
        $courseId = $this->insertCourse();
        $now = date('Y-m-d H:i:s');
        $outsideDepartmentId = (int) Db::name('departments')->insertGetId([
            'parent_id' => null,
            'name' => 'startup-outside-' . bin2hex(random_bytes(3)),
            'path' => '/',
            'depth' => 1,
            'sort' => 0,
            'status' => 'enabled',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        Db::name('departments')->where('id', $outsideDepartmentId)->update([
            'path' => '/' . $outsideDepartmentId,
        ]);
        $outsiderId = (int) Db::name('accounts')->insertGetId([
            'kind' => 'staff',
            'login' => 'startup-out-' . bin2hex(random_bytes(4)),
            'password_hash' => 'hash',
            'must_change_password' => 0,
            'status' => 'active',
            'last_login_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        Db::name('staff_users')->insert([
            'account_id' => $outsiderId,
            'is_super_admin' => 0,
            'department_id' => $outsideDepartmentId,
            'display_name' => 'Outside Staff',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $roleId = (int) Db::name('roles')->insertGetId([
            'name' => 'startup-out-role',
            'code' => 'startup-out-' . bin2hex(random_bytes(3)),
            'data_scope' => 'dept',
            'status' => 'enabled',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        Db::name('staff_role')->insert([
            'staff_user_id' => $outsiderId,
            'role_id' => $roleId,
        ]);

        try {
            (new CourseService())->updateCourse(
                $courseId,
                ['idle_threshold_hours' => 24],
                $outsiderId,
            );
            self::fail('Expected out-of-scope staff to be rejected.');
        } catch (BusinessException $exception) {
            self::assertSame('FORBIDDEN', $exception->apiCode);
            self::assertSame('DEPARTMENT_OUT_OF_SCOPE', $exception->getMessage());
        }

        $tree = (new CourseService())->getCourseTree($courseId);
        self::assertSame(72, $tree['idle_threshold_hours']);
    }

    public function testOutOfScopeStaffCannotReadStartupPolicy(): void
    {
        $courseId = $this->insertCourse();
        $now = date('Y-m-d H:i:s');
        $outsideDepartmentId = (int) Db::name('departments')->insertGetId([
            'parent_id' => null,
            'name' => 'startup-read-out-' . bin2hex(random_bytes(3)),
            'path' => '/',
            'depth' => 1,
            'sort' => 0,
            'status' => 'enabled',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        Db::name('departments')->where('id', $outsideDepartmentId)->update([
            'path' => '/' . $outsideDepartmentId,
        ]);
        $outsiderId = (int) Db::name('accounts')->insertGetId([
            'kind' => 'staff',
            'login' => 'startup-read-' . bin2hex(random_bytes(4)),
            'password_hash' => 'hash',
            'must_change_password' => 0,
            'status' => 'active',
            'last_login_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        Db::name('staff_users')->insert([
            'account_id' => $outsiderId,
            'is_super_admin' => 0,
            'department_id' => $outsideDepartmentId,
            'display_name' => 'Outside Reader',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $roleId = (int) Db::name('roles')->insertGetId([
            'name' => 'startup-read-role',
            'code' => 'startup-read-' . bin2hex(random_bytes(3)),
            'data_scope' => 'dept',
            'status' => 'enabled',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        Db::name('staff_role')->insert([
            'staff_user_id' => $outsiderId,
            'role_id' => $roleId,
        ]);

        try {
            (new CourseService())->getCourseTree($courseId, $outsiderId);
            self::fail('Expected out-of-scope staff to be rejected on policy read.');
        } catch (BusinessException $exception) {
            self::assertSame('FORBIDDEN', $exception->apiCode);
            self::assertSame('DEPARTMENT_OUT_OF_SCOPE', $exception->getMessage());
        }

        try {
            (new CourseService())->getCourseTree($courseId, 0);
            self::fail('Expected a missing actor id to fail closed on policy read.');
        } catch (BusinessException $exception) {
            self::assertSame('FORBIDDEN', $exception->apiCode);
        }
    }

    private function insertCourse(): int
    {
        $now = date('Y-m-d H:i:s');

        return (int) Db::name('courses')->insertGetId([
            'department_id' => $this->departmentId,
            'category_id' => $this->categoryId,
            'title' => '启动策略测试课 ' . bin2hex(random_bytes(3)),
            'cover_url' => null,
            'teacher_name' => '测试教师',
            'summary' => '启动策略测试摘要',
            'intro_rich_text' => '<p>简介</p>',
            'status' => 'draft',
            'price_mode' => 'free',
            'list_price' => 0,
            'sale_price' => 0,
            'sale_start_at' => null,
            'sale_end_at' => null,
            'created_by_staff_id' => $this->staffId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
}
