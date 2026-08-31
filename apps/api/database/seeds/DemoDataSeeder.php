<?php

declare(strict_types=1);

use Phinx\Seed\AbstractSeed;

/**
 * DemoDataSeeder — bulk mock data for local development / UI testing.
 *
 * Idempotent: skips when demo learner phones (1389000xxxx) already exist.
 * Default password for all demo learners: DemoPass123!
 *
 * Override counts via env:
 *   DEMO_SEED_LEARNERS  (default 120)
 *   DEMO_SEED_COURSES   (default 25)
 */
final class DemoDataSeeder extends AbstractSeed
{
    private const DEMO_PHONE_PREFIX = '1389000';
    private const DEMO_PASSWORD = 'DemoPass123!';

    private const COURSE_TITLES = [
        'Go 并发编程实战', 'Vue3 组件设计模式', 'TypeScript 类型体操',
        'MySQL 性能调优', 'Redis 缓存架构', 'Docker 容器化部署',
        'Kubernetes 入门到实战', '微服务架构设计', 'REST API 设计规范',
        '单元测试与 TDD', '前端工程化实践', 'Node.js 后端开发',
        'Python 数据分析', '算法与数据结构', '系统设计面试指南',
        'Git 工作流最佳实践', 'CI/CD 流水线搭建', '安全编码基础',
        'GraphQL 实战', 'WebSocket 实时通信', 'Nginx 反向代理',
        'Linux 运维基础', '消息队列 Kafka', 'ElasticSearch 搜索',
        'Flutter 跨端开发',
    ];

    private const NICKNAME_PARTS = [
        '学习', '代码', '极客', '小白', '进阶', '探索', '勤奋', '好奇',
        '夜猫', '晨曦', '星辰', '云端', '字节', '像素', '逻辑', '思维',
    ];

    private const TEACHERS = [
        '张老师', '李老师', '王老师', '陈老师', '刘老师', '赵老师', '周老师',
    ];

    public function run(): void
    {
        $pdo = $this->getAdapter()->getConnection();
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $learnerCount = max(1, (int) (getenv('DEMO_SEED_LEARNERS') ?: 120));
        $courseCount = max(1, min(count(self::COURSE_TITLES), (int) (getenv('DEMO_SEED_COURSES') ?: 25)));

        $exists = $pdo->prepare(
            'SELECT 1 FROM accounts WHERE kind = ? AND login LIKE ? LIMIT 1',
        );
        $exists->execute(['learner', self::DEMO_PHONE_PREFIX . '%']);
        if ($exists->fetchColumn() !== false) {
            echo "[seed] Demo learners already exist — skipping DemoDataSeeder\n";
            return;
        }

        $staffId = (int) $pdo->query(
            'SELECT account_id FROM staff_users ORDER BY account_id LIMIT 1',
        )->fetchColumn();
        if ($staffId <= 0) {
            echo "[seed] No staff user found — run SuperAdminSeeder first\n";
            return;
        }

        $departmentId = (int) $pdo->query(
            "SELECT id FROM departments WHERE status = 'enabled' ORDER BY id LIMIT 1",
        )->fetchColumn();
        if ($departmentId <= 0) {
            echo "[seed] No enabled department found — create one first\n";
            return;
        }

        $categoryIds = $pdo->query(
            "SELECT id FROM categories WHERE status = 'enabled' ORDER BY id",
        )->fetchAll(PDO::FETCH_COLUMN);
        if ($categoryIds === []) {
            echo "[seed] No enabled categories found — create one first\n";
            return;
        }

        $now = date('Y-m-d H:i:s');
        $passwordHash = password_hash(self::DEMO_PASSWORD, PASSWORD_DEFAULT);
        $inserted = 0;

        $learnerIds = $this->seedLearners($pdo, $learnerCount, $passwordHash, $now);
        $inserted += $learnerCount * 2;

        $courseMeta = $this->seedCourses(
            $pdo,
            $courseCount,
            $departmentId,
            $categoryIds,
            $staffId,
            $now,
        );
        $inserted += $courseCount
            + count($courseMeta['chapter_ids'])
            + count($courseMeta['lesson_ids']);

        $inserted += $this->seedEntitlementsAndEnrollments(
            $pdo,
            $learnerIds,
            $courseMeta['course_ids'],
            $courseMeta['lesson_ids'],
            $now,
        );

        $inserted += $this->seedLessonProgresses(
            $pdo,
            $learnerIds,
            $courseMeta['lesson_ids'],
            $now,
        );

        $inserted += $this->seedReviews(
            $pdo,
            $learnerIds,
            $courseMeta['course_ids'],
            $now,
        );

        $inserted += $this->seedQuestions(
            $pdo,
            $learnerIds,
            $courseMeta,
            $now,
        );

        $inserted += $this->seedFavorites(
            $pdo,
            $learnerIds,
            $courseMeta['course_ids'],
            $now,
        );

        $inserted += $this->seedNotifications(
            $pdo,
            $learnerIds,
            $courseMeta['course_ids'],
            $now,
        );

        $inserted += $this->seedDailyCheckins(
            $pdo,
            $learnerIds,
            $now,
        );

        echo sprintf(
            "[seed] DemoDataSeeder inserted ~%d rows (%d learners, %d courses, password: %s)\n",
            $inserted,
            $learnerCount,
            $courseCount,
            self::DEMO_PASSWORD,
        );
    }

