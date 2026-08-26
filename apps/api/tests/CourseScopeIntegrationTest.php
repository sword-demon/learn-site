<?php

declare(strict_types=1);

namespace Tests;

use App\service\BusinessException;
use App\service\CourseService;
use PHPUnit\Framework\TestCase;
use support\App;
use support\think\Db;
use Throwable;
use Webman\ThinkOrm\ThinkOrm;

final class CourseScopeIntegrationTest extends TestCase
{
    private int $actorId;
    private int $courseViewPermissionId;
    private int $courseViewRoleId;
    private int $parentDepartmentId;
    private int $outsideDepartmentId;
    private int $specifiedCourseId;
    private int $outsideCourseId;

    /** @var list<int> */
    private array $visibleCourseIds = [];

    /** @var array<string, int> */
    private array $derivedRowIds = [];

    private int $selfCourseId;

    public static function setUpBeforeClass(): void
    {
        App::loadAllConfig(['route', 'container']);
        ThinkOrm::start(null);
    }

    protected function setUp(): void
    {
        Db::startTrans();
        try {
            $this->seedScopeFixture();
        } catch (Throwable $exception) {
            Db::rollback();
            throw $exception;
        }
    }

    protected function tearDown(): void
    {
        Db::rollback();
    }

    public function testListCombinesDepartmentSpecifiedAndSelfWithoutLeakingThroughFilters(): void
    {
        $service = new CourseService();

        $result = $service->listForAdmin($this->actorId);
        $actualIds = array_column($result['items'], 'id');
        sort($actualIds);
        $this->assertSame($this->visibleCourseIds, $actualIds);
        $this->assertSame(4, $result['total']);

        $published = $service->listForAdmin($this->actorId, ['status' => 'published']);
        $publishedIds = array_column($published['items'], 'id');
        sort($publishedIds);
        $expectedPublished = array_values(array_diff($this->visibleCourseIds, [$this->selfCourseId]));
        $this->assertSame($expectedPublished, $publishedIds);
        $this->assertSame(3, $published['total']);

        $outside = $service->listForAdmin($this->actorId, ['q' => 'outside']);
        $this->assertSame([$this->selfCourseId], array_column($outside['items'], 'id'));
        $this->assertSame(1, $outside['total']);

        $publishedOutside = $service->listForAdmin($this->actorId, [
            'status' => 'published',
            'q' => 'outside',
        ]);
        $this->assertSame([], $publishedOutside['items']);
        $this->assertSame(0, $publishedOutside['total']);
    }

    public function testPermissionRevocationTakesEffectOnNextListRequest(): void
    {
        $service = new CourseService();
        $this->assertSame(4, $service->listForAdmin($this->actorId)['total']);

        Db::name('role_permission')
            ->where('role_id', $this->courseViewRoleId)
            ->where('permission_id', $this->courseViewPermissionId)
            ->delete();

        $afterRevocation = $service->listForAdmin($this->actorId);
        $this->assertSame([], $afterRevocation['items']);
        $this->assertSame(0, $afterRevocation['total']);
    }

    public function testCannotMoveOutOfScopeCourseIntoWritableDepartment(): void
    {
        $service = new CourseService();

        try {
            $service->updateCourse(
                $this->outsideCourseId,
                ['department_id' => $this->parentDepartmentId],
                $this->actorId,
            );
            $this->fail('Expected an out-of-scope source course to be rejected.');
        } catch (BusinessException $exception) {
            $this->assertSame('FORBIDDEN', $exception->apiCode);
            $this->assertSame('DEPARTMENT_OUT_OF_SCOPE', $exception->getMessage());
        }

        $this->assertSame(
            $this->outsideDepartmentId,
            (int) Db::name('courses')->where('id', $this->outsideCourseId)->value('department_id'),
        );
    }

    public function testVisibleCourseMoveReScopesDerivedRowsWithoutChangingRelations(): void
    {
        $updated = (new CourseService())->updateCourse(
            $this->specifiedCourseId,
            ['department_id' => $this->parentDepartmentId],
            $this->actorId,
        );

        $this->assertSame($this->parentDepartmentId, $updated['department_id']);
        foreach ($this->derivedRowIds as $table => $rowId) {
            $row = Db::query(
                "SELECT derived.course_id, courses.department_id
                 FROM {$table} derived
                 JOIN courses ON courses.id = derived.course_id
                 WHERE derived.id = ?",
                [$rowId],
            )[0];
            $this->assertSame($this->specifiedCourseId, (int) $row['course_id'], $table);
            $this->assertSame($this->parentDepartmentId, (int) $row['department_id'], $table);
        }
    }

