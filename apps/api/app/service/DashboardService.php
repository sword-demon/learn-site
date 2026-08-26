<?php
declare(strict_types=1);

namespace App\service;

use support\think\Db;
use think\db\Query;

/**
 * Builds the admin workbench summary without crossing functional or data scope.
 * A null section means the actor lacks that module's view permission.
 *
 * @phpstan-type ScopeShape array{all:bool,include_self:bool,department_ids:list<int>,scope:string}
 */
final class DashboardService
{
    private const RECENT_ORDERS_LIMIT = 5;

    private const SECTION_PERMISSIONS = [
        'unanswered_questions' => 'qa.view',
        'pending_reviews' => 'review.view',
        'abnormal_learning_maps' => 'map.view',
        'unpublished_courses' => 'course.view',
        'recent_orders' => 'order.view',
    ];

    public function __construct(private readonly DataScopeService $scope) {}

    /**
     * @param string[] $permissionCodes
     * @return array<string, bool>
     */
    public static function visibleSections(array $permissionCodes): array
    {
        $super = in_array('*', $permissionCodes, true);
        $visible = [];
        foreach (self::SECTION_PERMISSIONS as $section => $permission) {
            $visible[$section] = $super || in_array($permission, $permissionCodes, true);
        }
        return $visible;
    }

    /**
     * @param string[] $permissionCodes
     * @return array<string, mixed>
     */
    public function summary(int $staffAccountId, array $permissionCodes): array
    {
        $visible = self::visibleSections($permissionCodes);
        $scope = $this->scope->resolveForCourses($staffAccountId);

        return [
            'scope' => $scope['all'] ? 'all' : 'restricted',
            'counts' => [
                'unanswered_questions' => $visible['unanswered_questions']
                    ? $this->unansweredQuestionCount($scope, $staffAccountId)
                    : null,
                'pending_reviews' => $visible['pending_reviews']
                    ? $this->pendingReviewCount($scope, $staffAccountId)
                    : null,
                'abnormal_learning_maps' => $visible['abnormal_learning_maps']
                    ? $this->abnormalLearningMapCount($scope, $staffAccountId)
                    : null,
                'unpublished_courses' => $visible['unpublished_courses']
                    ? $this->unpublishedCourseCount($scope, $staffAccountId)
                    : null,
            ],
            'recent_orders' => $visible['recent_orders']
                ? $this->recentOrders($scope, $staffAccountId)
                : null,
        ];
    }

    /** @param ScopeShape $scope */
    private function unansweredQuestionCount(array $scope, int $staffAccountId): int
    {
        $query = Db::name('questions')
            ->alias('q')
            ->join('courses c', 'c.id = q.course_id')
            ->where('q.status', 'pending');
        return (int) $this->applyScope($query, $scope, $staffAccountId, 'c.department_id', 'c.created_by_staff_id')
            ->count();
    }

    /** @param ScopeShape $scope */
    private function pendingReviewCount(array $scope, int $staffAccountId): int
    {
        // Reviews have no separate triage state. Public rows are the active
        // moderation queue; hidden rows have already been handled.
        $query = Db::name('reviews')
            ->alias('r')
            ->join('courses c', 'c.id = r.course_id')
            ->where('r.visibility', 'public');
        return (int) $this->applyScope($query, $scope, $staffAccountId, 'c.department_id', 'c.created_by_staff_id')
            ->count();
    }

    /** @param ScopeShape $scope */
    private function abnormalLearningMapCount(array $scope, int $staffAccountId): int
    {
        $query = Db::name('learning_maps')
            ->alias('m')
            ->join('map_stages ms', 'ms.map_id = m.id')
            ->join('map_stage_courses msc', 'msc.stage_id = ms.id')
            ->join('courses c', 'c.id = msc.course_id')
            ->where('m.status', 'published')
            ->where('c.status', '<>', 'published');
        return (int) $this->applyScope($query, $scope, $staffAccountId, 'm.department_id', 'm.created_by_staff_id')
            ->count('DISTINCT m.id');
    }

    /** @param ScopeShape $scope */
    private function unpublishedCourseCount(array $scope, int $staffAccountId): int
    {
        $query = Db::name('courses')
            ->alias('c')
            ->whereIn('c.status', ['draft', 'unpublished']);
        return (int) $this->applyScope($query, $scope, $staffAccountId, 'c.department_id', 'c.created_by_staff_id')
            ->count();
    }

    /**
     * @param ScopeShape $scope
     * @return list<array{id:int,course_id:int,course_title:string,status:string,paid_amount:float,created_at:string}>
     */
    private function recentOrders(array $scope, int $staffAccountId): array
    {
        $query = Db::name('orders')
            ->alias('o')
            ->join('courses c', 'c.id = o.course_id')
            ->field('o.id,o.course_id,c.title AS course_title,o.status,o.paid_amount,o.created_at');
        $rows = $this->applyScope($query, $scope, $staffAccountId, 'c.department_id', 'c.created_by_staff_id')
            ->order('o.id', 'desc')
            ->limit(self::RECENT_ORDERS_LIMIT)
            ->select()
            ->toArray();

        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'course_id' => (int) $row['course_id'],
            'course_title' => (string) $row['course_title'],
            'status' => (string) $row['status'],
            'paid_amount' => (float) $row['paid_amount'],
            'created_at' => (string) $row['created_at'],
        ], $rows);
    }

    /**
     * @param ScopeShape $scope
     */
    private function applyScope(
        Query $query,
        array $scope,
        int $staffAccountId,
        string $departmentField,
        string $creatorField,
    ): Query {
        if ($scope['all']) {
            return $query;
        }
        if ($scope['department_ids'] === [] && !$scope['include_self']) {
            return $query->where($departmentField, -1);
        }
        return $query->where(function (Query $where) use (
            $scope,
            $staffAccountId,
            $departmentField,
            $creatorField,
        ): void {
            if ($scope['department_ids'] !== []) {
                $where->where($departmentField, 'in', $scope['department_ids']);
            }
            if ($scope['include_self']) {
                if ($scope['department_ids'] !== []) {
                    $where->whereOr($creatorField, $staffAccountId);
                } else {
                    $where->where($creatorField, $staffAccountId);
                }
            }
        });
    }
}
