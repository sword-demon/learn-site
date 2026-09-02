<?php

declare(strict_types=1);

namespace App\middleware;

use App\support\ApiResponse;
use Webman\Http\Request;
use Webman\Http\Response;
use Webman\MiddlewareInterface;

final class Authorize implements MiddlewareInterface
{
    private const MAP = [
        '/api/admin/v1/dashboard' => 'dashboard.view',
        '/api/admin/v1/departments' => 'org.department',
        '/api/admin/v1/posts' => 'org.post',
        '/api/admin/v1/roles' => 'org.role',
        '/api/admin/v1/permissions' => 'org.role',
        '/api/admin/v1/staff' => 'org.staff',
        '/api/admin/v1/categories' => 'category.manage',
        '/api/admin/v1/questions' => 'qa.view',
        '/api/admin/v1/reviews' => 'review.view',
        '/api/admin/v1/orders' => 'order.view',
        '/api/admin/v1/learners' => 'learner.view',
        '/api/admin/v1/site' => 'site.manage',
        '/api/admin/v1/moderation-logs' => 'audit.view',
        '/api/admin/v1/notifications' => 'notification.manage',
        '/api/admin/v1/scheduled-tasks' => 'scheduled_task.manage',
        '/api/admin/v1/checkins' => 'checkin.manage',
        '/api/admin/v1/banners' => 'banner.manage',
        '/api/admin/v1/coupons' => 'coupon.manage',
        '/api/admin/v1/coupon-redemptions' => 'coupon.manage',
    ];

    public static function permissionFor(string $path, string $method): ?string
    {
        $method = strtoupper($method);
        if (preg_match('#^/api/admin/v1/staff/(\d+)/overrides$#', $path)) {
            return 'org.grant';
        }
        if (preg_match('#^/api/admin/v1/learners/(\d+)/password$#', $path) && $method === 'POST') {
            return 'learner.reset_password';
        }
        if (preg_match('#^/api/admin/v1/learners/(\d+)/kick$#', $path) && $method === 'POST') {
            return 'learner.kick';
        }
        if (preg_match('#^/api/admin/v1/staff/(\d+)/kick$#', $path) && $method === 'POST') {
            return 'org.staff';
        }
        if (preg_match('#^/api/admin/v1/courses/(\d+)/students/(\d+)/revoke$#', $path)) {
            return 'course_student.revoke_free';
        }
        if (preg_match('#^/api/admin/v1/courses/(\d+)/students/(\d+)/progress/reset$#', $path)) {
            return 'course_student.reset';
        }
        if (preg_match('#^/api/admin/v1/courses/(\d+)/(?:publish|unpublish)$#', $path)) {
            return 'course.publish';
        }
        if (preg_match('#^/api/admin/v1/courses/(\d+)$#', $path) && $method === 'DELETE') {
            return 'course.delete';
        }
        if (preg_match('#^/api/admin/v1/courses/(\d+)/(chapters|lessons)(?:/\d+)?$#', $path)) {
            return $method === 'GET' ? 'course.view' : 'course.manage';
        }
        if (preg_match('#^/api/admin/v1/questions/(\d+)/(answer|close)#', $path)) {
            return 'qa.answer';
        }
        if (preg_match('#^/api/admin/v1/reviews/(\d+)/(hide|restore)$#', $path)) {
            return 'review.moderate';
        }
        if (preg_match('#^/api/admin/v1/review-replies/(\d+)/(hide|restore)$#', $path)) {
            return 'review.moderate';
        }
        if (preg_match('#^/api/admin/v1/courses/(\d+)/activation-code-batches$#', $path)) {
            return 'activation_code.manage';
        }
        if (preg_match('#^/api/admin/v1/courses/(\d+)/activation-codes(?:/\d+/void)?$#', $path)) {
            return 'activation_code.manage';
        }
        if (preg_match('#^/api/admin/v1/courses/(\d+)/feedback(?:/\d+)?$#', $path)) {
            return 'course_feedback.manage';
        }
        if (preg_match('#^/api/admin/v1/courses/(\d+)/students$#', $path)) {
            return 'course_student.view';
        }
        if ($path === '/api/admin/v1/assets') {
            return 'asset.upload';
        }
        if ($path === '/api/admin/v1/course-covers' && $method === 'POST') {
            return 'course.manage';
        }
        if ($path === '/api/admin/v1/map-covers' && $method === 'POST') {
            return 'map.manage';
        }
        if ($path === '/api/admin/v1/banner-images' && $method === 'POST') {
            return 'banner.manage';
        }
        if (preg_match('#^/api/admin/v1/courses(?:/\d+)?$#', $path)) {
            return $method === 'GET' ? 'course.view' : 'course.manage';
        }
        if (preg_match('#^/api/admin/v1/learning-maps/\d+/(?:publish|unpublish)$#', $path)) {
            return 'map.publish';
        }
        if (preg_match('#^/api/admin/v1/learning-maps(?:/.*)?$#', $path)) {
            return $method === 'GET' ? 'map.view' : 'map.manage';
        }
        foreach (self::MAP as $prefix => $code) {
            if ($path === $prefix || str_starts_with($path, $prefix . '/')) {
                return $code;
            }
        }
        return null;
    }

    public function process(Request $request, callable $handler): Response
    {
        $path = (string) $request->path();
        $code = self::permissionFor($path, $request->method());
        if ($code === null) {
            return $handler($request);
        }
        $perms = $request->permissions ?? [];
        if (in_array('*', $perms, true)) {
            return $handler($request);
        }
        if (!in_array($code, $perms, true)) {
            // US13 / T061 — 403 must not leak. The envelope below is built
            // from a fixed literal code + the request_id correlation id
            // only. Do NOT add the path, the missing code, the staff id, or
            // the held permissions here — that turns the guard into an
            // information disclosure channel. Tests live in
            // tests/AuthorizeLeakTest.php and pin this contract.
            return ApiResponse::fail(ApiResponse::FORBIDDEN, 'FORBIDDEN', $request->request_id ?? null);
        }
        return $handler($request);
    }
}
