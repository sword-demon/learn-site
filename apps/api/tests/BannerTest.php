<?php

declare(strict_types=1);

namespace Tests;

use App\controller\admin\BannerController;
use App\controller\admin\BannerImageController;
use App\middleware\Authorize;
use App\service\BannerService;
use App\service\BusinessException;
use App\service\HomeService;
use App\service\EntitlementService;
use App\service\PublicCatalogService;
use App\controller\learner\HomeController;
use PHPUnit\Framework\TestCase;
use support\App;
use support\Request;
use support\think\Db;
use Webman\ThinkOrm\ThinkOrm;
use Webman\Http\UploadFile;

final class BannerTest extends TestCase
{
    private BannerService $service;
    private int $staffId;
    private string $root;

    public static function setUpBeforeClass(): void
    {
        App::loadAllConfig(['route', 'container']);
        ThinkOrm::start(null);
    }

    protected function setUp(): void
    {
        Db::startTrans();
        $now = date('Y-m-d H:i:s');
        $this->staffId = (int) Db::name('accounts')->insertGetId([
            'kind' => 'staff',
            'login' => 'banner-admin-' . bin2hex(random_bytes(5)),
            'password_hash' => 'not-used-by-test',
            'must_change_password' => 0,
            'status' => 'active',
            'last_login_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        Db::name('staff_users')->insert([
            'account_id' => $this->staffId,
            'is_super_admin' => 1,
            'department_id' => null,
            'display_name' => 'Banner Admin',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $this->service = new BannerService();
        $this->root = sys_get_temp_dir() . '/learn-site-banner-' . bin2hex(random_bytes(5));
        mkdir($this->root, 0775, true);
    }

    protected function tearDown(): void
    {
        Db::rollback();
        $this->removeTree($this->root);
    }

    public function testCreateStoresDefaultEnabledBannerAndAudit(): void
    {
        $created = $this->service->create($this->input(20), $this->staffId);

        self::assertSame(20, $created['sort_order']);
        self::assertTrue($created['is_enabled']);
        self::assertNull($created['link_url']);
        self::assertSame(
            1,
            Db::name('audit_log')->where('action', 'banner.create')->where('target_id', $created['id'])->count(),
        );
    }

    public function testInvalidLinkIsRejectedBeforeInsert(): void
    {
        try {
            $this->service->create($this->input(0, 'javascript:alert(1)'), $this->staffId);
            self::fail('Expected invalid banner link to be rejected');
        } catch (BusinessException $exception) {
            self::assertSame('VALIDATION_FAILED', $exception->apiCode);
            self::assertSame('BANNER_LINK_INVALID', $exception->getMessage());
        }

        self::assertSame(0, Db::name('banners')->count());
    }

    public function testPublicListExcludesDisabledAndSoftDeletedBannersInSortOrder(): void
    {
        $first = $this->service->create($this->input(20), $this->staffId);
        $disabled = $this->service->create($this->input(10), $this->staffId);
        $deleted = $this->service->create($this->input(0), $this->staffId);
        $this->service->update($disabled['id'], ['is_enabled' => false], $this->staffId);
        $this->service->softDelete($deleted['id'], $this->staffId);

        $public = $this->service->listPublic();

        self::assertSame([$first['id']], array_column($public, 'id'));
        self::assertArrayNotHasKey('image_key', $public[0]);
        self::assertArrayNotHasKey('is_enabled', $public[0]);
        self::assertArrayNotHasKey('deleted_at', $public[0]);
    }

    public function testUpdateWritesChangedFieldsAndAudit(): void
    {
        $created = $this->service->create($this->input(), $this->staffId);

        $updated = $this->service->update($created['id'], [
            'link_url' => 'https://example.com/promo',
            'sort_order' => 3,
            'is_enabled' => false,
        ], $this->staffId);

        self::assertSame('https://example.com/promo', $updated['link_url']);
        self::assertSame(3, $updated['sort_order']);
        self::assertFalse($updated['is_enabled']);
        self::assertSame(
            1,
            Db::name('audit_log')->where('action', 'banner.update')->where('target_id', $created['id'])->count(),
        );
    }

    public function testSoftDeleteIsIdempotentAndKeepsTheDatabaseRow(): void
    {
        $created = $this->service->create($this->input(), $this->staffId);

        $this->service->softDelete($created['id'], $this->staffId);
        $this->service->softDelete($created['id'], $this->staffId);

        self::assertSame(1, Db::name('banners')->where('id', $created['id'])->count());
        self::assertNotNull(Db::name('banners')->where('id', $created['id'])->value('deleted_at'));
        self::assertSame(
            1,
            Db::name('audit_log')->where('action', 'banner.delete')->where('target_id', $created['id'])->count(),
        );
    }

    public function testBannerUploadUsesBannerErrorCodes(): void
    {
        $path = $this->root . '/banner.png';
        $fixture = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8A'
            . 'AQUBAScY42YAAAAASUVORK5CYII=',
            true,
        );
        file_put_contents($path, $fixture);
        $storage = new FakeImageStorage([
            'key' => 'banners/2026/08/aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa.png',
            'url' => '/api/media/banners/2026/08/aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa.png',
            'mime_type' => 'image/png',
            'size_bytes' => strlen((string) $fixture),
        ]);

        $response = (new BannerImageController($storage))->upload(new CoverRequest(
            new UploadFile($path, 'banner.png', 'image/png', UPLOAD_ERR_OK),
        ));
        $body = json_decode((string) $response->rawBody(), true);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('banners/2026/08/aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa.png', $body['data']['key']);
        self::assertSame(['image/png', 'png'], $storage->storedArguments);
    }

    public function testBannerUploadRejectsOversizedAndInvalidMimeFiles(): void
    {
        $oversizedPath = $this->root . '/large.png';
        file_put_contents($oversizedPath, str_repeat('x', 5 * 1024 * 1024 + 1));
        $storage = new FakeImageStorage();
        $largeResponse = (new BannerImageController($storage))->upload(new CoverRequest(
            new UploadFile($oversizedPath, 'large.png', 'image/png', UPLOAD_ERR_OK),
        ));
        $largeBody = json_decode((string) $largeResponse->rawBody(), true);
        self::assertSame(400, $largeResponse->getStatusCode());
        self::assertSame('BANNER_SIZE_INVALID', $largeBody['error']['message']);
        self::assertNull($storage->storedArguments);

        $invalidPath = $this->root . '/invalid.txt';
        file_put_contents($invalidPath, 'plain text');
        $invalidResponse = (new BannerImageController($storage))->upload(new CoverRequest(
            new UploadFile($invalidPath, 'invalid.txt', 'text/plain', UPLOAD_ERR_OK),
        ));
        $invalidBody = json_decode((string) $invalidResponse->rawBody(), true);
        self::assertSame(400, $invalidResponse->getStatusCode());
        self::assertSame('BANNER_MIME_INVALID', $invalidBody['error']['message']);
        self::assertNull($storage->storedArguments);
    }

    public function testAdminListCanFilterEnabledStateAndExcludesDeletedRows(): void
    {
        $enabled = $this->service->create($this->input(20), $this->staffId);
        $disabled = $this->service->create($this->input(10), $this->staffId);
        $deleted = $this->service->create($this->input(30), $this->staffId);
        $this->service->update($disabled['id'], ['is_enabled' => false], $this->staffId);
        $this->service->softDelete($deleted['id'], $this->staffId);

        $onlyEnabled = $this->service->listForAdmin(['is_enabled' => true]);
        $onlyDisabled = $this->service->listForAdmin(['is_enabled' => false]);

        self::assertSame([$enabled['id']], array_column($onlyEnabled['items'], 'id'));
        self::assertSame([$disabled['id']], array_column($onlyDisabled['items'], 'id'));
        self::assertSame(2, $onlyEnabled['total'] + $onlyDisabled['total']);
    }

    public function testPermissionAndHomePublicShapeAreSeparated(): void
    {
        self::assertSame('banner.manage', Authorize::permissionFor('/api/admin/v1/banners', 'GET'));
        self::assertSame('banner.manage', Authorize::permissionFor('/api/admin/v1/banners/1', 'PATCH'));
        self::assertSame('banner.manage', Authorize::permissionFor('/api/admin/v1/banner-images', 'POST'));
        self::assertNull(Authorize::permissionFor('/api/learner/v1/home', 'GET'));

        $created = $this->service->create($this->input(), $this->staffId);
        $home = (new HomeService())->banners();

        self::assertSame($created['id'], $home[0]['id']);
        self::assertArrayNotHasKey('image_key', $home[0]);
        self::assertArrayNotHasKey('is_enabled', $home[0]);
    }

    public function testAdminControllerExposesCreatePatchAndDeleteContracts(): void
    {
        $controller = new BannerController(new BannerService());
        $create = $this->jsonRequest('POST', '/api/admin/v1/banners', $this->input(4, '/'));
        $create->account_id = $this->staffId;
        $createdResponse = $controller->store($create);
        $createdPayload = json_decode((string) $createdResponse->rawBody(), true);

        self::assertSame(201, $createdResponse->getStatusCode());
        $id = (int) ($createdPayload['data']['id'] ?? 0);
        self::assertGreaterThan(0, $id);

        $patch = $this->jsonRequest('PATCH', '/api/admin/v1/banners/' . $id, ['is_enabled' => false]);
        $patch->account_id = $this->staffId;
        $patchedResponse = $controller->patch($patch, (string) $id);
        $patchedPayload = json_decode((string) $patchedResponse->rawBody(), true);
        self::assertSame(200, $patchedResponse->getStatusCode());
        self::assertFalse($patchedPayload['data']['is_enabled']);

        $delete = new Request("DELETE /api/admin/v1/banners/{$id} HTTP/1.1\r\nHost: test\r\n\r\n");
        $delete->account_id = $this->staffId;
        self::assertSame(204, $controller->destroy($delete, (string) $id)->getStatusCode());
    }

    public function testHomeControllerReturnsOnlyPublicBannerFields(): void
    {
        $created = $this->service->create($this->input(2, '/courses/1'), $this->staffId);
        $request = new Request("GET /api/learner/v1/home HTTP/1.1\r\nHost: test\r\n\r\n");
        $response = (new HomeController(
            new HomeService(),
            new PublicCatalogService(new EntitlementService()),
        ))->home($request);
        $payload = json_decode((string) $response->rawBody(), true);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame($created['id'], $payload['data']['banners'][0]['id']);
        self::assertArrayNotHasKey('image_key', $payload['data']['banners'][0]);
        self::assertArrayNotHasKey('is_enabled', $payload['data']['banners'][0]);
        self::assertArrayNotHasKey('deleted_at', $payload['data']['banners'][0]);
    }

    /** @return array<string, mixed> */
    private function input(int $sort = 0, ?string $link = null): array
    {
        return [
            'image_url' => '/api/media/banners/2026/08/aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa.webp',
            'image_key' => 'banners/2026/08/aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa.webp',
            'link_url' => $link,
            'sort_order' => $sort,
        ];
    }

    /** @param array<string,mixed> $body */
    private function jsonRequest(string $method, string $path, array $body): Request
    {
        $json = json_encode($body, JSON_THROW_ON_ERROR);
        return new Request(
            "$method $path HTTP/1.1\r\nHost: test\r\nContent-Type: application/json\r\nContent-Length: "
            . strlen($json) . "\r\n\r\n$json",
        );
    }

    private function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $child = $path . '/' . $entry;
            if (is_dir($child)) {
                $this->removeTree($child);
            } else {
                unlink($child);
            }
        }
        rmdir($path);
    }
}
