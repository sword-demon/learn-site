<?php

declare(strict_types=1);

namespace App\service;

interface AssetReachabilityProbe
{
    /** @return array{reachable: bool, skipped: bool} */
    public function probe(string $storagePath): array;
}