    /** @return list<int> */
    private function seedLearners(PDO $pdo, int $count, string $passwordHash, string $now): array
    {
        $accountStmt = $pdo->prepare(
            'INSERT INTO accounts (kind, login, password_hash, must_change_password, status, created_at, updated_at)
             VALUES (?, ?, ?, 0, ?, ?, ?)',
        );
        $learnerStmt = $pdo->prepare(
            'INSERT INTO learners (account_id, nickname, avatar_url, show_on_course, created_at, updated_at)
             VALUES (?, ?, NULL, ?, ?, ?)',
        );

        $ids = [];
        for ($i = 1; $i <= $count; $i++) {
            $phone = self::DEMO_PHONE_PREFIX . str_pad((string) $i, 4, '0', STR_PAD_LEFT);
            $accountStmt->execute(['learner', $phone, $passwordHash, 'active', $now, $now]);
            $accountId = (int) $pdo->lastInsertId();
            $nickname = $this->pick(self::NICKNAME_PARTS)
                . $this->pick(self::NICKNAME_PARTS)
                . $i;
            $learnerStmt->execute([
                $accountId,
                mb_substr($nickname, 0, 32),
                random_int(0, 4) === 0 ? 1 : 0,
                $now,
                $now,
            ]);
            $ids[] = $accountId;
        }

        return $ids;
    }

