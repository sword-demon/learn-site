<?php

declare(strict_types=1);

use Phinx\Seed\AbstractSeed;

/**
 * PermissionSeeder — every permission code listed in the spec FR-075.
 *
 * Codes are stable: never delete or rename, otherwise seed-on-boot will not
 * match audit logs. Adding a new code is safe (insert-only).
 *
 * learner.reset_password and learner.kick are NOT implied by learner.view;
 * they each require their own grant. (FR-075 §caller review.)
 */
final class PermissionSeeder extends AbstractSeed
{
    private const PERMISSIONS = [
        ['code' => 'category.manage', 'module' => 'catalog', 'description' => 'Manage categories'],
        ['code' => 'course.view', 'module' => 'catalog', 'description' => 'View courses'],
        ['code' => 'course.manage', 'module' => 'catalog', 'description' => 'Edit course content'],
        ['code' => 'course.publish', 'module' => 'catalog', 'description' => 'Publish / unpublish a course'],
        ['code' => 'course.delete', 'module' => 'catalog', 'description' => 'Hard-delete empty draft'],
        ['code' => 'asset.upload', 'module' => 'catalog', 'description' => 'Upload PDF / video assets'],
        ['code' => 'map.view', 'module' => 'map', 'description' => 'View learning maps'],
        ['code' => 'map.manage', 'module' => 'map', 'description' => 'Edit learning maps'],
        ['code' => 'map.publish', 'module' => 'map', 'description' => 'Publish learning maps'],
        ['code' => 'order.view', 'module' => 'order', 'description' => 'Read-only order review'],
        ['code' => 'qa.view', 'module' => 'qa', 'description' => 'View Q&A inbox'],
        ['code' => 'qa.answer', 'module' => 'qa', 'description' => 'Reply to questions'],
        ['code' => 'review.view', 'module' => 'review', 'description' => 'View reviews'],
        ['code' => 'review.moderate', 'module' => 'review', 'description' => 'Hide / restore reviews'],
        ['code' => 'course_student.view', 'module' => 'course_student', 'description' => 'View per-course learner list'],
        ['code' => 'course_student.reset', 'module' => 'course_student', 'description' => 'Reset learner progress'],
        ['code' => 'course_student.revoke_free', 'module' => 'course_student', 'description' => 'Revoke free course access'],
        ['code' => 'learner.view', 'module' => 'learner', 'description' => 'List learner accounts'],
        ['code' => 'learner.reset_password', 'module' => 'learner', 'description' => 'Reset a learner password'],
        ['code' => 'learner.kick', 'module' => 'learner', 'description' => 'Revoke learner login families'],
        ['code' => 'org.department', 'module' => 'org', 'description' => 'Manage departments'],
        ['code' => 'org.post', 'module' => 'org', 'description' => 'Manage posts'],
        ['code' => 'org.role', 'module' => 'org', 'description' => 'Manage roles'],
        ['code' => 'org.staff', 'module' => 'org', 'description' => 'Manage staff accounts'],
        ['code' => 'org.grant', 'module' => 'org', 'description' => 'Apply user-level overrides'],
        ['code' => 'site.manage', 'module' => 'site', 'description' => 'Edit public site profile'],
        ['code' => 'audit.view', 'module' => 'site', 'description' => 'Read moderation logs'],
        ['code' => 'notification.manage', 'module' => 'notification', 'description' => 'Send and review learner notifications'],
        ['code' => 'scheduled_task.manage', 'module' => 'scheduled_task', 'description' => 'Manage scheduled background tasks'],
        ['code' => 'checkin.manage', 'module' => 'checkin', 'description' => 'View and delete learner daily check-ins'],
        ['code' => 'banner.manage', 'module' => 'banner', 'description' => 'Manage home banners'],
        ['code' => 'dashboard.view', 'module' => 'site', 'description' => 'Read admin dashboard'],
    ];

    public function run(): void
    {
        $pdo = $this->getAdapter()->getConnection();
        $exists = $pdo->prepare('SELECT 1 FROM permissions WHERE code = ?');
        $insert = $pdo->prepare(
            'INSERT INTO permissions (code, module, description) VALUES (?, ?, ?)',
        );

        foreach (self::PERMISSIONS as $permission) {
            $exists->execute([$permission['code']]);
            if ($exists->fetchColumn() !== false) {
                continue;
            }

            $insert->execute([
                $permission['code'],
                $permission['module'],
                $permission['description'],
            ]);
        }
    }
}