    public function testSelfCreatorCanUpdateWithUnchangedOutOfScopeDepartment(): void
    {
        $updated = (new CourseService())->updateCourse(
            $this->selfCourseId,
            [
                'department_id' => $this->outsideDepartmentId,
                'summary' => 'T064 self update',
            ],
            $this->actorId,
        );

        $this->assertSame($this->outsideDepartmentId, $updated['department_id']);
        $this->assertSame('T064 self update', $updated['summary']);
    }

    private function seedScopeFixture(): void
    {
        $suffix = bin2hex(random_bytes(6));
        $now = date('Y-m-d H:i:s');

        $parentId = $this->insertDepartment("T063 parent $suffix", null, '/', 1, 'enabled', $now);
        $this->parentDepartmentId = $parentId;
        $childId = $this->insertDepartment(
            "T063 disabled child $suffix",
            $parentId,
            "/$parentId",
            2,
            'disabled',
            $now,
        );
        $specifiedId = $this->insertDepartment("T063 specified $suffix", null, '/', 1, 'enabled', $now);
        $specifiedChildId = $this->insertDepartment(
            "T063 specified child $suffix",
            $specifiedId,
            "/$specifiedId",
            2,
            'enabled',
            $now,
        );
        $outsideId = $this->insertDepartment("T063 outside $suffix", null, '/', 1, 'enabled', $now);
        $this->outsideDepartmentId = $outsideId;

        $this->actorId = $this->insertStaff("t063-actor-$suffix", $parentId, $now);
        $otherStaffId = $this->insertStaff("t063-other-$suffix", $outsideId, $now);

        $deptRoleId = $this->insertRole("T063 subtree $suffix", "t063-subtree-$suffix", 'dept_and_children', $now);
        $this->courseViewRoleId = $deptRoleId;
        $specifiedRoleId = $this->insertRole(
            "T063 specified $suffix",
            "t063-specified-$suffix",
            'specified_depts',
            $now,
        );
        $selfRoleId = $this->insertRole("T063 self $suffix", "t063-self-$suffix", 'self', $now);
        foreach ([$deptRoleId, $specifiedRoleId, $selfRoleId] as $roleId) {
            Db::name('staff_role')->insert([
                'staff_user_id' => $this->actorId,
                'role_id' => $roleId,
            ]);
        }
        Db::name('role_scope_department')->insert([
            'role_id' => $specifiedRoleId,
            'department_id' => $specifiedId,
        ]);

        $permissionId = Db::name('permissions')->where('code', 'course.view')->value('id');
        if ($permissionId === null) {
            $permissionId = Db::name('permissions')->insertGetId([
                'code' => 'course.view',
                'module' => 'course',
                'description' => 'T063 integration fixture',
            ]);
        }
        $this->courseViewPermissionId = (int) $permissionId;
        Db::name('role_permission')->insert([
            'role_id' => $deptRoleId,
            'permission_id' => $this->courseViewPermissionId,
        ]);

        $categoryId = (int) Db::name('categories')->insertGetId([
            'parent_id' => 0,
            'name' => "T063 category $suffix",
            'path' => '/',
            'depth' => 1,
            'sort' => 0,
            'status' => 'enabled',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $parentCourseId = $this->insertCourse(
            $parentId,
            $categoryId,
            "T063 parent $suffix",
            'published',
            $otherStaffId,
            $now,
        );
        $childCourseId = $this->insertCourse(
            $childId,
            $categoryId,
            "T063 child $suffix",
            'published',
            $otherStaffId,
            $now,
        );
        $specifiedCourseId = $this->insertCourse(
            $specifiedId,
            $categoryId,
            "T063 specified $suffix",
            'published',
            $otherStaffId,
            $now,
        );
        $this->specifiedCourseId = $specifiedCourseId;
        $this->insertCourse(
            $specifiedChildId,
            $categoryId,
            "T063 specified child $suffix",
            'published',
            $otherStaffId,
            $now,
        );
        $this->selfCourseId = $this->insertCourse(
            $outsideId,
            $categoryId,
            "T063 self outside $suffix",
            'draft',
            $this->actorId,
            $now,
        );
        $this->outsideCourseId = $this->insertCourse(
            $outsideId,
            $categoryId,
            "T063 other outside $suffix",
            'published',
            $otherStaffId,
            $now,
        );

        $this->derivedRowIds = $this->insertDerivedRows($specifiedCourseId, $suffix, $now);

        $this->visibleCourseIds = [$parentCourseId, $childCourseId, $specifiedCourseId, $this->selfCourseId];
        sort($this->visibleCourseIds);
    }

    /** @return array<string, int> */
    private function insertDerivedRows(int $courseId, string $suffix, string $now): array
    {
        $chapterId = (int) Db::name('chapters')->insertGetId([
            'course_id' => $courseId,
            'title' => 'T064 chapter',
            'sort' => 0,
            'status' => 'enabled',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $lessonId = (int) Db::name('lessons')->insertGetId([
            'chapter_id' => $chapterId,
            'title' => 'T064 lesson',
            'sort' => 0,
            'status' => 'enabled',
            'content_type' => 'markdown',
            'body_markdown' => 'T064 lesson fixture',
            'asset_id' => null,
            'is_preview' => 0,
            'duration_seconds' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $learnerId = (int) Db::name('accounts')->insertGetId([
            'kind' => 'learner',
            'login' => '139' . substr(preg_replace('/[^0-9]/', '', $suffix) . '00000000', 0, 8),
            'password_hash' => 'not-used-by-test',
            'must_change_password' => 0,
            'status' => 'active',
            'last_login_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $orderId = (int) Db::name('orders')->insertGetId([
            'learner_id' => $learnerId,
            'course_id' => $courseId,
            'list_price_snapshot' => 0,
            'sale_price_snapshot' => 0,
            'paid_amount' => 0,
            'currency' => 'CNY',
            'status' => 'succeeded',
            'provider' => 'fake',
            'provider_ref' => "t064-$suffix",
            'succeeded_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $entitlementId = (int) Db::name('course_entitlements')->insertGetId([
            'learner_id' => $learnerId,
            'course_id' => $courseId,
            'source' => 'purchase',
            'order_id' => $orderId,
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $questionId = (int) Db::name('questions')->insertGetId([
            'course_id' => $courseId,
            'chapter_id' => $chapterId,
            'lesson_id' => $lessonId,
            'learner_id' => $learnerId,
            'title' => 'T064 question',
            'body' => 'T064 question fixture',
            'status' => 'pending',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $reviewId = (int) Db::name('reviews')->insertGetId([
            'course_id' => $courseId,
            'learner_id' => $learnerId,
            'rating' => 5,
            'body' => 'T064 review fixture',
            'visibility' => 'public',
            'active_key' => "$learnerId:$courseId",
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return [
            'orders' => $orderId,
            'course_entitlements' => $entitlementId,
            'questions' => $questionId,
            'reviews' => $reviewId,
        ];
    }

    private function insertDepartment(
        string $name,
        ?int $parentId,
        string $parentPath,
        int $depth,
        string $status,
        string $now,
    ): int {
        $id = (int) Db::name('departments')->insertGetId([
            'parent_id' => $parentId,
            'name' => $name,
            'path' => $parentPath,
            'depth' => $depth,
            'sort' => 0,
            'status' => $status,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $path = $parentPath === '/' ? "/$id" : "$parentPath/$id";
        Db::name('departments')->where('id', $id)->update(['path' => $path]);
        return $id;
    }

    private function insertStaff(string $login, int $departmentId, string $now): int
    {
        $accountId = (int) Db::name('accounts')->insertGetId([
            'kind' => 'staff',
            'login' => $login,
            'password_hash' => 'not-used-by-test',
            'must_change_password' => 0,
            'status' => 'active',
            'last_login_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        Db::name('staff_users')->insert([
            'account_id' => $accountId,
            'is_super_admin' => 0,
            'department_id' => $departmentId,
            'display_name' => $login,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        return $accountId;
    }

    private function insertRole(string $name, string $code, string $scope, string $now): int
    {
        return (int) Db::name('roles')->insertGetId([
            'name' => $name,
            'code' => $code,
            'data_scope' => $scope,
            'status' => 'enabled',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function insertCourse(
        int $departmentId,
        int $categoryId,
        string $title,
        string $status,
        int $creatorId,
        string $now,
    ): int {
        return (int) Db::name('courses')->insertGetId([
            'department_id' => $departmentId,
            'category_id' => $categoryId,
            'title' => $title,
            'cover_url' => null,
            'teacher_name' => 'T063 teacher',
            'summary' => 'T063 integration fixture',
            'intro_rich_text' => '<p>T063</p>',
            'status' => $status,
            'price_mode' => 'free',
            'list_price' => 0,
            'sale_price' => 0,
            'sale_start_at' => null,
            'sale_end_at' => null,
            'created_by_staff_id' => $creatorId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
}
