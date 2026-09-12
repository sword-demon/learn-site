<?php

declare(strict_types=1);

namespace App\service;

/**
 * Visit recording lives on ShareEntryService; this wrapper is the
 * US1 T022 surface so callers do not reach into share-entry minting.
 */
final class ShareVisitService
{
    /** @var ShareEntryService */
    private readonly ShareEntryService $shareEntryService;

    public function __construct(ShareEntryService $shareEntryService)
    {
        $this->shareEntryService = $shareEntryService;
    }

    /** @return array{visitor_token: string, share_entry_id: int, learner_id: int|null, scope: string, course_id: int|null} */
    public function recordVisit(string $shortCode, ?string $visitorToken, ?string $ip, ?string $userAgent): array
    {
        return $this->shareEntryService->recordVisit($shortCode, $visitorToken, $ip, $userAgent);
    }

    public function bindVisitorToLearner(int $learnerId, string $visitorToken): int
    {
        return $this->shareEntryService->bindVisitorToLearner($learnerId, $visitorToken);
    }
}
