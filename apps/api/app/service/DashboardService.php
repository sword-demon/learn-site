<?php
declare(strict_types=1);

namespace App\service;

use DateTimeImmutable;
use DateTimeZone;
use support\think\Db;
use think\db\Query;

/**
 * Builds the admin workbench summary without crossing functional or data scope.
 * A null section means the actor lacks that module's view permission.
 *
 * @phpstan-type ScopeShape array{all:bool,include_self:bool,department_ids:list<int>,scope:string}
 * @phpstan-type DashboardWindow array{start:string,end:string,start_local:DateTimeImmutable,end_local:DateTimeImmutable,range_days:int}
 */
final class DashboardService
{
    private const RECENT_ORDERS_LIMIT = 5;
    private const TIMEZONE = 'Asia/Shanghai';

    private const SECTION_PERMISSIONS = [
        'unanswered_questions' => 'qa.view',
        'pending_reviews' => 'review.view',
        'abnormal_learning_maps' => 'map.view',
        'unpublished_courses' => 'course.view',
        'pending_orders' => 'order.view',
        'succeeded_orders' => 'order.view',
        'paid_amount' => 'order.view',
        'published_courses' => 'course.view',
        'recent_orders' => 'order.view',
        'order_trend' => 'order.view',
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

    public static function normalizeRangeDays(?int $days): int
    {
        return in_array($days, [7, 30, 90], true) ? $days : 30;
    }

    /**
     * @param string[] $permissionCodes
     * @return array<string, mixed>
     */
    public function summary(int $staffAccountId, array $permissionCodes, ?int $rangeDays = null): array
    {
        $visible = self::visibleSections($permissionCodes);
        $scope = $this->scope->resolveForCourses($staffAccountId);
        $window = $this->rangeWindow(self::normalizeRangeDays($rangeDays));
        $operations = [
            'unanswered_questions' => $visible['unanswered_questions'] ? $this->unansweredQuestionCount($scope, $staffAccountId) : null,
            'pending_reviews' => $visible['pending_reviews'] ? $this->pendingReviewCount($scope, $staffAccountId) : null,
            'abnormal_learning_maps' => $visible['abnormal_learning_maps'] ? $this->abnormalLearningMapCount($scope, $staffAccountId) : null,
            'unpublished_courses' => $visible['unpublished_courses'] ? $this->unpublishedCourseCount($scope, $staffAccountId) : null,
        ];
        $inventory = $this->courseInventory($scope, $staffAccountId, $visible['published_courses'] || $visible['unpublished_courses']);

        return [
            'scope' => $scope['all'] ? 'all' : 'restricted',
            'timezone' => self::TIMEZONE,
            'range_days' => $window['range_days'],
            'counts' => [
                ...$operations,
                'pending_orders' => $visible['pending_orders'] ? $this->pendingOrderCount($scope, $staffAccountId) : null,
                'succeeded_orders' => $visible['succeeded_orders'] ? $this->succeededOrderCount($scope, $staffAccountId, $window) : null,
                'paid_amount' => $visible['paid_amount'] ? $this->paidAmount($scope, $staffAccountId, $window) : null,
                'published_courses' => $visible['published_courses'] ? $inventory['published'] : null,
            ],
            'order_trend' => $visible['order_trend'] ? $this->orderTrend($scope, $staffAccountId, $window) : null,
            'operations_content' => [
                'operations' => $operations,
                'course_inventory' => [
                    'draft' => $visible['unpublished_courses'] ? $inventory['draft'] : null,
                    'published' => $visible['published_courses'] ? $inventory['published'] : null,
                    'unpublished' => $visible['unpublished_courses'] ? $inventory['unpublished'] : null,
                ],
            ],
            'recent_orders' => $visible['recent_orders'] ? $this->recentOrders($scope, $staffAccountId) : null,
        ];
    }

    /** @param ScopeShape $scope */
    private function unansweredQuestionCount(array $scope, int $staffAccountId): int
    {
        $query = Db::name('questions')->alias('q')->join('courses c', 'c.id = q.course_id')->where('q.status', 'pending');
        return (int) $this->applyScope($query, $scope, $staffAccountId, 'c.department_id', 'c.created_by_staff_id')->count();
    }

    /** @param ScopeShape $scope */
    private function pendingReviewCount(array $scope, int $staffAccountId): int
    {
        $query = Db::name('reviews')->alias('r')->join('courses c', 'c.id = r.course_id')->where('r.visibility', 'public');
        return (int) $this->applyScope($query, $scope, $staffAccountId, 'c.department_id', 'c.created_by_staff_id')->count();
    }

    /** @param ScopeShape $scope */
    private function abnormalLearningMapCount(array $scope, int $staffAccountId): int
    {
        $query = Db::name('learning_maps')->alias('m')->join('map_stages ms', 'ms.map_id = m.id')->join('map_stage_courses msc', 'msc.stage_id = ms.id')->join('courses c', 'c.id = msc.course_id')->where('m.status', 'published')->where('c.status', '<>', 'published');
        return (int) $this->applyScope($query, $scope, $staffAccountId, 'm.department_id', 'm.created_by_staff_id')->count('DISTINCT m.id');
    }

    /** @param ScopeShape $scope */
    private function unpublishedCourseCount(array $scope, int $staffAccountId): int
    {
        $query = Db::name('courses')->alias('c')->whereIn('c.status', ['draft', 'unpublished']);
        return (int) $this->applyScope($query, $scope, $staffAccountId, 'c.department_id', 'c.created_by_staff_id')->count();
    }

    /** @param ScopeShape $scope */
    private function pendingOrderCount(array $scope, int $staffAccountId): int
    {
        $query = Db::name('orders')->alias('o')->join('courses c', 'c.id = o.course_id')->where('o.status', 'pending');
        return (int) $this->applyScope($query, $scope, $staffAccountId, 'c.department_id', 'c.created_by_staff_id')->count();
    }

    /**
     * @param ScopeShape $scope
     * @param DashboardWindow $window
     */
    private function succeededOrderCount(array $scope, int $staffAccountId, array $window): int
    {
        $query = Db::name('orders')->alias('o')->join('courses c', 'c.id = o.course_id')->where('o.status', 'succeeded')->where('o.succeeded_at', '>=', $window['start'])->where('o.succeeded_at', '<', $window['end']);
        return (int) $this->applyScope($query, $scope, $staffAccountId, 'c.department_id', 'c.created_by_staff_id')->count();
    }

    /**
     * @param ScopeShape $scope
     * @param DashboardWindow $window
     */
    private function paidAmount(array $scope, int $staffAccountId, array $window): float
    {
        $query = Db::name('orders')->alias('o')->join('courses c', 'c.id = o.course_id')->where('o.status', 'succeeded')->where('o.succeeded_at', '>=', $window['start'])->where('o.succeeded_at', '<', $window['end']);
        $value = $this->applyScope($query, $scope, $staffAccountId, 'c.department_id', 'c.created_by_staff_id')->sum('o.paid_amount');
        return round((float) $value, 2);
    }

    /**
     * @param ScopeShape $scope
     * @return array{draft:int,published:int,unpublished:int}
     */
    private function courseInventory(array $scope, int $staffAccountId, bool $needed): array
    {
        if (!$needed) {
            return ['draft' => 0, 'published' => 0, 'unpublished' => 0];
        }
        $query = Db::name('courses')->alias('c')->field('c.status AS status, COUNT(*) AS total')->group('c.status');
        $rows = $this->applyScope($query, $scope, $staffAccountId, 'c.department_id', 'c.created_by_staff_id')->select()->toArray();
        $result = ['draft' => 0, 'published' => 0, 'unpublished' => 0];
        foreach ($rows as $row) {
            $status = (string) ($row['status'] ?? '');
            if (array_key_exists($status, $result)) {
                $result[$status] = (int) $row['total'];
            }
        }
        return $result;
    }

    /**
     * @param ScopeShape $scope
     * @param DashboardWindow $window
     * @return list<array{date:string,created_orders:int,succeeded_orders:int,paid_amount:float}>
     */
    private function orderTrend(array $scope, int $staffAccountId, array $window): array
    {
        $createdQuery = Db::name('orders')->alias('o')->join('courses c', 'c.id = o.course_id')->where('o.created_at', '>=', $window['start'])->where('o.created_at', '<', $window['end'])->field($this->localDayExpression('o.created_at') . ' AS day, COUNT(*) AS total')->group('day');
        $createdRows = $this->applyScope($createdQuery, $scope, $staffAccountId, 'c.department_id', 'c.created_by_staff_id')->select()->toArray();

        $succeededQuery = Db::name('orders')->alias('o')->join('courses c', 'c.id = o.course_id')->where('o.status', 'succeeded')->where('o.succeeded_at', '>=', $window['start'])->where('o.succeeded_at', '<', $window['end'])->field($this->localDayExpression('o.succeeded_at') . ' AS day, COUNT(*) AS total, SUM(o.paid_amount) AS amount')->group('day');
        $succeededRows = $this->applyScope($succeededQuery, $scope, $staffAccountId, 'c.department_id', 'c.created_by_staff_id')->select()->toArray();

        $created = [];
        foreach ($createdRows as $row) {
            $created[(string) $row['day']] = (int) $row['total'];
        }
        $succeeded = [];
        foreach ($succeededRows as $row) {
            $succeeded[(string) $row['day']] = ['count' => (int) $row['total'], 'amount' => round((float) $row['amount'], 2)];
        }

        $points = [];
        for ($day = $window['start_local']; $day < $window['end_local']; $day = $day->modify('+1 day')) {
            $date = $day->format('Y-m-d');
            $points[] = ['date' => $date, 'created_orders' => $created[$date] ?? 0, 'succeeded_orders' => $succeeded[$date]['count'] ?? 0, 'paid_amount' => $succeeded[$date]['amount'] ?? 0.0];
        }
        return $points;
    }

    /**
     * @param ScopeShape $scope
     * @return list<array{id:int,course_id:int,course_title:string,status:string,paid_amount:float,created_at:string}>
     */
    private function recentOrders(array $scope, int $staffAccountId): array
    {
        $query = Db::name('orders')->alias('o')->join('courses c', 'c.id = o.course_id')->field('o.id,o.course_id,c.title AS course_title,o.status,o.paid_amount,o.created_at');
        $rows = $this->applyScope($query, $scope, $staffAccountId, 'c.department_id', 'c.created_by_staff_id')->order('o.id', 'desc')->limit(self::RECENT_ORDERS_LIMIT)->select()->toArray();
        return array_map(static fn (array $row): array => ['id' => (int) $row['id'], 'course_id' => (int) $row['course_id'], 'course_title' => (string) $row['course_title'], 'status' => (string) $row['status'], 'paid_amount' => (float) $row['paid_amount'], 'created_at' => (string) $row['created_at']], $rows);
    }

    /** @return DashboardWindow */
    private function rangeWindow(int $days): array
    {
        $local = new DateTimeZone(self::TIMEZONE);
        $endLocal = (new DateTimeImmutable('now', $local))->modify('+1 day')->setTime(0, 0, 0);
        $startLocal = $endLocal->modify('-' . $days . ' days');
        return ['start' => $this->toDatabaseTime($startLocal), 'end' => $this->toDatabaseTime($endLocal), 'start_local' => $startLocal, 'end_local' => $endLocal, 'range_days' => $days];
    }

    private function toDatabaseTime(DateTimeImmutable $value): string
    {
        return $value->setTimezone($this->databaseTimezone())->format('Y-m-d H:i:s');
    }

    private function databaseTimezone(): DateTimeZone
    {
        $name = (string) (getenv('TZ') ?: 'UTC');
        try {
            return new DateTimeZone($name);
        } catch (\Exception) {
            return new DateTimeZone('UTC');
        }
    }

    private function localDayExpression(string $field): string
    {
        $dbOffset = (new DateTimeImmutable('now', $this->databaseTimezone()))->format('P');
        return "DATE_FORMAT(CONVERT_TZ($field, '$dbOffset', '+08:00'), '%Y-%m-%d')";
    }

    /** @param ScopeShape $scope */
    private function applyScope(Query $query, array $scope, int $staffAccountId, string $departmentField, string $creatorField): Query
    {
        if ($scope['all']) {
            return $query;
        }
        if ($scope['department_ids'] === [] && !$scope['include_self']) {
            return $query->where($departmentField, -1);
        }
        return $query->where(function (Query $where) use ($scope, $staffAccountId, $departmentField, $creatorField): void {
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
