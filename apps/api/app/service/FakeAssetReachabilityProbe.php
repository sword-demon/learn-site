<?php

declare(strict_types=1);

namespace App\service;

final class FakeAssetReachabilityProbe implements AssetReachabilityProbe
{
    public function __construct(private readonly bool $reachable = true)
    {
    }

    /** @return array{reachable: bool, skipped: bool} */
    public function probe(string $storagePath): array
    {
        return ['reachable' => $this->reachable, 'skipped' => false];
    }
}
