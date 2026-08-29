<?php

declare(strict_types=1);

namespace Tests;

use App\support\storage\AssetStorage;
use App\support\storage\LocalAssetStorage;
use PHPUnit\Framework\TestCase;
use Webman\Http\UploadFile;

final class AssetStorageTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/learn-site-assets-' . bin2hex(random_bytes(5));
        mkdir($this->root, 0775, true);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->root);
    }

    public function testStoresAndResolvesPdfAssetWithoutPathTraversal(): void
    {
        $source = $this->root . '/source.pdf';
        file_put_contents($source, '%PDF-asset-fixture');
        $storage = new LocalAssetStorage($this->root . '/stored');
        $file = new UploadFile($source, 'lesson.pdf', 'application/pdf', UPLOAD_ERR_OK);

        $result = $storage->store($file, 'pdf', 'application/pdf', 'pdf');

        self::assertInstanceOf(AssetStorage::class, $storage);
        self::assertMatchesRegularExpression('#^uploads/\d{4}/\d{2}/[a-f0-9]{32}\.pdf$#', $result['storage_path']);
        self::assertSame('application/pdf', $result['mime_type']);
        self::assertSame(18, $result['size_bytes']);
        self::assertFileExists($storage->resolve($result['storage_path'])['path']);
        self::assertNull($storage->resolve('../runtime/secret.pdf'));
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
