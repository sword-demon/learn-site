<?php

declare(strict_types=1);

use support\App;
use support\Redis;
use support\think\Db;
use Webman\ThinkOrm\ThinkOrm;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

if (getenv('APP_ENV') !== 'testing' || getenv('E2E_FIXTURE_ALLOW_RESET') !== '1') {
    fwrite(STDERR, "Refusing to reset data outside the explicit E2E test environment.\n");
    exit(2);
}

App::loadAllConfig(['route', 'container']);
ThinkOrm::start(null);

$tables = Db::query(
    "SELECT TABLE_NAME FROM information_schema.TABLES
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE'",
);

Db::execute('SET FOREIGN_KEY_CHECKS = 0');
try {
    foreach ($tables as $table) {
        $name = (string) ($table['TABLE_NAME'] ?? $table['table_name'] ?? '');
        if ($name === '' || $name === 'phinxlog') {
            continue;
        }
        Db::execute(sprintf('TRUNCATE TABLE `%s`', str_replace('`', '``', $name)));
    }
} finally {
    Db::execute('SET FOREIGN_KEY_CHECKS = 1');
}

try {
    Redis::connection('default')->flushDB();
} catch (Throwable $exception) {
    fwrite(STDERR, 'Unable to reset Redis: ' . $exception->getMessage() . "\n");
    exit(3);
}

$now = date('Y-m-d H:i:s');

$ownerId = (int) Db::name('accounts')->insertGetId([
    'kind' => 'staff',
    'login' => getenv('E2E_OWNER_ACCOUNT') ?: 'e2e-owner',
    'password_hash' => password_hash(getenv('E2E_OWNER_PASSWORD') ?: 'OwnerPass123!', PASSWORD_DEFAULT),
    'must_change_password' => 0,
    'status' => 'active',
    'created_at' => $now,
    'updated_at' => $now,
]);
Db::name('staff_users')->insert([
    'account_id' => $ownerId,
    'is_super_admin' => 1,
    'department_id' => null,
    'display_name' => 'E2E Owner',
    'created_at' => $now,
    'updated_at' => $now,
]);

$insideDepartmentId = (int) Db::name('departments')->insertGetId([
    'parent_id' => null,
    'name' => 'E2E 内容部',
    'path' => '/',
    'depth' => 1,
    'sort' => 1,
    'status' => 'enabled',
    'created_at' => $now,
    'updated_at' => $now,
]);
Db::name('departments')->where('id', $insideDepartmentId)->update(['path' => '/' . $insideDepartmentId]);

$outsideDepartmentId = (int) Db::name('departments')->insertGetId([
    'parent_id' => null,
    'name' => 'E2E 旁支部',
    'path' => '/',
    'depth' => 1,
    'sort' => 2,
    'status' => 'enabled',
    'created_at' => $now,
    'updated_at' => $now,
]);
Db::name('departments')->where('id', $outsideDepartmentId)->update(['path' => '/' . $outsideDepartmentId]);

$courseViewPermissionId = (int) Db::name('permissions')->insertGetId([
    'code' => 'course.view',
    'module' => 'catalog',
    'description' => 'View courses',
]);
$editorRoleId = (int) Db::name('roles')->insertGetId([
    'name' => 'E2E 本部门课程查看',
    'code' => 'e2e-course-viewer',
    'data_scope' => 'dept',
    'status' => 'enabled',
    'created_at' => $now,
    'updated_at' => $now,
]);
Db::name('role_permission')->insert([
    'role_id' => $editorRoleId,
    'permission_id' => $courseViewPermissionId,
]);

$editorId = (int) Db::name('accounts')->insertGetId([
    'kind' => 'staff',
    'login' => getenv('E2E_EDITOR_ACCOUNT') ?: 'e2e-course-editor',
    'password_hash' => password_hash(getenv('E2E_EDITOR_PASSWORD') ?: 'EditorPass123!', PASSWORD_DEFAULT),
    'must_change_password' => 0,
    'status' => 'active',
    'created_at' => $now,
    'updated_at' => $now,
]);
Db::name('staff_users')->insert([
    'account_id' => $editorId,
    'is_super_admin' => 0,
    'department_id' => $insideDepartmentId,
    'display_name' => 'E2E Course Editor',
    'created_at' => $now,
    'updated_at' => $now,
]);
Db::name('staff_role')->insert([
    'staff_user_id' => $editorId,
    'role_id' => $editorRoleId,
]);

$categoryId = (int) Db::name('categories')->insertGetId([
    'parent_id' => 0,
    'name' => 'E2E 核心旅程',
    'path' => '/',
    'depth' => 1,
    'sort' => 1,
    'status' => 'enabled',
    'created_at' => $now,
    'updated_at' => $now,
]);
Db::name('categories')->where('id', $categoryId)->update(['path' => '/' . $categoryId]);

$insertCourse = static function (
    string $title,
    int $departmentId,
    string $status,
    string $priceMode,
    float $listPrice,
    string $lessonTitle,
) use ($categoryId, $ownerId, $now): int {
    $courseId = (int) Db::name('courses')->insertGetId([
        'department_id' => $departmentId,
        'category_id' => $categoryId,
        'title' => $title,
        'cover_url' => null,
        'teacher_name' => 'E2E 讲师',
        'summary' => $title . ' 的自动化验收摘要',
        'intro_rich_text' => '<p>用于 Compose Playwright 核心旅程验收。</p>',
        'status' => $status,
        'price_mode' => $priceMode,
        'list_price' => $listPrice,
        'sale_price' => 0,
        'sale_start_at' => null,
        'sale_end_at' => null,
        'created_by_staff_id' => $ownerId,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    $chapterId = (int) Db::name('chapters')->insertGetId([
        'course_id' => $courseId,
        'title' => 'E2E 第一章',
        'sort' => 0,
        'status' => 'enabled',
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    Db::name('lessons')->insert([
        'chapter_id' => $chapterId,
        'title' => $lessonTitle,
        'sort' => 0,
        'status' => 'enabled',
        'content_type' => 'markdown',
        'body_markdown' => '# ' . $lessonTitle . "\n\n这是 E2E 学习内容。",
        'asset_id' => null,
        'is_preview' => 0,
        'duration_seconds' => 0,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    return $courseId;
};

$fixture = [
    'draft_course_id' => $insertCourse(
        'E2E 待发布课程', $insideDepartmentId, 'draft', 'free', 0, 'E2E 待发布课节',
    ),
    'free_course_id' => $insertCourse(
        'E2E 范围内免费课', $insideDepartmentId, 'published', 'free', 0, 'E2E 免费课第一节',
    ),
    'paid_course_id' => $insertCourse(
        'E2E 收费课程', $insideDepartmentId, 'published', 'paid', 49, 'E2E 收费课第一节',
    ),
    'outside_course_id' => $insertCourse(
        'E2E 范围外课程', $outsideDepartmentId, 'published', 'free', 0, 'E2E 范围外课节',
    ),
];

fwrite(STDOUT, json_encode($fixture, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE) . "\n");
