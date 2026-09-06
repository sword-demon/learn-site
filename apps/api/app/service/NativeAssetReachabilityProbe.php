<?php

declare(strict_types=1);

namespace App\service;

use App\support\storage\LocalAssetStorage;

final class NativeAssetReachabilityProbe implements AssetReachabilityProbe
{
    /** @return array{reachable: bool, skipped: bool} */
    public function probe(string $storagePath): array
    {
        // Assets are protected local files, not public HTTP URLs. Use the same
        // traversal-safe resolver as the learner download endpoint.
        $resolved = (new LocalAssetStorage())->resolve($storagePath);
        return ['reachable' => $resolved !== null && is_file($resolved['path']) && is_readable($resolved['path']), 'skipped' => false];
    }
}
