<?php

declare(strict_types=1);

namespace Tests;

use App\service\ActivationCodeService;
use App\service\BusinessException;
use App\service\EntitlementService;
use PHPUnit\Framework\TestCase;
use support\App;
use support\think\Db;
use Webman\ThinkOrm\ThinkOrm;

/**
 * 010-course-notify-feedback-codes / US2 + US3 — activation code lifecycle.
 *
 * The concurrency regression uses forked workers with independent database
 * connections so the row-lock and unique-index contract is exercised for real.
 */
final class ActivationCodeTest extends TestCase
{
    private int $staffId;
    private ActivationCodeService $service;

    public static function setUpBeforeClass(): void
    {
        App::loadAllConfig(['route', 'container']);
        ThinkOrm::start(null);
    }

    protected function setUp(): void
    {
        Db::startTrans();
        $this->staffId = $this->insertStaff();
        $this->service = new ActivationCodeService();
    }

    protected function tearDown(): void
    {
        Db::rollback();
    }

    // ── US2: 生成 ─────────────────────────────────────────────────────────

    public function testGenerateBatchCreatesUniqueCodesAndReturnsPlaintextOnce(): void
    {
        $courseId = $this->insertCourse('published', 'paid');
        $batch = $this->service->generateBatch($courseId, 3, null, $this->staffId);

        self::assertSame(3, $batch['quantity']);
        self::assertCount(3, $batch['codes']);
        self::assertCount(3, array_unique($batch['codes']));

        foreach ($batch['codes'] as $plain) {
            self::assertMatchesRegularExpression('/^[0-9A-Z]{4}-[0-9A-Z]{4}-[0-9A-Z]{4}-[0-9A-Z]{4}$/', $plain);
        }

        $rows = Db::name('activation_codes')->where('batch_id', $batch['id'])->select()->toArray();
        self::assertCount(3, $rows);
        foreach ($rows as $index => $row) {
            $normalized = str_replace('-', '', $batch['codes'][$index]);
            self::assertSame(hash('sha256', $normalized), (string) $row['code_hash']);
            self::assertSame(substr($normalized, 0, 4), (string) $row['code_prefix']);
            self::assertSame(substr($normalized, -4), (string) $row['code_suffix']);
            self::assertSame('unused', (string) $row['status']);
            self::assertSame($courseId, (int) $row['course_id']);
            self::assertMatchesRegularExpression(
                '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/',
                (string) $row['created_at'],
            );
            self::assertSame((string) $row['created_at'], (string) $row['updated_at']);
        }

        $audit = Db::name('audit_log')
            ->where('action', 'activation_code.batch_create')
            ->where('target_id', (int) $batch['id'])
            ->value('payload_json');
        self::assertIsString($audit);
        // 明文绝不进入审计载荷。
        self::assertStringNotContainsString((string) $batch['codes'][0], $audit);
    }

    public function testGenerateBatchCopiesExpiresAtToEveryCode(): void
    {
        $courseId = $this->insertCourse('published', 'paid');
        $future = (new \DateTimeImmutable('now', new \DateTimeZone('Asia/Shanghai')))
            ->modify('+30 days')->format('Y-m-d H:i:s');
        $batch = $this->service->generateBatch($courseId, 2, $future, $this->staffId);

        $rows = Db::name('activation_codes')->where('batch_id', $batch['id'])->column('expires_at');
        self::assertCount(2, $rows);
        foreach ($rows as $expiresAt) {
            self::assertSame($future, (string) $expiresAt);
        }
    }

    public function testGenerateRejectsNonPublishedOrFreeCourses(): void
    {
        $draft = $this->insertCourse('draft', 'paid');
        $free = $this->insertCourse('published', 'free');

        try {
            $this->service->generateBatch($draft, 1, null, $this->staffId);
            self::fail('draft course must be rejected');
        } catch (BusinessException $e) {
            self::assertSame('COURSE_NOT_PUBLISHED', $e->getMessage());
        }
        try {
            $this->service->generateBatch($free, 1, null, $this->staffId);
            self::fail('free course must be rejected');
        } catch (BusinessException $e) {
            self::assertSame('COURSE_NOT_PAID', $e->getMessage());
        }
    }

