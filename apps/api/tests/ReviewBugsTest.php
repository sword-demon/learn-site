<?php
declare(strict_types=1);

namespace Tests;

use App\middleware\Authorize;
use App\service\PermissionService;
use App\service\TokenService;
use PHPUnit\Framework\TestCase;

final class ReviewBugsTest extends TestCase
{
    private InMemoryRedis $redis;
    private TokenService $tokens;

    protected function setUp(): void
    {
        $this->redis = new InMemoryRedis();
        RedisStub::install($this->redis);
        $this->tokens = new TokenService();
    }

    public function testRefreshReuseRevokesFamilyAndAccess(): void
    {
        $pair = $this->tokens->issue('42', TokenService::KIND_LEARNER);
        $this->assertNotNull($pair);
        $rotated = $this->tokens->rotate($pair['refresh_token'], TokenService::KIND_LEARNER);
        $this->assertNotNull($rotated);
        $replay = $this->tokens->rotate($pair['refresh_token'], TokenService::KIND_LEARNER);
        $this->assertNull($replay);
        $this->assertTrue((bool) $this->redis->exists('family:' . $pair['family_id'] . ':revoked'));
        $this->assertNull($this->tokens->verifyAccess($pair['access_token']));
        $this->assertNull($this->tokens->verifyAccess($rotated['access_token']));
    }

    public function testLearnerRefreshRejectedOnStaffEndpointKind(): void
    {
        $pair = $this->tokens->issue('7', TokenService::KIND_LEARNER);
        $this->assertNotNull($pair);
        $this->assertNull($this->tokens->rotate($pair['refresh_token'], TokenService::KIND_STAFF));
        $this->assertNotNull($this->tokens->verifyAccess($pair['access_token']));
    }

    public function testLearnerViewDoesNotImplyResetOrKick(): void
    {
        $this->assertSame('learner.view', Authorize::permissionFor('/api/admin/v1/learners', 'GET'));
        $this->assertSame(
            'learner.reset_password',
            Authorize::permissionFor('/api/admin/v1/learners/9/password', 'POST'),
        );
        $this->assertSame(
            'learner.kick',
            Authorize::permissionFor('/api/admin/v1/learners/9/kick', 'POST'),
        );
        $this->assertSame(
            'org.grant',
            Authorize::permissionFor('/api/admin/v1/staff/3/overrides', 'POST'),
        );
        $this->assertSame('org.staff', Authorize::permissionFor('/api/admin/v1/staff', 'GET'));
    }

    public function testScopeUnionKeepsSpecifiedDeptsAndSelf(): void
    {
        $merged = PermissionService::unionScopeRows([
            ['data_scope' => 'specified_depts', 'department_id' => 4, 'role_status' => 'enabled'],
            ['data_scope' => 'self', 'department_id' => null, 'role_status' => 'enabled'],
            ['data_scope' => 'all', 'department_id' => null, 'role_status' => 'disabled'],
        ]);
        $this->assertFalse($merged['all']);
        $this->assertTrue($merged['include_self']);
        $this->assertSame([4], $merged['department_ids']);
    }

    public function testMustChangePasswordOnlyAllowsFirstPasswordPath(): void
    {
        $this->assertTrue(
            \App\middleware\AdminAuth::allowsWhileMustChangePassword('/api/admin/v1/auth/password/first', 'POST'),
        );
        $this->assertFalse(
            \App\middleware\AdminAuth::allowsWhileMustChangePassword('/api/admin/v1/dashboard', 'GET'),
        );
    }
}
