<?php

declare(strict_types=1);

namespace Tests;

use App\support\storage\ImageStorage;
use App\support\storage\LocalImageStorage;
use PHPUnit\Framework\TestCase;
use Webman\Http\UploadFile;

final class ImageStorageTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/learn-site-cover-' . bin2hex(random_bytes(5));
        mkdir($this->root, 0775, true);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->root);
    }

    public function testLocalStorageStoresImageAndResolvesGeneratedKey(): void
    {
        $source = $this->root . '/source.png';
        file_put_contents($source, "\x89PNG\r\n\x1a\nfixture");
        $storage = new LocalImageStorage($this->root . '/stored');
        $file = new UploadFile($source, 'cover.png', 'image/png', UPLOAD_ERR_OK);

        $result = $storage->store($file, 'image/png', 'png');

        self::assertInstanceOf(ImageStorage::class, $storage);
        self::assertMatchesRegularExpression('#^covers/\d{4}/\d{2}/[a-f0-9]{32}\.png$#', $result['key']);
        self::assertSame('/api/media/' . $result['key'], $result['url']);
        self::assertSame('image/png', $result['mime_type']);
        self::assertSame(15, $result['size_bytes']);
        self::assertFileExists($storage->resolve($result['key'])['path']);
        self::assertSame('image/png', $storage->resolve($result['key'])['mime_type']);
    }

    public function testResolveRejectsTraversalAndMissingKeys(): void
    {
        $storage = new LocalImageStorage($this->root . '/stored');

        self::assertNull($storage->resolve('../runtime/logs/secret.png'));
        self::assertNull($storage->resolve('covers/2026/08/missing.png'));
    }

    public function testBannerPrefixStoresAndResolvesInItsOwnKeyspace(): void
    {
        $source = $this->root . '/source-banner.png';
        file_put_contents($source, "\x89PNG\r\n\x1a\nfixture");
        $storage = new LocalImageStorage($this->root . '/stored', 'banners');
        $file = new UploadFile($source, 'banner.png', 'image/png', UPLOAD_ERR_OK);

        $result = $storage->store($file, 'image/png', 'png');

        self::assertMatchesRegularExpression('#^banners/\d{4}/\d{2}/[a-f0-9]{32}\.png$#', $result['key']);
        self::assertSame('/api/media/' . $result['key'], $result['url']);
        self::assertFileExists($storage->resolve($result['key'])['path']);
        self::assertSame('image/png', $storage->resolve($result['key'])['mime_type']);
    }

    public function testStorageOnlyResolvesItsConfiguredPrefix(): void
    {
        $storage = new LocalImageStorage($this->root . '/stored');

        self::assertNull($storage->resolve(
            'banners/2026/08/aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa.png',
        ));
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
