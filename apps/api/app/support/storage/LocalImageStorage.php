<?php

declare(strict_types=1);

namespace App\support\storage;

use RuntimeException;
use Webman\Http\UploadFile;

final class LocalImageStorage implements ImageStorage
{
    private const PREFIXES = ['covers', 'banners'];

    private readonly string $root;
    private readonly string $prefix;

    public function __construct(string $root = '', string $prefix = 'covers')
    {
        if (!in_array($prefix, self::PREFIXES, true)) {
            throw new \InvalidArgumentException('Unsupported image storage prefix');
        }
        $this->root = $root;
        $this->prefix = $prefix;
    }

    /** @return array{key: string, url: string, mime_type: string, size_bytes: int} */
    public function store(UploadFile $file, string $mime, string $extension): array
    {
        $extension = strtolower($extension);
        if (!in_array($extension, ['jpg', 'jpeg', 'png', 'webp'], true)) {
            throw new RuntimeException(strtoupper($this->prefix) . '_EXTENSION_INVALID');
        }

        $root = $this->rootPath();
        $year = date('Y');
        $month = date('m');
        $directory = $root . DIRECTORY_SEPARATOR . $year . DIRECTORY_SEPARATOR . $month . DIRECTORY_SEPARATOR;
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException(strtoupper($this->prefix) . '_STORE_FAILED');
        }

        $name = bin2hex(random_bytes(16)) . '.' . $extension;
        $file->move($directory . $name);
        $path = $directory . $name;
        if (!is_file($path)) {
            throw new RuntimeException(strtoupper($this->prefix) . '_STORE_FAILED');
        }

        $key = sprintf('%s/%s/%s/%s', $this->prefix, $year, $month, $name);
        return [
            'key' => $key,
            'url' => '/api/media/' . $key,
            'mime_type' => $mime,
            'size_bytes' => (int) filesize($path),
        ];
    }

    /** @return array{path: string, mime_type: string}|null */
    public function resolve(string $key): ?array
    {
        $pattern = '#^(' . preg_quote($this->prefix, '#') . ')/(\d{4})/(\d{2})/([a-f0-9]{32})\.(jpg|jpeg|png|webp)$#D';
        if (!preg_match($pattern, $key, $matches)) {
            return null;
        }

        $path = $this->rootPath()
            . DIRECTORY_SEPARATOR . $matches[2]
            . DIRECTORY_SEPARATOR . $matches[3]
            . DIRECTORY_SEPARATOR . $matches[4] . '.' . $matches[5];
        $real = realpath($path);
        $root = realpath($this->rootPath());
        if ($real === false || $root === false || !str_starts_with($real, $root . DIRECTORY_SEPARATOR)) {
            return null;
        }

        return [
            'path' => $real,
            'mime_type' => match ($matches[5]) {
                'jpg', 'jpeg' => 'image/jpeg',
                'png' => 'image/png',
                default => 'image/webp',
            },
        ];
    }

    private function rootPath(): string
    {
        return $this->root !== ''
            ? rtrim($this->root, DIRECTORY_SEPARATOR)
            : runtime_path('uploads/' . $this->prefix);
    }
}