    public function testGenerateRejectsInvalidQuantityOrExpires(): void
    {
        $courseId = $this->insertCourse('published', 'paid');
        foreach ([0, 1001, -5] as $quantity) {
            try {
                $this->service->generateBatch($courseId, $quantity, null, $this->staffId);
                self::fail("quantity {$quantity} must be rejected");
            } catch (BusinessException $e) {
                self::assertSame('ACTIVATION_CODE_QUANTITY_INVALID', $e->getMessage());
            }
        }
        $past = (new \DateTimeImmutable('now', new \DateTimeZone('Asia/Shanghai')))
            ->modify('-1 hour')->format('Y-m-d H:i:s');
        try {
            $this->service->generateBatch($courseId, 1, $past, $this->staffId);
            self::fail('past expires_at must be rejected');
        } catch (BusinessException $e) {
            self::assertSame('ACTIVATION_CODE_EXPIRES_INVALID', $e->getMessage());
        }
    }

    // ── US2: 列表与作废 ───────────────────────────────────────────────────

    public function testListIsMaskedAndSupportsDerivedExpiredFilter(): void
    {
        $courseId = $this->insertCourse('published', 'paid');
        $batch = $this->service->generateBatch($courseId, 3, null, $this->staffId);
        $plain = $batch['codes'][0];
        // Make one code expired by moving its expires_at into the past.
        Db::name('activation_codes')->where('batch_id', $batch['id'])->where('code_prefix', substr($plain, 0, 4))
            ->update(['expires_at' => '2020-01-01 00:00:00']);

        $list = $this->service->listCodes($this->staffId, $courseId, []);
        self::assertSame(3, $list['total']);
        foreach ($list['items'] as $item) {
            self::assertMatchesRegularExpression('/^[0-9A-Z]{4}\*\*\*\*[0-9A-Z]{4}$/', $item['display_code']);
            self::assertArrayNotHasKey('code_hash', $item);
        }

        $expired = $this->service->listCodes($this->staffId, $courseId, ['status' => 'expired']);
        self::assertSame(1, $expired['total']);
        self::assertSame('expired', $expired['items'][0]['status']);

        $unused = $this->service->listCodes($this->staffId, $courseId, ['status' => 'unused']);
        // 「未使用」筛选只返回当前仍可兑换的 unused 行; 已过期单列 expired。
        self::assertSame(2, $unused['total']);
    }

    public function testVoidMarksUnusedCodeAndRejectsRedeemedCode(): void
    {
        $courseId = $this->insertCourse('published', 'paid');
        $batch = $this->service->generateBatch($courseId, 2, null, $this->staffId);
        $codeId = $this->codeIdByPlain($batch['codes'][0]);

        $result = $this->service->voidCode($this->staffId, $courseId, $codeId);
        self::assertTrue($result['voided']);
        self::assertSame(
            'void',
            (string) Db::name('activation_codes')->where('id', $codeId)->value('status'),
        );
        self::assertSame(
            1,
            (int) Db::name('audit_log')->where('action', 'activation_code.void')->where('target_id', $codeId)->count(),
        );

        // 已作废不可再作废。
        try {
            $this->service->voidCode($this->staffId, $courseId, $codeId);
            self::fail('voided code must not be voidable again');
        } catch (BusinessException $e) {
            self::assertSame('ACTIVATION_CODE_NOT_VOIDABLE', $e->getMessage());
        }

        // 已兑换的码不可作废; 作废尝试不改变其状态。
        $otherId = $this->codeIdByPlain($batch['codes'][1]);
        $learnerId = $this->insertLearner();
        Db::name('activation_codes')->where('id', $otherId)->update([
            'status' => 'redeemed',
            'redeemed_by_learner_id' => $learnerId,
            'redeemed_at' => date('Y-m-d H:i:s'),
        ]);
        try {
            $this->service->voidCode($this->staffId, $courseId, $otherId);
            self::fail('redeemed code must not be voidable');
        } catch (BusinessException $e) {
            self::assertSame('ACTIVATION_CODE_NOT_VOIDABLE', $e->getMessage());
        }
        self::assertSame(
            'redeemed',
            (string) Db::name('activation_codes')->where('id', $otherId)->value('status'),
        );
    }

    // ── US3: 兑换 ─────────────────────────────────────────────────────────

