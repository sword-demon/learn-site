<?php

declare(strict_types=1);

namespace Tests;

use App\service\BusinessException;
use App\service\PermissionOverrideService;
use App\service\PermissionService;
use PHPUnit\Framework\TestCase;
use support\App;
use support\think\Db;
use Throwable;
use Webman\ThinkOrm\ThinkOrm;

final class PermissionOverrideServiceIntegrationTest extends TestCase
{
    private PermissionOverrideService $service;
    private int $actorId;
    private int $targetId;

    public static function setUpBeforeClass(): void
    {
        App::loadAllConfig(['route', 'container']);
        ThinkOrm::start(null);
    }

    protected function setUp(): void
    {
        /** @phpstan-ignore-next-line */
        Db::startTrans();
        try {
            $this->service = new PermissionOverrideService(new PermissionService());
            $this->seedFixture();
        } catch (Throwable $exception) {
            /** @phpstan-ignore-next-line */
            Db::rollback();
            throw $exception;
        }
    }

    protected function tearDown(): void
    {
        /** @phpstan-ignore-next-line */
        Db::rollback();
    }

    public function testReplacePersistsGrantAndDenyAndDenyWinsInEffectivePermissions(): void
    {
        $out = $this->service->replace($this->targetId, [
            ['code' => 'qa.answer', 'effect' => 'grant'],
            ['code' => 'order.view', 'effect' => 'deny'],
        ], $this->actorId);

        self::assertCount(2, $out);
        self::assertSame(['grant', 'deny'], array_column($out, 'effect'));
        self::assertSame(['qa.answer', 'order.view'], array_column($out, 'code'));
        self::assertContains((int) $out[0]['permission_id'], [
            (int) Db::name('permissions')->where('code', 'qa.answer')->value('id'),
        ]);
        self::assertContains((int) $out[1]['permission_id'], [
            (int) Db::name('permissions')->where('code', 'order.view')->value('id'),
        ]);

        $effective = (new PermissionService())->effectiveCodes($this->targetId);
        self::assertContains('qa.answer', $effective);
        self::assertNotContains('order.view', $effective);
    }

    public function testReplaceIsAtomicAndRemovesPreviousOverrides(): void
    {
        $this->service->replace($this->targetId, [
            ['code' => 'order.view', 'effect' => 'deny'],
        ], $this->actorId);

        $out = $this->service->replace($this->targetId, [
            ['code' => 'qa.answer', 'effect' => 'grant'],
        ], $this->actorId);

        self::assertCount(1, $out);
        self::assertSame('qa.answer', $out[0]['code']);
        self::assertSame('grant', $out[0]['effect']);
        self::assertSame(
            [['effect' => 'grant', 'code' => 'qa.answer', 'permission_id' => $out[0]['permission_id']]],
            $this->service->list($this->targetId),
        );
    }

    public function testSelfModificationIsRejected(): void
    {
        $this->assertBusinessException(
            fn (): array => $this->service->replace(
                $this->actorId,
                [['code' => 'qa.answer', 'effect' => 'grant']],
                $this->actorId,
            ),
            'FORBIDDEN',
            'SELF_GUARD',
        );
    }

    public function testActorCannotAssignPermissionTheyDoNotHold(): void
    {
        $this->assertBusinessException(
            fn (): array => $this->service->replace(
                $this->targetId,
                [['code' => 'review.moderate', 'effect' => 'grant']],
                $this->actorId,
            ),
            'FORBIDDEN',
            'OVERRIDE_NOT_HELD',
        );
    }

    public function testInvalidEffectAndUnknownPermissionAreRejected(): void
    {
        $this->assertBusinessException(
            fn (): array => $this->service->replace(
                $this->targetId,
                [['code' => 'qa.answer', 'effect' => 'replace']],
                $this->actorId,
            ),
            'VALIDATION_FAILED',
            'OVERRIDE_EFFECT_INVALID',
        );

        $this->assertBusinessException(
            fn (): array => $this->service->replace(
                $this->targetId,
                [['code' => 'permission.does_not_exist', 'effect' => 'grant']],
                $this->actorId,
            ),
            'VALIDATION_FAILED',
            'OVERRIDE_CODE_UNKNOWN',
        );
    }

    private function seedFixture(): void
    {
        $now = '2026-08-26 00:00:00';
        $suffix = bin2hex(random_bytes(4));
        $this->actorId = $this->insertAccount("t080-actor-$suffix");
        $this->targetId = $this->insertAccount("t080-target-$suffix");

        foreach ([$this->actorId, $this->targetId] as $accountId) {
            Db::name('staff_users')->insert([
                'account_id' => $accountId,
                'is_super_admin' => 0,
                'department_id' => null,
                'display_name' => "T080 $accountId",
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $permissionIds = [];
        foreach (['org.grant', 'qa.answer', 'order.view', 'review.moderate'] as $code) {
            $permissionIds[$code] = $this->permissionId($code);
        }

        $actorRoleId = (int) Db::name('roles')->insertGetId([
            'name' => "T080 actor $suffix",
            'code' => "t080-actor-$suffix",
            'data_scope' => 'self',
            'status' => 'enabled',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $targetRoleId = (int) Db::name('roles')->insertGetId([
            'name' => "T080 target $suffix",
            'code' => "t080-target-$suffix",
            'data_scope' => 'self',
            'status' => 'enabled',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        foreach (['org.grant', 'qa.answer', 'order.view'] as $code) {
            Db::name('role_permission')->insert([
                'role_id' => $actorRoleId,
                'permission_id' => $permissionIds[$code],
            ]);
        }
        Db::name('role_permission')->insert([
            'role_id' => $targetRoleId,
            'permission_id' => $permissionIds['order.view'],
        ]);
        Db::name('staff_role')->insert(['staff_user_id' => $this->actorId, 'role_id' => $actorRoleId]);
        Db::name('staff_role')->insert(['staff_user_id' => $this->targetId, 'role_id' => $targetRoleId]);
    }

    private function insertAccount(string $login): int
    {
        return (int) Db::name('accounts')->insertGetId([
            'kind' => 'staff',
            'login' => $login,
            'password_hash' => 'not-used',
            'must_change_password' => 0,
            'status' => 'active',
            'last_login_at' => null,
            'created_at' => '2026-08-26 00:00:00',
            'updated_at' => '2026-08-26 00:00:00',
        ]);
    }

    private function permissionId(string $code): int
    {
        $existing = Db::name('permissions')->where('code', $code)->value('id');
        if ($existing !== null) {
            return (int) $existing;
        }
        return (int) Db::name('permissions')->insertGetId([
            'code' => $code,
            'module' => 't080',
            'description' => "T080 $code",
        ]);
    }

    /** @param callable():mixed $operation */
    private function assertBusinessException(callable $operation, string $apiCode, string $message): void
    {
        try {
            $operation();
            self::fail("Expected {$apiCode}/{$message}");
        } catch (BusinessException $exception) {
            self::assertSame($apiCode, $exception->apiCode);
            self::assertSame($message, $exception->getMessage());
        }
    }
}
