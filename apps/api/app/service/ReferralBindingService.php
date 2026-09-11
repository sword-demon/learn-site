<?php

declare(strict_types=1);

namespace App\service;

use support\think\Db;

/**
 * ReferralBindingService — promotes a (visitor_token, share_entry_id) pair
 * into a permanent referrer_learner_id row in `learners`. Called once on
 * new registration.
 *
 * Three guards make this safe to call eagerly:
 *   1. Self-binding is forbidden (learner_id == referrer_id throws).
 *   2. Once referrer_learner_id is set, the trigger in MySQL blocks any
 *      UPDATE — so a re-call on the same learner is a noop even if the
 *      request somehow makes it past the precondition.
 *   3. Revoked share entries are skipped, so a stolen visitor_token after
 *      revocation cannot mint an illegitimate chain.
 */
final class ReferralBindingService
{
    public function __construct(
        private readonly ShareEntryService $shares = new ShareEntryService(),
    ) {
    }

    /**
     * Bind the visitor that just registered. Safe to call without a token;
     * returns the referrer_learner_id (or null if nothing to bind) so the
     * caller can log it without re-querying.
     */
    public function bindFromVisitor(int $newLearnerId, ?string $visitorToken = null, ?int $shareEntryId = null): ?int
    {
        if ($visitorToken === null || $visitorToken === '') {
            return null;
        }
        $resolved = $shareEntryId !== null
            ? $this->shares->resolveByVisitor($visitorToken, $shareEntryId)
            : $this->shares->resolveLatestVisit($visitorToken);
        if ($resolved === null) {
            return null;
        }
        if ($this->shares->isVisitorOrEntryBound($visitorToken, $resolved['share_entry_id'])) {
            return null;
        }
        return $this->bind($newLearnerId, $resolved['referrer_learner_id']);
    }

    /**
     * Bind by explicit referrer id (admin tools, future invite codes, etc.).
     * Returns the referrer_learner_id actually persisted, or null when no
     * binding was made (already set, self, or referrer missing).
     */
    public function bind(int $newLearnerId, ?int $referrerLearnerId): ?int
    {
        if ($referrerLearnerId === null) {
            return null;
        }
        if ($referrerLearnerId === $newLearnerId) {
            // Defensive: a malicious / forwarded cookie that points to the
            // same learner can never be self-applied.
            return null;
        }

        return Db::transaction(function () use ($newLearnerId, $referrerLearnerId): ?int {
            $existing = Db::name('learners')
                ->where('account_id', $newLearnerId)
                ->lock(true)
                ->find();
            if (!$existing) {
                return null;
            }
            if (!empty($existing['referrer_learner_id'])) {
                // FR-001 / FR-002 — write-once. We never overwrite.
                return (int) $existing['referrer_learner_id'];
            }
            $referrerExists = Db::name('learners')
                ->where('account_id', $referrerLearnerId)
                ->find();
            if (!$referrerExists) {
                return null;
            }
            $affected = (int) Db::name('learners')
                ->where('account_id', $newLearnerId)
                ->whereNull('referrer_learner_id')
                ->update(['referrer_learner_id' => $referrerLearnerId]);
            if ($affected === 0) {
                return null;
            }
            return $referrerLearnerId;
        });
    }

    /**
     * Walk the referrer chain up to N levels, returning the list of
     * ancestor learner ids from nearest to farthest.
     *
     * @return array<int, int>
     */
    public function walkUp(int $learnerId, int $maxLevels = 3): array
    {
        $chain = [];
        $seen = [$learnerId => true];
        $current = $learnerId;
        for ($i = 0; $i < $maxLevels; $i++) {
            $row = Db::name('learners')
                ->where('account_id', $current)
                ->field('referrer_learner_id')
                ->find();
            $referrer = isset($row['referrer_learner_id']) ? (int) $row['referrer_learner_id'] : 0;
            if ($referrer <= 0 || isset($seen[$referrer])) {
                break; // cycle or missing
            }
            $chain[] = $referrer;
            $seen[$referrer] = true;
            $current = $referrer;
        }
        return $chain;
    }
}