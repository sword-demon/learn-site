<?php

declare(strict_types=1);

namespace Tests;

use App\support\storage\ImageStorage;
use RuntimeException;
use Webman\Http\UploadFile;

final class FakeImageStorage implements ImageStorage
{
    /** @var array{key:string,url:string,mime_type:string,size_bytes:int}|null */
    public ?array $result;

    /** @var array{string,string}|null */
    public ?array $storedArguments = null;

    public function __construct(?array $result = null, private readonly bool $fail = false)
    {
        $this->result = $result;
    }

    public function store(UploadFile $file, string $mime, string $extension): array
    {
        $this->storedArguments = [$mime, $extension];
        if ($this->fail) {
            throw new RuntimeException('filesystem unavailable');
        }

        return $this->result ?? throw new RuntimeException('missing fixture result');
    }

    public function resolve(string $key): ?array
    {
        return null;
    }
}
