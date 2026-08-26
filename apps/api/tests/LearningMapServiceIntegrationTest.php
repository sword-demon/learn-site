<?php

declare(strict_types=1);

namespace Tests;

use App\service\BusinessException;
use App\service\DataScopeService;
use App\service\EntitlementService;
use App\service\LearningMapService;
use PHPUnit\Framework\TestCase;
use support\App;
use support\think\Db;
use Throwable;
use Webman\ThinkOrm\ThinkOrm;

final class LearningMapServiceIntegrationTest extends TestCase
{
    private LearningMapService $service;
    private int $departmentId;
    private int $staffId;
    private int $otherStaffId;
    private int $learnerId;
    private int $mapId;
    private int $stageId;
    private int $courseId;
    private int $secondCourseId;
    private int $unavailableCourseId;

    public static function setUpBeforeClass(): void
    {
        App::loadAllConfig(['route', 'container']);
        ThinkOrm::start(null);
    }

    protected function setUp(): void
    {
        Db::startTrans();
        try {
            $this->service = new LearningMapService(
                new DataScopeService(),
                new EntitlementService(),
            );
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

    public function testAdminCreateUpdateAndReadPersistsMapMetadata(): void
    {
        $created = $this->service->adminCreateMap($this->staffId, [
            'department_id' => $this->departmentId,
            'title' => 'Backend growth path',
            'summary' => 'A focused path',
            'cover_url' => 'https://cdn.example.test/maps/backend.png',
            'objective' => 'Build production-ready backend services',
            'audience' => 'Developers with basic PHP experience',
        ]);

        $this->assertSame('https://cdn.example.test/maps/backend.png', $created['cover_url']);
        $this->assertSame('Build production-ready backend services', $created['objective']);
        $this->assertSame('Developers with basic PHP experience', $created['audience']);

        $updated = $this->service->adminUpdateMap($this->staffId, (int) $created['id'], [
            'cover_url' => null,
            'objective' => 'Ship and operate backend services',
            'audience' => null,
        ]);

        $this->assertNull($updated['cover_url']);
        $this->assertSame('Ship and operate backend services', $updated['objective']);
        $this->assertNull($updated['audience']);
        $this->assertSame($updated, $this->service->adminGetMap($this->staffId, (int) $created['id']));
    }

    public function testAdminDetailListsStructuredPublishIssuesAndPublishUsesSameValidation(): void
    {
        $emptyMap = $this->insertMap($this->staffId, 'Empty map');
        $this->assertSame(
            [['code' => 'MAP_HAS_NO_STAGES', 'stage_id' => null, 'course_id' => null]],
            $this->service->adminGetMap($this->staffId, $emptyMap)['publish_issues'],
        );

        $stageIssue = [
            'code' => 'STAGE_HAS_NO_COURSES',
            'stage_id' => $this->stageId,
            'course_id' => null,
        ];
        $this->assertSame(
            [$stageIssue],
            $this->service->adminGetMap($this->staffId, $this->mapId)['publish_issues'],
        );

        try {
            $this->service->adminPublishMap($this->staffId, $this->mapId);
            $this->fail('An empty stage must block publishing.');
        } catch (BusinessException $exception) {
            $this->assertSame('VALIDATION_FAILED', $exception->apiCode);
            $this->assertSame($stageIssue['code'], $exception->getMessage());
        }

        $this->addStep($this->unavailableCourseId);
        $courseIssue = [
            'code' => 'MAP_HAS_UNPUBLISHED_COURSE',
            'stage_id' => $this->stageId,
            'course_id' => $this->unavailableCourseId,
        ];
        $this->assertSame(
            [$courseIssue],
            $this->service->adminGetMap($this->staffId, $this->mapId)['publish_issues'],
        );

        try {
            $this->service->adminPublishMap($this->staffId, $this->mapId);
            $this->fail('An unpublished course must block publishing.');
        } catch (BusinessException $exception) {
            $this->assertSame('VALIDATION_FAILED', $exception->apiCode);
            $this->assertSame($courseIssue['code'], $exception->getMessage());
        }
    }

    public function testGuestCanReadPublishedMapsWithoutPrivateProgress(): void
    {
        $this->addStep($this->courseId);
        $this->publishDirectly();

        $list = $this->service->learnerListMaps(null);
        $item = $this->findById($list['items'], $this->mapId);
        $this->assertNull($item['enrollment']);

        $detail = $this->service->learnerGetMap(null, $this->mapId);
        $this->assertNull($detail['enrollment']);
        $this->assertSame($this->courseId, $detail['next_step']['course_id']);
        $this->assertSame(
            ['available' => true, 'viewer_authorized' => false, 'completed' => false],
            $this->stepState($detail, $this->courseId),
        );
    }

    public function testLearnerDetailRecomputesProgressAndStepStatesFromCurrentCourses(): void
    {
        $this->addStep($this->courseId);
        $this->addStep($this->secondCourseId);
        $this->addStep($this->unavailableCourseId);
        $this->publishDirectly();
        $this->insertMapEnrollment(completed: 0, total: 0, percent: 0);
        $this->completeCourse($this->courseId, authorized: true);
        $this->completeCourse($this->unavailableCourseId, authorized: false);

        $detail = $this->service->learnerGetMap($this->learnerId, $this->mapId);

        $this->assertSame([
            'enrolled_at' => $detail['enrollment']['enrolled_at'],
            'completed_courses' => 1,
            'total_courses' => 2,
            'progress_percent' => 50,
            'completed_at' => null,
        ], $detail['enrollment']);
        $this->assertSame($this->secondCourseId, $detail['next_step']['course_id']);
        $this->assertSame(
            ['available' => true, 'viewer_authorized' => true, 'completed' => true],
            $this->stepState($detail, $this->courseId),
        );
        $this->assertSame(
            ['available' => true, 'viewer_authorized' => false, 'completed' => false],
            $this->stepState($detail, $this->secondCourseId),
        );
        $this->assertSame(
            ['available' => false, 'viewer_authorized' => false, 'completed' => true],
            $this->stepState($detail, $this->unavailableCourseId),
        );

        $stored = Db::name('map_enrollments')
            ->where('map_id', $this->mapId)
            ->where('learner_id', $this->learnerId)
            ->find();
        $this->assertSame(1, (int) $stored['completed_courses']);
        $this->assertSame(2, (int) $stored['total_courses']);
        $this->assertSame(50, (int) $stored['progress_percent']);
    }

    public function testStartIsIdempotentAndNeverGrantsCourseEntitlements(): void
    {
        $this->addStep($this->courseId);
        $this->addStep($this->unavailableCourseId);
        $this->publishDirectly();

        $first = $this->service->learnerStart($this->learnerId, $this->mapId);
        $second = $this->service->learnerStart($this->learnerId, $this->mapId);

        $this->assertSame($first['enrollment']['enrolled_at'], $second['enrollment']['enrolled_at']);
        $this->assertSame(1, (int) Db::name('map_enrollments')
            ->where('map_id', $this->mapId)
            ->where('learner_id', $this->learnerId)
            ->count());
        $this->assertSame(0, (int) Db::name('course_entitlements')
            ->where('learner_id', $this->learnerId)
            ->count());
        $this->assertSame(1, $first['enrollment']['total_courses']);
    }

    public function testSelfScopeOnlyReturnsMapsCreatedByTheActor(): void
    {
        $suffix = bin2hex(random_bytes(5));
        $now = date('Y-m-d H:i:s');
        $actorId = $this->insertStaff("t074-self-$suffix", $this->departmentId, false, $now);
        $roleId = (int) Db::name('roles')->insertGetId([
            'name' => "T074 self $suffix",
            'code' => "t074-self-$suffix",
            'data_scope' => 'self',
            'status' => 'enabled',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        Db::name('staff_role')->insert(['staff_user_id' => $actorId, 'role_id' => $roleId]);
        $permissionId = Db::name('permissions')->where('code', 'map.view')->value('id');
        $this->assertNotNull($permissionId);
        Db::name('role_permission')->insert([
            'role_id' => $roleId,
            'permission_id' => (int) $permissionId,
        ]);

        $ownMapId = $this->insertMap($actorId, 'Own self-scoped map');
        $otherMapId = $this->insertMap($this->otherStaffId, 'Other staff map');

        $result = $this->service->adminListMaps($actorId, []);
        $this->assertSame([$ownMapId], array_column($result['items'], 'id'));
        $this->assertSame($ownMapId, $this->service->adminGetMap($actorId, $ownMapId)['id']);

        try {
            $this->service->adminGetMap($actorId, $otherMapId);
            $this->fail('Self scope must not expose another staff member\'s map.');
        } catch (BusinessException $exception) {
            $this->assertSame('FORBIDDEN', $exception->apiCode);
        }
    }

    public function testAddingCoursePersistsItsMapIdentity(): void
    {
        $step = $this->service->adminAddCourseToStage(
            $this->staffId,
            $this->mapId,
            $this->stageId,
            $this->courseId,
        );

        $persistedMapId = Db::name('map_stage_courses')
            ->where('id', (int) $step['id'])
            ->value('map_id');

        $this->assertSame($this->mapId, (int) $persistedMapId);
    }

    /** @param list<array<string, mixed>> $rows */
    private function findById(array $rows, int $id): array
    {
        foreach ($rows as $row) {
            if ((int) $row['id'] === $id) {
                return $row;
            }
        }
        $this->fail("Row $id was not returned.");
    }

    /** @param array<string, mixed> $detail */
    private function stepState(array $detail, int $courseId): array
    {
        foreach ($detail['stages'] as $stage) {
            foreach ($stage['courses'] as $step) {
                if ((int) $step['course_id'] === $courseId) {
                    return [
                        'available' => $step['available'],
                        'viewer_authorized' => $step['viewer_authorized'],
                        'completed' => $step['completed'],
                    ];
                }
            }
        }
        $this->fail("Course step $courseId was not returned.");
    }

    private function addStep(int $courseId): int
    {
        $sortOrder = (int) Db::name('map_stage_courses')->where('stage_id', $this->stageId)->count() + 1;
        return (int) Db::name('map_stage_courses')->insertGetId([
            'stage_id' => $this->stageId,
            'map_id' => $this->mapId,
            'course_id' => $courseId,
            'sort_order' => $sortOrder,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    private function publishDirectly(): void
    {
        Db::name('learning_maps')->where('id', $this->mapId)->update([
            'status' => 'published',
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }

    private function insertMapEnrollment(int $completed, int $total, int $percent): void
    {
        Db::name('map_enrollments')->insert([
            'map_id' => $this->mapId,
            'learner_id' => $this->learnerId,
            'enrolled_at' => date('Y-m-d H:i:s'),
            'completed_courses' => $completed,
            'total_courses' => $total,
            'progress_percent' => $percent,
            'completed_at' => null,
        ]);
    }

    private function completeCourse(int $courseId, bool $authorized): void
    {
        $now = date('Y-m-d H:i:s');
        if ($authorized) {
            (new EntitlementService())->grant($this->learnerId, $courseId, 'free');
            Db::name('course_enrollments')
                ->where('learner_id', $this->learnerId)
                ->where('course_id', $courseId)
                ->update(['progress_percent' => 100, 'completed_at' => $now, 'updated_at' => $now]);
            return;
        }

        Db::name('course_enrollments')->insert([
            'learner_id' => $this->learnerId,
            'course_id' => $courseId,
            'progress_percent' => 100,
            'last_lesson_id' => null,
            'last_position' => 0,
            'completed_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function seedFixture(): void
    {
        $suffix = bin2hex(random_bytes(6));
        $now = date('Y-m-d H:i:s');

        $this->departmentId = (int) Db::name('departments')->insertGetId([
            'parent_id' => null,
            'name' => "T074 department $suffix",
            'path' => "/t074-$suffix/",
            'depth' => 1,
            'sort' => 0,
            'status' => 'enabled',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $this->staffId = $this->insertStaff("t074-$suffix", $this->departmentId, true, $now);
        $this->otherStaffId = $this->insertStaff("t074-other-$suffix", $this->departmentId, true, $now);
        $this->learnerId = (int) Db::name('accounts')->insertGetId([
            'kind' => 'learner',
            'login' => '13' . random_int(100000000, 999999999),
            'password_hash' => password_hash('T074-password', PASSWORD_DEFAULT),
            'must_change_password' => 0,
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        Db::name('learners')->insert([
            'account_id' => $this->learnerId,
            'nickname' => 'T074 Learner',
            'avatar_url' => null,
            'show_on_course' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $categoryId = (int) Db::name('categories')->insertGetId([
            'parent_id' => 0,
            'name' => "T074 category $suffix",
            'path' => '/',
            'depth' => 1,
            'sort' => 0,
            'status' => 'enabled',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $this->courseId = $this->insertCourse($categoryId, "T074 course A $suffix", 'published', $now);
        $this->secondCourseId = $this->insertCourse($categoryId, "T074 course B $suffix", 'published', $now);
        $this->unavailableCourseId = $this->insertCourse(
            $categoryId,
            "T074 unavailable course $suffix",
            'unpublished',
            $now,
        );
        $this->mapId = $this->insertMap($this->staffId, "T074 map $suffix");
        $this->stageId = (int) Db::name('map_stages')->insertGetId([
            'map_id' => $this->mapId,
            'title' => 'T074 stage',
            'summary' => null,
            'sort_order' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function insertStaff(string $login, int $departmentId, bool $superAdmin, string $now): int
    {
        $id = (int) Db::name('accounts')->insertGetId([
            'kind' => 'staff',
            'login' => $login,
            'password_hash' => password_hash('T074-password', PASSWORD_DEFAULT),
            'must_change_password' => 0,
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        Db::name('staff_users')->insert([
            'account_id' => $id,
            'is_super_admin' => $superAdmin ? 1 : 0,
            'department_id' => $departmentId,
            'display_name' => "T074 Staff $id",
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        return $id;
    }

    private function insertCourse(int $categoryId, string $title, string $status, string $now): int
    {
        return (int) Db::name('courses')->insertGetId([
            'department_id' => $this->departmentId,
            'category_id' => $categoryId,
            'title' => $title,
            'cover_url' => null,
            'teacher_name' => 'T074 Teacher',
            'summary' => 'T074 map course',
            'intro_rich_text' => '<p>T074</p>',
            'status' => $status,
            'price_mode' => 'free',
            'list_price' => 0,
            'sale_price' => 0,
            'created_by_staff_id' => $this->staffId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function insertMap(int $creatorId, string $title): int
    {
        $now = date('Y-m-d H:i:s');
        return (int) Db::name('learning_maps')->insertGetId([
            'department_id' => $this->departmentId,
            'title' => $title,
            'summary' => null,
            'cover_url' => null,
            'objective' => null,
            'audience' => null,
            'status' => 'draft',
            'created_by_staff_id' => $creatorId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
}