    public function testRedeemGrantsEntitlementWithoutOrder(): void
    {
        $courseId = $this->insertCourse('published', 'paid');
        $batch = $this->service->generateBatch($courseId, 1, null, $this->staffId);
        $code = $batch['codes'][0];
        $learnerId = $this->insertLearner();

        $result = $this->service->redeem($learnerId, $code);
        self::assertTrue($result['granted']);
        self::assertSame($courseId, $result['course_id']);
        self::assertSame('activation_code', $result['source']);

        $entitlement = Db::name('course_entitlements')
            ->where('learner_id', $learnerId)
            ->where('course_id', $courseId)
            ->where('status', 'active')
            ->find();
        self::assertIsArray($entitlement);
        self::assertSame('activation_code', (string) $entitlement['source']);
        self::assertNull($entitlement['order_id']);
        self::assertNotNull($entitlement['activation_code_id']);

        $normalized = str_replace('-', '', $code);
        $codeHash = hash('sha256', $normalized);

        $codeRow = Db::name('activation_codes')->where('code_hash', $codeHash)->find();
        self::assertIsArray($codeRow);
        self::assertSame('redeemed', (string) $codeRow['status']);
        self::assertSame($learnerId, (int) $codeRow['redeemed_by_learner_id']);
        self::assertNotNull($codeRow['redeemed_at']);
        self::assertSame((int) $codeRow['id'], (int) $entitlement['activation_code_id']);

        // 不创建任何购买订单 (FR-013)。
        self::assertSame(0, (int) Db::name('orders')->where('learner_id', $learnerId)->count());
        self::assertSame(
            1,
            (int) Db::name('audit_log')
                ->where('action', 'activation_code.redeem')
                ->where('target_id', (int) $codeRow['id'])
                ->count(),
        );
    }

    public function testRedeemNormalizesCaseAndDashes(): void
    {
        $courseId = $this->insertCourse('published', 'paid');
        $batch = $this->service->generateBatch($courseId, 1, null, $this->staffId);
        $code = strtolower(str_replace('-', ' ', (string) $batch['codes'][0]));
        $learnerId = $this->insertLearner();

        $result = $this->service->redeem($learnerId, $code);
        self::assertTrue($result['granted']);
    }

    public function testRedeemRejectsUnknownOrMalformedCodes(): void
    {
        $courseId = $this->insertCourse('published', 'paid');
        $this->service->generateBatch($courseId, 1, null, $this->staffId);
        $learnerId = $this->insertLearner();

        foreach (['', 'ABCD', 'ABCD-EFGH-JKMN-PQR!', 'AB3D-EFGH-IKMN-PQRS'] as $input) {
            try {
                $this->service->redeem($learnerId, $input);
                self::fail("malformed code [{$input}] must be rejected");
            } catch (BusinessException $e) {
                self::assertSame('ACTIVATION_CODE_INVALID', $e->getMessage());
            }
        }
    }

    public function testRedeemTwiceReturnsRedeemedError(): void
    {
        $courseId = $this->insertCourse('published', 'paid');
        $code = $this->service->generateBatch($courseId, 1, null, $this->staffId)['codes'][0];
        $learnerA = $this->insertLearner();
        $learnerB = $this->insertLearner();

        $this->service->redeem($learnerA, $code);
        try {
            $this->service->redeem($learnerB, $code);
            self::fail('second redemption must fail');
        } catch (BusinessException $e) {
            self::assertSame('ACTIVATION_CODE_REDEEMED', $e->getMessage());
        }
        self::assertSame(
            $learnerA,
            (int) Db::name('activation_codes')->where('code_hash', hash('sha256', str_replace('-', '', $code)))->value('redeemed_by_learner_id'),
        );
    }

    public function testRedeemRejectsVoidedAndExpiredCodes(): void
    {
        $courseId = $this->insertCourse('published', 'paid');
        $batch = $this->service->generateBatch($courseId, 2, null, $this->staffId);
        $voidId = $this->codeIdByPlain($batch['codes'][0]);
        $this->service->voidCode($this->staffId, $courseId, $voidId);

        $learner = $this->insertLearner();
        try {
            $this->service->redeem($learner, $batch['codes'][0]);
            self::fail('voided code must not redeem');
        } catch (BusinessException $e) {
            self::assertSame('ACTIVATION_CODE_VOID', $e->getMessage());
        }

        $expiredId = $this->codeIdByPlain($batch['codes'][1]);
        Db::name('activation_codes')->where('id', $expiredId)->update(['expires_at' => '2020-01-01 00:00:00']);
        try {
            $this->service->redeem($learner, $batch['codes'][1]);
            self::fail('expired code must not redeem');
        } catch (BusinessException $e) {
            self::assertSame('ACTIVATION_CODE_EXPIRED', $e->getMessage());
        }
    }

