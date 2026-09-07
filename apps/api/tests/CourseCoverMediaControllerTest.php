<?php

declare(strict_types=1);

namespace Tests;

use App\controller\media\CourseCoverMediaController;
use App\support\storage\LocalImageStorage;
use PHPUnit\Framework\TestCase;
use Webman\Http\UploadFile;

final class CourseCoverMediaControllerTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/learn-site-cover-media-' . bin2hex(random_bytes(5));
        mkdir($this->root, 0775, true);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->root);
    }

    public function testAvatarKeyIsServedFromAvatarStorage(): void
    {
        $source = $this->root . '/source.png';
        file_put_contents($source, "\x89PNG\r\n\x1a\nfixture");
        $avatars = new LocalImageStorage($this->root . '/avatars', 'avatars');
        $stored = $avatars->store(
            new UploadFile($source, 'avatar.png', 'image/png', UPLOAD_ERR_OK),
            'image/png',
            'png',
        );

        $controller = new CourseCoverMediaController(
            new LocalImageStorage($this->root . '/covers'),
            new LocalImageStorage($this->root . '/banners', 'banners'),
            $avatars,
        );
        $response = $controller->show($stored['key']);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('image/png', $response->getHeader('Content-Type'));
    }

    public function testUnknownKeyReturnsNotFound(): void
    {
        $controller = new CourseCoverMediaController(
            new LocalImageStorage($this->root . '/covers'),
            new LocalImageStorage($this->root . '/banners', 'banners'),
            new LocalImageStorage($this->root . '/avatars', 'avatars'),
        );
        $response = $controller->show('avatars/2026/09/aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa.png');
        $body = json_decode((string) $response->rawBody(), true);

        self::assertSame(404, $response->getStatusCode());
        self::assertSame('COVER_NOT_FOUND', $body['error']['message']);
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