    /**
     * @param list<int> $categoryIds
     * @return array{course_ids: list<int>, chapter_ids: list<int>, lesson_ids: list<int>, lesson_map: array<int, array{course_id: int, chapter_id: int}>}
     */
    private function seedCourses(
        PDO $pdo,
        int $count,
        int $departmentId,
        array $categoryIds,
        int $staffId,
        string $now,
    ): array {
        $courseStmt = $pdo->prepare(
            'INSERT INTO courses (
                department_id, category_id, title, cover_url, teacher_name, summary,
                intro_rich_text, status, price_mode, list_price, sale_price,
                sale_start_at, sale_end_at, created_by_staff_id, created_at, updated_at
             ) VALUES (?, ?, ?, NULL, ?, ?, ?, ?, ?, ?, 0, NULL, NULL, ?, ?, ?)',
        );
        $chapterStmt = $pdo->prepare(
            'INSERT INTO chapters (course_id, title, sort, status, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?)',
        );
        $lessonStmt = $pdo->prepare(
            'INSERT INTO lessons (
                chapter_id, title, sort, status, content_type, body_markdown,
                asset_id, is_preview, duration_seconds, created_at, updated_at
             ) VALUES (?, ?, ?, ?, ?, ?, NULL, ?, ?, ?, ?)',
        );

        $courseIds = [];
        $chapterIds = [];
        $lessonIds = [];
        $lessonMap = [];

        for ($c = 0; $c < $count; $c++) {
            $title = self::COURSE_TITLES[$c % count(self::COURSE_TITLES)]
                . ($c >= count(self::COURSE_TITLES) ? ' 进阶' . ($c + 1) : '');
            $isPaid = $c % 3 === 2;
            $listPrice = $isPaid ? random_int(29, 299) : 0;
            $courseStmt->execute([
                $departmentId,
                $categoryIds[$c % count($categoryIds)],
                mb_substr($title, 0, 128),
                $this->pick(self::TEACHERS),
                mb_substr($title . ' — 系统讲解核心知识点与实战案例', 0, 255),
                '<p>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . ' 课程简介, 含章节练习与项目实战。</p>',
                'published',
                $isPaid ? 'paid' : 'free',
                $listPrice,
                $staffId,
                $now,
                $now,
            ]);
            $courseId = (int) $pdo->lastInsertId();
            $courseIds[] = $courseId;

            $chapterCount = 3;
            $lessonsPerChapter = 4;
            for ($ch = 0; $ch < $chapterCount; $ch++) {
                $chapterStmt->execute([
                    $courseId,
                    sprintf('第 %d 章 %s', $ch + 1, $this->pick(['基础', '进阶', '实战', '总结'])),
                    $ch,
                    'enabled',
                    $now,
                    $now,
                ]);
                $chapterId = (int) $pdo->lastInsertId();
                $chapterIds[] = $chapterId;

                for ($ls = 0; $ls < $lessonsPerChapter; $ls++) {
                    $lessonTitle = sprintf('第 %d.%d 节 %s', $ch + 1, $ls + 1, $this->pick([
                        '概念讲解', '代码演示', '动手练习', '常见问题', '最佳实践',
                    ]));
                    $lessonStmt->execute([
                        $chapterId,
                        mb_substr($lessonTitle, 0, 128),
                        $ls,
                        'enabled',
                        'markdown',
                        '# ' . $lessonTitle . "\n\n本节为模拟课节内容, 用于本地 UI 与分页测试。",
                        $ch === 0 && $ls === 0 ? 1 : 0,
                        random_int(300, 1800),
                        $now,
                        $now,
                    ]);
                    $lessonId = (int) $pdo->lastInsertId();
                    $lessonIds[] = $lessonId;
                    $lessonMap[$lessonId] = [
                        'course_id' => $courseId,
                        'chapter_id' => $chapterId,
                    ];
                }
            }
        }

        return [
            'course_ids' => $courseIds,
            'chapter_ids' => $chapterIds,
            'lesson_ids' => $lessonIds,
            'lesson_map' => $lessonMap,
        ];
    }

    /**
     * @param list<int> $learnerIds
     * @param list<int> $courseIds
     * @param list<int> $lessonIds
     */
    private function seedEntitlementsAndEnrollments(
        PDO $pdo,
        array $learnerIds,
        array $courseIds,
        array $lessonIds,
        string $now,
    ): int {
        $entStmt = $pdo->prepare(
            'INSERT INTO course_entitlements (learner_id, course_id, source, order_id, status, created_at, updated_at)
             VALUES (?, ?, ?, NULL, ?, ?, ?)',
        );
        $enrollStmt = $pdo->prepare(
            'INSERT INTO course_enrollments (
                learner_id, course_id, progress_percent, last_lesson_id, last_position,
                completed_at, created_at, updated_at
             ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
        );

        $rows = 0;
        foreach ($learnerIds as $learnerId) {
            $pickedCourses = $this->pickMany($courseIds, random_int(2, min(6, count($courseIds))));
            foreach ($pickedCourses as $courseId) {
                $entStmt->execute([
                    $learnerId,
                    $courseId,
                    'free',
                    'active',
                    $now,
                    $now,
                ]);
                $rows++;

                $progress = random_int(0, 100);
                $lastLesson = $lessonIds[array_rand($lessonIds)];
                $completedAt = $progress >= 100 ? $now : null;
                $enrollStmt->execute([
                    $learnerId,
                    $courseId,
                    $progress,
                    $lastLesson,
                    random_int(0, 300),
                    $completedAt,
                    $now,
                    $now,
                ]);
                $rows++;
            }
        }

        return $rows;
    }

    /**
     * @param list<int> $learnerIds
     * @param list<int> $lessonIds
     */
    private function seedLessonProgresses(PDO $pdo, array $learnerIds, array $lessonIds, string $now): int
    {
        $stmt = $pdo->prepare(
            'INSERT INTO lesson_progresses (
                learner_id, lesson_id, position_seconds, opened_at, completed,
                completed_at, created_at, updated_at
             ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
        );

        $rows = 0;
        foreach ($learnerIds as $learnerId) {
            $picked = $this->pickMany($lessonIds, random_int(4, min(10, count($lessonIds))));
            foreach ($picked as $lessonId) {
                $completed = random_int(0, 3) > 0 ? 1 : 0;
                $stmt->execute([
                    $learnerId,
                    $lessonId,
                    random_int(1, 600),
                    $now,
                    $completed,
                    $completed ? $now : null,
                    $now,
                    $now,
                ]);
                $rows++;
            }
        }

        return $rows;
    }

    /**
     * @param list<int> $learnerIds
     * @param list<int> $courseIds
     */
    private function seedReviews(PDO $pdo, array $learnerIds, array $courseIds, string $now): int
    {
        $stmt = $pdo->prepare(
            'INSERT INTO reviews (
                course_id, learner_id, rating, body, visibility, active_key,
                hidden_reason, hidden_by_staff_id, hidden_at, created_at, updated_at
             ) VALUES (?, ?, ?, ?, ?, ?, NULL, NULL, NULL, ?, ?)',
        );

        $bodies = [
            '课程内容很实用, 讲解清晰。',
            '老师讲得很好, 案例贴近工作场景。',
            '节奏适中, 适合有一定基础的同学。',
            '希望能增加更多实战项目。',
            '整体不错, 推荐学习。',
        ];

        $rows = 0;
        $reviewers = $this->pickMany($learnerIds, min(80, count($learnerIds)));
        foreach ($reviewers as $learnerId) {
            $courseId = $courseIds[array_rand($courseIds)];
            $visibility = random_int(0, 9) === 0 ? 'hidden' : 'public';
            $activeKey = $visibility === 'public' ? "{$learnerId}:{$courseId}" : null;
            try {
                $stmt->execute([
                    $courseId,
                    $learnerId,
                    random_int(3, 5),
                    $this->pick($bodies),
                    $visibility,
                    $activeKey,
                    $now,
                    $now,
                ]);
                $rows++;
            } catch (PDOException) {
                // unique active_key — skip duplicate learner/course pair
            }
        }

        return $rows;
    }

    /**
     * @param list<int> $learnerIds
     * @param array{course_ids: list<int>, lesson_map: array<int, array{course_id: int, chapter_id: int}>} $courseMeta
     */
    private function seedQuestions(
        PDO $pdo,
        array $learnerIds,
        array $courseMeta,
        string $now,
    ): int {
        $qStmt = $pdo->prepare(
            'INSERT INTO questions (
                course_id, chapter_id, lesson_id, learner_id, title, body,
                status, answered_at, answered_by_staff_id, created_at, updated_at
             ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NULL, ?, ?)',
        );

        $lessonIds = array_keys($courseMeta['lesson_map']);
        $rows = 0;
        $askers = $this->pickMany($learnerIds, min(60, count($learnerIds)));
        foreach ($askers as $learnerId) {
            $lessonId = $lessonIds[array_rand($lessonIds)];
            $meta = $courseMeta['lesson_map'][$lessonId];
            $status = $this->pick(['pending', 'answered', 'closed']);
            $qStmt->execute([
                $meta['course_id'],
                $meta['chapter_id'],
                $lessonId,
                $learnerId,
                '关于本节内容的疑问',
                '请问这一节里的示例代码在生产环境需要注意什么?',
                $status,
                $status === 'answered' ? $now : null,
                $now,
                $now,
            ]);
            $rows++;
        }

        return $rows;
    }

    /**
     * @param list<int> $learnerIds
     * @param list<int> $courseIds
     */
    private function seedFavorites(PDO $pdo, array $learnerIds, array $courseIds, string $now): int
    {
        $stmt = $pdo->prepare(
            'INSERT INTO favorites (learner_id, course_id, created_at) VALUES (?, ?, ?)',
        );

        $rows = 0;
        foreach ($this->pickMany($learnerIds, min(50, count($learnerIds))) as $learnerId) {
            foreach ($this->pickMany($courseIds, random_int(1, 3)) as $courseId) {
                try {
                    $stmt->execute([$learnerId, $courseId, $now]);
                    $rows++;
                } catch (PDOException) {
                    // unique (learner, course)
                }
            }
        }

        return $rows;
    }

    /**
     * @param list<int> $learnerIds
     * @param list<int> $courseIds
     */
    private function seedNotifications(
        PDO $pdo,
        array $learnerIds,
        array $courseIds,
        string $now,
    ): int {
        $stmt = $pdo->prepare(
            'INSERT INTO learner_notifications (
                learner_id, kind, title, body, payload_json,
                resource_type, resource_id, idempotency_key, read_at, created_at
             ) VALUES (?, ?, ?, ?, NULL, ?, ?, ?, ?, ?)',
        );

        $kinds = ['question_update', 'progress_reset', 'entitlement_revoked'];
        $titles = [
            'question_update' => '你的提问有新回复',
            'progress_reset' => '学习进度已重置',
            'entitlement_revoked' => '课程访问权已调整',
        ];

        $rows = 0;
        for ($i = 0; $i < 100; $i++) {
            $learnerId = $learnerIds[array_rand($learnerIds)];
            $kind = $this->pick($kinds);
            $courseId = $courseIds[array_rand($courseIds)];
            $readAt = random_int(0, 1) ? $now : null;
            $stmt->execute([
                $learnerId,
                $kind,
                $titles[$kind],
                '这是一条模拟站内通知, 用于测试消息列表与分页。',
                'course',
                $courseId,
                'demo-seed-' . $i . '-' . $learnerId,
                $readAt,
                $now,
            ]);
            $rows++;
        }

        return $rows;
    }

    /** @param list<int> $learnerIds */
    private function seedDailyCheckins(PDO $pdo, array $learnerIds, string $now): int
    {
        $stmt = $pdo->prepare(
            'INSERT INTO learner_daily_checkins (learner_id, checkin_date, plan_html, checked_in_at, created_at)
             VALUES (?, ?, ?, ?, ?)',
        );

        $rows = 0;
        $tz = new DateTimeZone('Asia/Shanghai');
        $today = new DateTimeImmutable('today', $tz);

        foreach ($this->pickMany($learnerIds, min(80, count($learnerIds))) as $learnerId) {
            $daysBack = random_int(0, 14);
            $date = $today->sub(new DateInterval('P' . $daysBack . 'D'))->format('Y-m-d');
            try {
                $stmt->execute([
                    $learnerId,
                    $date,
                    '<p>今日计划: 完成 ' . random_int(1, 3) . ' 节课, 复习笔记。</p>',
                    $now,
                    $now,
                ]);
                $rows++;
            } catch (PDOException) {
                // unique (learner, date)
            }
        }

        return $rows;
    }

    /** @template T */
    private function pick(array $items): mixed
    {
        return $items[array_rand($items)];
    }

    /**
     * @template T
     * @param list<T> $items
     * @return list<T>
     */
    private function pickMany(array $items, int $count): array
    {
        if ($count >= count($items)) {
            return $items;
        }
        $keys = array_rand($items, $count);
        if (!is_array($keys)) {
            return [$items[$keys]];
        }
        return array_values(array_map(static fn (int $k) => $items[$k], $keys));
    }
}