    public function testRedeemRejectsWhenCourseUnavailable(): void
    {
        $courseId = $this->insertCourse('published', 'paid');
        $code = $this->service->generateBatch($courseId, 1, null, $this->staffId)['codes'][0];
        Db::name('courses')->where('id', $courseId)->update(['status' => 'unpublished']);
        $learner = $this->insertLearner();

        try {
            $this->service->redeem($learner, $code);
            self::fail('unpublished course must block redemption');
        } catch (BusinessException $e) {
            self::assertSame('ACTIVATION_CODE_COURSE_UNAVAILABLE', $e->getMessage());
        }
        // 码保持未使用, 课程重新发布后可兑换。
        self::assertSame(
            'unused',
            (string) Db::name('activation_codes')->where('code_hash', hash('sha256', str_replace('-', '', $code)))->value('status'),
        );
        Db::name('courses')->where('id', $courseId)->update(['status' => 'published']);
        $result = $this->service->redeem($learner, $code);
        self::assertTrue($result['granted']);
    }

    public function testRedeemWithAlreadyActiveEntitlementKeepsCodeUnused(): void
    {
        $courseId = $this->insertCourse('published', 'paid');
        $code = $this->service->generateBatch($courseId, 1, null, $this->staffId)['codes'][0];
        $learner = $this->insertLearner();
        // 学员已通过其他来源 (免费加入) 持有 active 授权。
        Db::name('course_entitlements')->insert([
            'learner_id' => $learner,
            'course_id' => $courseId,
            'source' => 'free',
            'order_id' => null,
            'status' => 'active',
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        try {
            $this->service->redeem($learner, $code);
            self::fail('already-entitled learner must not consume a code');
        } catch (BusinessException $e) {
            self::assertSame('ENTITLEMENT_ALREADY_ACTIVE', $e->getMessage());
            self::assertSame($courseId, (int) ($e->details['course_id'] ?? 0));
            self::assertNotSame('', (string) ($e->details['course_title'] ?? ''));
        }
        self::assertSame(
            'unused',
            (string) Db::name('activation_codes')->where('code_hash', hash('sha256', str_replace('-', '', $code)))->value('status'),
        );
        self::assertSame(
            1,
            (int) Db::name('course_entitlements')->where('learner_id', $learner)->where('course_id', $courseId)->count(),
        );
    }

    public function testActivationCodeGrantNeverReusesAnExistingEntitlement(): void
    {
        $courseId = $this->insertCourse('published', 'paid');
        $codes = $this->service->generateBatch($courseId, 2, null, $this->staffId)['codes'];
        $learnerId = $this->insertLearner();
        $this->service->redeem($learnerId, $codes[0]);

        try {
            (new EntitlementService())->grant(
                $learnerId,
                $courseId,
                'activation_code',
                null,
                $this->codeIdByPlain($codes[1]),
            );
            self::fail('Activation-code grants must reject an existing active entitlement.');
        } catch (BusinessException $exception) {
            self::assertSame('ENTITLEMENT_ALREADY_ACTIVE', $exception->getMessage());
        }
    }

    public function testConcurrentDifferentCodesKeepTheLosingCodeUnused(): void
    {
        $courseId = $this->insertCourse('published', 'paid');
        $course = Db::name('courses')->where('id', $courseId)->find();
        self::assertIsArray($course);
        $batch = $this->service->generateBatch($courseId, 2, null, $this->staffId);
        $learnerId = $this->insertLearner();

        Db::commit();
        try {
            $results = $this->redeemConcurrently($learnerId, $courseId, $batch['codes']);
            sort($results);

            self::assertSame(['ENTITLEMENT_ALREADY_ACTIVE', 'OK'], $results);
            self::assertSame(
                1,
                (int) Db::name('course_entitlements')
                    ->where('learner_id', $learnerId)
                    ->where('course_id', $courseId)
                    ->where('status', 'active')
                    ->count(),
            );
            $statusCounts = Db::name('activation_codes')
                ->where('batch_id', (int) $batch['id'])
                ->group('status')
                ->column('COUNT(*)', 'status');
            ksort($statusCounts);
            self::assertSame(['redeemed' => 1, 'unused' => 1], $statusCounts);
        } finally {
            try {
                $this->cleanupCommittedConcurrencyFixture(
                    $learnerId,
                    $courseId,
                    (int) $batch['id'],
                    (int) $course['department_id'],
                    (int) $course['category_id'],
                );
            } finally {
                Db::startTrans();
            }
        }
    }

    // ── helpers ───────────────────────────────────────────────────────────

    private function codeIdByPlain(string $plain): int
    {
        $id = Db::name('activation_codes')
            ->where('code_hash', hash('sha256', str_replace('-', '', $plain)))
            ->value('id');
        self::assertNotNull($id);
        return (int) $id;
    }

    /** @param list<string> $codes @return list<string> */
    private function redeemConcurrently(int $learnerId, int $courseId, array $codes): array
    {
        Db::connect()->close();
        $children = [];
        foreach ($codes as $code) {
            $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
            self::assertIsArray($sockets);
            $pid = pcntl_fork();
            self::assertNotSame(-1, $pid);
            if ($pid === 0) {
                fclose($sockets[0]);
                Db::connect('mysql', true)->execute(
                    'SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED',
                );
                fwrite($sockets[1], 'R');
                fread($sockets[1], 1);
                try {
                    (new ActivationCodeService())->redeem($learnerId, $code);
                    $result = 'OK';
                } catch (BusinessException $exception) {
                    $result = $exception->getMessage();
                } catch (\Throwable $exception) {
                    $result = $exception::class . ': ' . $exception->getMessage();
                }
                fwrite($sockets[1], $result);
                fclose($sockets[1]);
                exit(0);
            }
            fclose($sockets[1]);
            $children[] = [$pid, $sockets[0]];
        }

        foreach ($children as [, $socket]) {
            self::assertSame('R', fread($socket, 1));
        }

        Db::connect('mysql', true);
        Db::startTrans();
        $existing = Db::name('course_entitlements')
            ->where('learner_id', $learnerId)
            ->where('course_id', $courseId)
            ->where('status', 'active')
            ->lock(true)
            ->find();
        self::assertNull($existing);
        foreach ($children as [, $socket]) {
            fwrite($socket, 'G');
        }
        usleep(1_000_000);
        Db::commit();

        $results = [];
        foreach ($children as [$pid, $socket]) {
            $results[] = stream_get_contents($socket);
            fclose($socket);
            pcntl_waitpid($pid, $status);
            self::assertTrue(pcntl_wifexited($status));
            self::assertSame(0, pcntl_wexitstatus($status));
        }
        return $results;
    }

    private function cleanupCommittedConcurrencyFixture(
        int $learnerId,
        int $courseId,
        int $batchId,
        int $departmentId,
        int $categoryId,
    ): void {
        $codeIds = Db::name('activation_codes')->where('batch_id', $batchId)->column('id');
        Db::name('audit_log')->where('actor_id', $learnerId)->whereIn('target_id', $codeIds)->delete();
        Db::name('audit_log')->where('actor_id', $this->staffId)->where('target_id', $batchId)->delete();
        Db::name('course_entitlements')->where('learner_id', $learnerId)->where('course_id', $courseId)->delete();
        Db::name('course_enrollments')->where('learner_id', $learnerId)->where('course_id', $courseId)->delete();
        Db::name('activation_codes')->where('batch_id', $batchId)->delete();
        Db::name('activation_code_batches')->where('id', $batchId)->delete();
        Db::name('courses')->where('id', $courseId)->delete();
        Db::name('learners')->where('account_id', $learnerId)->delete();
        Db::name('staff_users')->where('account_id', $this->staffId)->delete();
        Db::name('accounts')->whereIn('id', [$learnerId, $this->staffId])->delete();
        Db::name('categories')->where('id', $categoryId)->delete();
        Db::name('departments')->where('id', $departmentId)->delete();
    }

    private function insertStaff(): int
    {
        $now = date('Y-m-d H:i:s');
        $id = (int) Db::name('accounts')->insertGetId([
            'kind' => 'staff',
            'login' => 'code-admin-' . bin2hex(random_bytes(4)),
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
            'display_name' => 'Code Admin',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        return $id;
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
            'nickname' => '码友' . random_int(1000, 9999),
            'avatar_url' => null,
            'show_on_course' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        return $id;
    }

    private function insertCourse(string $status, string $priceMode): int
    {
        $now = date('Y-m-d H:i:s');
        $deptId = (int) Db::name('departments')->insertGetId([
            'parent_id' => null,
            'name' => 'code-dept-' . bin2hex(random_bytes(3)),
            'path' => '/',
            'depth' => 1,
            'sort' => 0,
            'status' => 'enabled',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $categoryId = (int) Db::name('categories')->insertGetId([
            'name' => 'code-cat-' . bin2hex(random_bytes(3)),
            'parent_id' => 0,
            'path' => '/',
            'depth' => 1,
            'sort' => 0,
            'status' => 'enabled',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        return (int) Db::name('courses')->insertGetId([
            'department_id' => $deptId,
            'category_id' => $categoryId,
            'title' => '激活码测试课 ' . bin2hex(random_bytes(3)),
            'teacher_name' => '教师乙',
            'summary' => '激活码兑换验证摘要',
            'intro_rich_text' => '<p>课程简介</p>',
            'status' => $status,
            'price_mode' => $priceMode,
            'list_price' => $priceMode === 'paid' ? 199 : 0,
            'sale_price' => 0,
            'created_by_staff_id' => $this->staffId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
}
