<?php

declare(strict_types=1);

namespace App\service;

use App\model\ShareEntry;
use App\model\ShareVisit;
use App\support\ShareShortCode;
use support\think\Db;

use function nowDatetime;

/**
 * ShareEntryService — mints learner-owned share entries, logs anonymous
 * visits, and resolves a (short_code → referrer_learner_id) lookup that
 * downstream settlement uses.
 *
 * Three concerns kept together because splitting them just spreads the
 * short-code minting collision retry:
 *   1. create() — learner calls POST /share-entries; returns plaintext_code
 *      only once (later reads always see masked_code).
 *   2. recordVisit() — public landing page calls this on GET; binds the
 *      visitor_token cookie so post-registration we can find the referrer.
 *   3. resolveByShortCode() — short-code → referrer learner id (only when
 *      distribution was enabled at creation and entry has not been revoked).
 */
final class ShareEntryService
{
    public const VISITOR_COOKIE = 'distribution_visitor_token';
    public const COOKIE_TTL_SECONDS = 180 * 86400;

    public function __construct(
        private readonly DistributionConfigService $config = new DistributionConfigService(),
    ) {
    }

    /**
     * Mint a new share entry. Returns the full row plus the plaintext code
     * and share URL — the only place the plaintext code is exposed.
     *
     * @return array<string, mixed>
     */
    public function create(int $learnerId, string $scope, ?int $courseId, int $maxRetries = 5): array
    {
        if ($scope !== 'course' && $scope !== 'site') {
            throw new BusinessException('VALIDATION_FAILED', 'INVALID_SCOPE');
        }
        if ($scope === 'course' && ($courseId === null || $courseId <= 0)) {
            throw new BusinessException('VALIDATION_FAILED', 'COURSE_REQUIRED');
        }
        if ($scope === 'course') {
            $course = Db::name('courses')->where('id', $courseId)->field('id,status')->find();
            if (!is_array($course)) {
                throw new BusinessException('NOT_FOUND', 'COURSE_NOT_FOUND');
            }
            if ((string) $course['status'] !== 'published') {
                throw new BusinessException('VALIDATION_FAILED', 'COURSE_NOT_PUBLISHED');
            }
        }

        // ponytail: refuse to mint dead links when the global switch is off —
        // surfacing DISTRIBUTION_DISABLED lets the admin UI hint why the
        // share button is greyed out, instead of silently creating an entry
        // that never resolves a referrer.
        $config = $this->config->getConfig();
        if (empty($config['enabled'])) {
            throw new BusinessException('FORBIDDEN', 'DISTRIBUTION_DISABLED');
        }

        // Snapshot the distribution-enabled state at creation so revoking
        // global config later does not retroactively kill live shares.
        $enabledAtCreation = (bool) $config['enabled'];

        $now = nowDatetime();
        for ($attempt = 0; $attempt < $maxRetries; $attempt++) {
            $code = ShareShortCode::generate();
            try {
                $id = (int) Db::transaction(function () use ($learnerId, $scope, $courseId, $code, $enabledAtCreation, $now): int {
                    $id = (int) Db::name('share_entries')->insertGetId([
                        'learner_id' => $learnerId,
                        'scope' => $scope,
                        'course_id' => $courseId,
                        'short_code' => $code,
                        'distribution_enabled_at_creation' => (int) $enabledAtCreation,
                        'created_at' => $now,
                    ]);
                    if ($id <= 0) {
                        throw new BusinessException('INTERNAL', 'SHARE_INSERT_FAILED');
                    }
                    return $id;
                });
                return [
                    'id' => $id,
                    'learner_id' => $learnerId,
                    'scope' => $scope,
                    'course_id' => $courseId,
                    'short_code' => $code,
                    'plaintext_code' => $code,
                    'share_url' => self::buildShareUrl($code),
                    'distribution_enabled_at_creation' => $enabledAtCreation,
                    'created_at' => $now,
                ];
            } catch (\Throwable $e) {
                // uk_share_short_code collision → retry; anything else propagates.
                if (!str_contains($e->getMessage(), 'uk_share_short_code')
                    && !str_contains(strtolower($e->getMessage()), 'duplicate')) {
                    throw $e;
                }
            }
        }
        throw new BusinessException('INTERNAL', 'SHORT_CODE_EXHAUSTED');
    }

