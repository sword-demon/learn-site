<?php

declare(strict_types=1);

namespace Tests;

use App\controller\admin\CourseCoverController;
use PHPUnit\Framework\TestCase;
use Webman\Http\UploadFile;

final class CourseCoverControllerTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/learn-site-cover-controller-' . bin2hex(random_bytes(5));
        mkdir($this->root, 0775, true);
    }

    protected function tearDown(): void
    {
        foreach (scandir($this->root) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                @unlink($this->root . '/' . $entry);
            }
        }
        @rmdir($this->root);
    }

    public function testValidPngIsStoredAndReturnedInEnvelope(): void
    {
        $file = $this->file('cover.png', 'image/png', 32);
        $storage = new FakeImageStorage([
            'key' => 'covers/2026/08/aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa.png',
            'url' => '/api/media/covers/2026/08/aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa.png',
            'mime_type' => 'image/png',
            'size_bytes' => 32,
        ]);
        $response = (new CourseCoverController($storage))->upload(new CoverRequest($file));
        $body = json_decode((string) $response->rawBody(), true);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame($storage->result, $body['data']);
        self::assertSame(['image/png', 'png'], $storage->storedArguments);
    }

    public function testMissingFileIsRejectedWithoutCallingStorage(): void
    {
        $storage = new FakeImageStorage();
        $response = (new CourseCoverController($storage))->upload(new CoverRequest(null));
        $body = json_decode((string) $response->rawBody(), true);

        self::assertSame(400, $response->getStatusCode());
        self::assertSame('COVER_FILE_REQUIRED', $body['error']['message']);
        self::assertNull($storage->storedArguments);
    }

    public function testMimeAndExtensionMustMatch(): void
    {
        $storage = new FakeImageStorage();
        $response = (new CourseCoverController($storage))->upload(new CoverRequest(
            $this->file('cover.jpg', 'image/png', 32),
        ));
        $body = json_decode((string) $response->rawBody(), true);

        self::assertSame(400, $response->getStatusCode());
        self::assertSame('COVER_EXTENSION_INVALID', $body['error']['message']);
        self::assertNull($storage->storedArguments);
    }

    public function testOversizedFileIsRejected(): void
    {
        $storage = new FakeImageStorage();
        $response = (new CourseCoverController($storage))->upload(new CoverRequest(
            $this->file('cover.webp', 'image/webp', 5 * 1024 * 1024 + 1),
        ));
        $body = json_decode((string) $response->rawBody(), true);

        self::assertSame(400, $response->getStatusCode());
        self::assertSame('COVER_SIZE_INVALID', $body['error']['message']);
        self::assertNull($storage->storedArguments);
    }

    public function testStorageFailureReturnsInternalError(): void
    {
        $storage = new FakeImageStorage(null, true);
        $response = (new CourseCoverController($storage))->upload(new CoverRequest(
            $this->file('cover.png', 'image/png', 32),
        ));
        $body = json_decode((string) $response->rawBody(), true);

        self::assertSame(500, $response->getStatusCode());
        self::assertSame('COVER_STORE_FAILED', $body['error']['message']);
    }

    private function file(string $name, string $mime, int $size): UploadFile
    {
        $path = $this->root . '/' . $name;
        $pngFixture = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8A'
            . 'AQUBAScY42YAAAAASUVORK5CYII=';
        $content = $mime === 'image/png'
            ? base64_decode($pngFixture, true)
            : str_repeat('x', $size);
        file_put_contents($path, $content);
        return new UploadFile($path, $name, $mime, UPLOAD_ERR_OK);
    }
}
