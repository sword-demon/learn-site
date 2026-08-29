<?php

declare(strict_types=1);

namespace App\support\storage;

use RuntimeException;
use Webman\Http\UploadFile;

final class LocalAssetStorage implements AssetStorage
{
    private const PATH_PATTERN = '#^uploads/(\d{4})/(\d{2})/([a-f0-9]{16,32})\.(pdf|mp4|mov)$#D';

    public function __construct(private readonly string $root = '')
    {
    }

    /** @return array{storage_path: string, mime_type: string, size_bytes: int} */
    public function store(UploadFile $file, string $kind, string $mime, string $extension): array
    {
        $extension = strtolower($extension);
        $allowed = $kind === 'pdf' ? ['pdf'] : ['mp4', 'mov'];
        if (!in_array($extension, $allowed, true)) {
            throw new RuntimeException('ASSET_EXTENSION_INVALID');
        }

        $year = date('Y');
        $month = date('m');
        $directory = $this->rootPath() . DIRECTORY_SEPARATOR . 'uploads'
            . DIRECTORY_SEPARATOR . $year . DIRECTORY_SEPARATOR . $month . DIRECTORY_SEPARATOR;
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException('ASSET_STORE_FAILED');
        }

        $name = bin2hex(random_bytes(16)) . '.' . $extension;
        $file->move($directory . $name);
        $path = $directory . $name;
        if (!is_file($path)) {
            throw new RuntimeException('ASSET_STORE_FAILED');
        }

        return [
            'storage_path' => sprintf('uploads/%s/%s/%s', $year, $month, $name),
            'mime_type' => $mime !== '' ? $mime : ($kind === 'pdf' ? 'application/pdf' : 'video/mp4'),
            'size_bytes' => (int) filesize($path),
        ];
    }

    /** @return array{path: string, mime_type: string}|null */
    public function resolve(string $storagePath): ?array
    {
        if (!preg_match(self::PATH_PATTERN, $storagePath, $matches)) {
            return null;
        }
        $path = $this->rootPath() . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $storagePath);
        $real = realpath($path);
        $root = realpath($this->rootPath());
        if ($real === false || $root === false || !str_starts_with($real, $root . DIRECTORY_SEPARATOR)) {
            return null;
        }

        return [
            'path' => $real,
            'mime_type' => $matches[4] === 'pdf' ? 'application/pdf' : 'video/' . ($matches[4] === 'mov' ? 'quicktime' : 'mp4'),
        ];
    }

    private function rootPath(): string
    {
        return $this->root !== '' ? rtrim($this->root, DIRECTORY_SEPARATOR) : runtime_path('');
    }
}