    /**
     * List the requesting learner's share entries with masked codes.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listForLearner(int $learnerId, int $limit = 50): array
    {
        $limit = max(1, min(200, $limit));
        $rows = Db::name('share_entries')
            ->where('learner_id', $learnerId)
            ->order('id', 'desc')
            ->limit($limit)
            ->select()
            ->toArray();
        if ($rows === []) {
            return [];
        }
        $ids = array_map('intval', array_column($rows, 'id'));
        // ponytail: single GROUP BY instead of 2·N per-row counts. visit_count
        // is total visits, bound_count is visits already linked to a learner.
        $counts = Db::name('share_visits')
            ->whereIn('share_entry_id', $ids)
            ->group('share_entry_id')
            ->field('share_entry_id, COUNT(*) AS visit_count, SUM(bound_learner_id IS NOT NULL) AS bound_count')
            ->select()
            ->toArray();
        $byId = [];
        foreach ($counts as $c) {
            $byId[(int) $c['share_entry_id']] = [
                'visit_count' => (int) $c['visit_count'],
                'bound_count' => (int) ($c['bound_count'] ?? 0),
            ];
        }
        $items = [];
        foreach ($rows as $row) {
            $entry = (int) $row['id'];
            $stats = $byId[$entry] ?? ['visit_count' => 0, 'bound_count' => 0];
            $items[] = [
                'id' => $entry,
                'scope' => (string) $row['scope'],
                'course_id' => isset($row['course_id']) ? (int) $row['course_id'] : null,
                'masked_code' => maskShortCode((string) $row['short_code']),
                'created_at' => (string) $row['created_at'],
                'distribution_enabled_at_creation' => (bool) $row['distribution_enabled_at_creation'],
                'revoked_at' => $row['revoked_at'] ?? null,
                'visit_count' => $stats['visit_count'],
                'bound_count' => $stats['bound_count'],
            ];
        }
        return $items;
    }

    /**
     * Revoke a share entry. Idempotent — calling twice on the same id is a noop.
     */
    public function revoke(int $learnerId, int $entryId): void
    {
        $now = nowDatetime();
        $affected = (int) Db::name('share_entries')
            ->where('id', $entryId)
            ->where('learner_id', $learnerId)
            ->whereNull('revoked_at')
            ->update(['revoked_at' => $now]);
        if ($affected === 0) {
            // Distinguish missing vs already-revoked vs not-owner:
            // missing is the only case we want to surface.
            $existing = Db::name('share_entries')->where('id', $entryId)->find();
            if ($existing === null) {
                throw new BusinessException('NOT_FOUND', 'SHARE_NOT_FOUND');
            }
        }
    }

    /**
     * Record a visit from a visitor_token. Returns the (possibly freshly
     * minted) token so the caller can set it on a cookie.
     *
     * @return array{visitor_token: string, share_entry_id: int, learner_id: int|null, scope: string, course_id: int|null}
     */
    public function recordVisit(string $shortCode, ?string $visitorToken, ?string $ip, ?string $userAgent): array
    {
        if (!ShareShortCode::isValid($shortCode)) {
            throw new BusinessException('NOT_FOUND', 'SHARE_NOT_FOUND');
        }
        $entry = Db::name('share_entries')
            ->where('short_code', $shortCode)
            ->whereNull('revoked_at')
            ->find();
        if (!$entry) {
            throw new BusinessException('NOT_FOUND', 'SHARE_NOT_FOUND');
        }
        if ((int) ($entry['distribution_enabled_at_creation'] ?? 0) === 0) {
            return [
                'visitor_token' => $visitorToken !== null && strlen($visitorToken) === 32
                    ? $visitorToken
                    : bin2hex(random_bytes(16)),
                'share_entry_id' => (int) $entry['id'],
                'learner_id' => (int) $entry['learner_id'],
                'scope' => (string) $entry['scope'],
                'course_id' => isset($entry['course_id']) ? (int) $entry['course_id'] : null,
            ];
        }

        $token = $visitorToken !== null && strlen($visitorToken) === 32
            ? $visitorToken
            : bin2hex(random_bytes(16));

        $now = nowDatetime();
        Db::name('share_visits')->insert([
            'share_entry_id' => (int) $entry['id'],
            'visitor_token' => $token,
            'ip' => self::truncate($ip, 45),
            'user_agent' => self::truncate($userAgent, 255),
            'created_at' => $now,
        ]);

        return [
            'visitor_token' => $token,
            'share_entry_id' => (int) $entry['id'],
            'learner_id' => (int) $entry['learner_id'],
            // ponytail: scope/course_id let the controller build a 302 target
            // without a second round-trip — recordVisit already loaded them.
            'scope' => (string) $entry['scope'],
            'course_id' => isset($entry['course_id']) ? (int) $entry['course_id'] : null,
        ];
    }

    public function isActiveCode(string $shortCode): bool
    {
        if (!ShareShortCode::isValid($shortCode)) {
            return false;
        }
        $entry = Db::name('share_entries')
            ->where('short_code', $shortCode)
            ->whereNull('revoked_at')
            ->find();
        return $entry !== null;
    }

    /** @return array{share_entry_id: int, referrer_learner_id: int}|null */
    public function resolveLatestVisit(string $visitorToken): ?array
    {
        if (strlen($visitorToken) !== 32) {
            return null;
        }
        $visit = Db::name('share_visits')
            ->where('visitor_token', $visitorToken)
            ->order('id', 'desc')
            ->find();
        if (!$visit) {
            return null;
        }
        return $this->resolveByVisitor($visitorToken, (int) $visit['share_entry_id']);
    }

    /**
     * Resolve the most recent visit for a visitor_token, scoped to the
     * specific share_entry_id. Returns null if no visit exists or the entry
     * was revoked in the meantime.
     *
     * @return array{share_entry_id: int, referrer_learner_id: int}|null
     */
    public function resolveByVisitor(string $visitorToken, int $shareEntryId): ?array
    {
        if (strlen($visitorToken) !== 32) {
            return null;
        }
        $visit = Db::name('share_visits')
            ->where('share_entry_id', $shareEntryId)
            ->where('visitor_token', $visitorToken)
            ->order('id', 'desc')
            ->find();
        if (!$visit) {
            return null;
        }
        $entry = Db::name('share_entries')
            ->where('id', $shareEntryId)
            ->whereNull('revoked_at')
            ->find();
        if (!$entry) {
            return null;
        }
        if ((int) ($entry['distribution_enabled_at_creation'] ?? 0) === 0) {
            return null;
        }
        return [
            'share_entry_id' => $shareEntryId,
            'referrer_learner_id' => (int) $entry['learner_id'],
        ];
    }

    public function isVisitorOrEntryBound(string $visitorToken, int $shareEntryId): bool
    {
        $boundVisit = Db::name('share_visits')
            ->where('visitor_token', $visitorToken)
            ->whereNotNull('bound_learner_id')
            ->find();
        if ($boundVisit) {
            return true;
        }
        $boundEntry = Db::name('share_visits')
            ->where('share_entry_id', $shareEntryId)
            ->whereNotNull('bound_learner_id')
            ->find();
        return $boundEntry !== null;
    }

    /**
     * Backfill: mark a visitor's most recent unbound visits as bound to a
     * learner, only when the underlying share entry has not been revoked.
     * Returns the number of visits updated.
     */
    public function bindVisitorToLearner(int $learnerId, string $visitorToken): int
    {
        if (strlen($visitorToken) !== 32) {
            return 0;
        }
        $now = nowDatetime();
        // ponytail: explicit id list avoids a closure subquery through think-orm,
        // which under our buildSql path sometimes flattens to an empty IN.
        // Also filter out entries that were minted while distribution was off
        // — those visits exist for audit but never resolve a referrer.
        $activeIds = array_map('intval', (array) Db::name('share_entries')
            ->whereNull('revoked_at')
            ->where('distribution_enabled_at_creation', 1)
            ->column('id'));
        if ($activeIds === []) {
            return 0;
        }
        return (int) Db::name('share_visits')
            ->where('visitor_token', $visitorToken)
            ->whereNull('bound_learner_id')
            ->whereIn('share_entry_id', $activeIds)
            ->update(['bound_learner_id' => $learnerId, 'bound_at' => $now]);
    }

    private static function buildShareUrl(string $code): string
    {
        $base = rtrim((string) (getenv('LEARN_SITE_PUBLIC_BASE') ?: 'https://learn.example.test'), '/');
        return $base . '/r/' . $code;
    }

    private static function truncate(?string $value, int $max): ?string
    {
        if ($value === null) {
            return null;
        }
        return mb_substr($value, 0, $max);
    }
}