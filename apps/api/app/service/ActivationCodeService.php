<?php

declare(strict_types=1);

namespace App\service;

use App\model\ActivationCode;
use App\model\ActivationCodeBatch;
use App\service\DataScopeService;
use App\support\Logger;
use support\think\Db;

/**
 * ActivationCodeService — per-course one-shot redemption codes (010 US2/US3).
 *
 * Invariants (specs/010 data-model.md):
 *  - One code binds exactly one course and can be redeemed exactly once.
 *  - Plaintext lives only in the create-batch response (`codes[]`); storage
 *    is SHA-256 of the normalized code plus 4-char prefix/suffix for
 *    masked display. Plaintext never enters audit payloads or logs.
 *  - Expiry is derived (unused + expires_at in the past), not a stored state.
 *  - Every admin mutation and every redemption writes an audit row.
 */
final class ActivationCodeService
{
    private const TIMEZONE = 'Asia/Shanghai';
    private const MIN_QUANTITY = 1;
    private const MAX_QUANTITY = 1000;
    private const CODE_LENGTH = 16;
    private const CODE_GROUP = 4;
    private const CHUNK_SIZE = 250;
    private const HASH_RETRY = 3;
    private const MAX_PAGE_LIMIT = 100;

    /** Crockford Base32: digits + letters minus I/L/O/U (33rd symbol excluded). */
    private const CODE_ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

    public function __construct(private readonly DataScopeService $scope = new DataScopeService())
    {
    }

    // -------------------------------------------------------------------------
    // 管理端:批量生成 / 列表 / 作废 (US2)
    // -------------------------------------------------------------------------

    /**
     * Generate a batch of codes for one published paid course.
     *
     * @return array<string, mixed> batch DTO including one-time plaintext `codes[]`
     */
    public function generateBatch(int $courseId, int $quantity, mixed $expiresAt, int $staffId): array
    {
        $this->assertStaff($staffId);
        $course = $this->assertCourseAccessible($staffId, $courseId);
        if ((string) $course['status'] !== 'published') {
            throw new BusinessException('VALIDATION_FAILED', 'COURSE_NOT_PUBLISHED');
        }
        if ((string) $course['price_mode'] !== 'paid') {
            throw new BusinessException('VALIDATION_FAILED', 'COURSE_NOT_PAID');
        }
        if ($quantity < self::MIN_QUANTITY || $quantity > self::MAX_QUANTITY) {
            throw new BusinessException('VALIDATION_FAILED', 'ACTIVATION_CODE_QUANTITY_INVALID');
        }
        $expires = $this->normalizeExpiresAt($expiresAt);

        $now = $this->nowDatetime();
        $drafts = [];
        for ($i = 0; $i < $quantity; $i++) {
            $drafts[] = $this->generateCodeText();
        }

        [$batchId, $plaintexts] = Db::transaction(function () use (
            $courseId,
            $quantity,
            $expires,
            $staffId,
            $now,
            $drafts,
        ): array {
            $batchId = (int) ActivationCodeBatch::create([
                'course_id' => $courseId,
                'quantity' => $quantity,
                'expires_at' => $expires,
                'created_by_staff_id' => $staffId,
                'created_at' => $now,
                'updated_at' => $now,
            ])->id;

            $plaintexts = [];
            foreach (array_chunk($drafts, self::CHUNK_SIZE) as $chunk) {
                array_push($plaintexts, ...$this->insertChunk($batchId, $courseId, $expires, $chunk));
            }
            return [$batchId, $plaintexts];
        });

        $this->writeAudit($staffId, 'activation_code.batch_create', $batchId, [
            'course_id' => $courseId,
            'quantity' => $quantity,
            'expires_at' => $expires,
        ]);
        Logger::info('activation_code.batch_created', [
            'actor_id' => $staffId,
            'batch_id' => $batchId,
            'course_id' => $courseId,
            'quantity' => $quantity,
        ]);

        $batch = $this->loadBatch($batchId);
        $batch['codes'] = $plaintexts;
        return $batch;
    }

    /**
     * Paginated, masked list for one course. `expired` is a derived filter:
     * unused rows whose expires_at has passed.
     *
     * @param array{status?:string,page?:int,limit?:int} $filters
     * @return array{items:list<array<string,mixed>>,total:int,page:int,limit:int}
     */
    public function listCodes(int $staffId, int $courseId, array $filters): array
    {
        $this->assertCourseAccessible($staffId, $courseId);
        $status = (string) ($filters['status'] ?? '');
        if ($status !== '' && !in_array($status, ['unused', 'redeemed', 'void', 'expired'], true)) {
            throw new BusinessException('VALIDATION_FAILED', 'ACTIVATION_CODE_STATUS_INVALID');
        }
        $page = max(1, (int) ($filters['page'] ?? 1));
        $limit = max(1, min(self::MAX_PAGE_LIMIT, (int) ($filters['limit'] ?? 20)));

        $query = Db::name('activation_codes')->where('course_id', $courseId);
        if ($status === 'expired') {
            $query->where('status', ActivationCode::STATUS_UNUSED)
                ->whereNotNull('expires_at')
                ->where('expires_at', '<=', $this->nowDatetime());
        } elseif ($status === 'unused') {
            $now = $this->nowDatetime();
            $query->where('status', ActivationCode::STATUS_UNUSED)
                ->where(function ($where) use ($now): void {
                    $where->whereNull('expires_at')->whereOr('expires_at', '>', $now);
                });
        } elseif ($status !== '') {
            $query->where('status', $status);
        }

        $total = (int) (clone $query)->count();
        $rows = $query->order('id', 'desc')->page($page, $limit)->select()->toArray();

        return [
            'items' => array_map([$this, 'shapeCodeRow'], is_array($rows) ? $rows : []),
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
        ];
    }

    /** @return array{voided:bool} */
    public function voidCode(int $staffId, int $courseId, int $codeId): array
    {
        $this->assertCourseAccessible($staffId, $courseId);
        $code = Db::name('activation_codes')
            ->where('id', $codeId)
            ->where('course_id', $courseId)
            ->find();
        if (!is_array($code)) {
            throw new BusinessException('NOT_FOUND', 'ACTIVATION_CODE_NOT_FOUND');
        }
        if ((string) $code['status'] !== ActivationCode::STATUS_UNUSED) {
            throw new BusinessException('CONFLICT', 'ACTIVATION_CODE_NOT_VOIDABLE');
        }

        $now = $this->nowDatetime();
        Db::transaction(function () use ($codeId, $staffId, $now): void {
            $updated = Db::name('activation_codes')
                ->where('id', $codeId)
                ->where('status', ActivationCode::STATUS_UNUSED)
                ->update([
                    'status' => ActivationCode::STATUS_VOID,
                    'voided_by_staff_id' => $staffId,
                    'voided_at' => $now,
                    'updated_at' => $now,
                ]);
            if ($updated === 0) {
                throw new BusinessException('CONFLICT', 'ACTIVATION_CODE_NOT_VOIDABLE');
            }
        });

        $this->writeAudit($staffId, 'activation_code.void', $codeId, [
            'course_id' => $courseId,
            'batch_id' => (int) $code['batch_id'],
        ]);
        return ['voided' => true];
    }

    // -------------------------------------------------------------------------
    // 学习端:兑换 (US3)
    // -------------------------------------------------------------------------

    /**
     * Redeem one code for a learner (see US3 tests). The row lock plus the
     * unique active-entitlement index guarantee a single winner; the
     * `grant()` idempotent path is never used to consume a code — an
     * already-entitled learner is rejected before any state changes.
     *
     * @return array{granted:true,course_id:int,course_title:string,source:string}
     */
    public function redeem(int $learnerId, string $codeInput): array
    {
        $normalized = $this->normalizeCode($codeInput);
        if ($normalized === null) {
            throw new BusinessException('VALIDATION_FAILED', 'ACTIVATION_CODE_INVALID');
        }

        $granted = Db::transaction(function () use ($learnerId, $normalized) {
            $code = Db::name('activation_codes')
                ->where('code_hash', hash('sha256', $normalized))
                ->lock(true)
                ->find();
            if (!is_array($code)) {
                throw new BusinessException('VALIDATION_FAILED', 'ACTIVATION_CODE_INVALID');
            }
            $nowTs = $this->nowTimestamp();
            if ((string) $code['status'] === ActivationCode::STATUS_REDEEMED) {
                throw new BusinessException('CONFLICT', 'ACTIVATION_CODE_REDEEMED');
            }
            if ((string) $code['status'] === ActivationCode::STATUS_VOID) {
                throw new BusinessException('CONFLICT', 'ACTIVATION_CODE_VOID');
            }
            if ($code['expires_at'] !== null
                && $nowTs >= $this->sqlDatetimeTimestamp((string) $code['expires_at'])) {
                throw new BusinessException('CONFLICT', 'ACTIVATION_CODE_EXPIRED');
            }
            $course = Db::name('courses')
                ->where('id', (int) $code['course_id'])
                ->field('id,title,status')
                ->find();
            if (!is_array($course) || (string) $course['status'] !== 'published') {
                throw new BusinessException('CONFLICT', 'ACTIVATION_CODE_COURSE_UNAVAILABLE');
            }
            $active = Db::name('course_entitlements')
                ->where('learner_id', $learnerId)
                ->where('course_id', (int) $code['course_id'])
                ->where('status', 'active')
                ->lock(true)
                ->find();
            if (is_array($active)) {
                throw new BusinessException('CONFLICT', 'ENTITLEMENT_ALREADY_ACTIVE');
            }

            $now = $this->nowDatetime();
            $entitlement = (new EntitlementService())->grant(
                $learnerId,
                (int) $code['course_id'],
                'activation_code',
                null,
                (int) $code['id'],
            );
            Db::name('activation_codes')
                ->where('id', (int) $code['id'])
                ->where('status', ActivationCode::STATUS_UNUSED)
                ->update([
                    'status' => ActivationCode::STATUS_REDEEMED,
                    'redeemed_by_learner_id' => $learnerId,
                    'redeemed_at' => $now,
                    'updated_at' => $now,
                ]);
            $this->writeAudit($learnerId, 'activation_code.redeem', (int) $code['id'], [
                'course_id' => (int) $code['course_id'],
                'batch_id' => (int) $code['batch_id'],
            ]);
            return ['course' => $course, 'entitlement' => $entitlement];
        });

        return [
            'granted' => true,
            'course_id' => (int) $granted['course']['id'],
            'course_title' => (string) $granted['course']['title'],
            'source' => 'activation_code',
        ];
    }

    // -------------------------------------------------------------------------
    // 内部工具
    // -------------------------------------------------------------------------

    /**
     * Insert a chunk of freshly generated codes. A duplicate hash (astronomic
     * entropy, but the unique index is authoritative) regenerates the chunk
     * and returns the matching final plaintexts.
     *
     * @param list<array{0:string,1:string}> $drafts normalized + dashed plaintext
     * @return list<string>
     */
    private function insertChunk(
        int $batchId,
        int $courseId,
        ?string $expires,
        array $drafts,
    ): array {
        for ($attempt = 0; $attempt < self::HASH_RETRY; $attempt++) {
            $rows = array_map(static fn (array $draft): array => [
                'batch_id' => $batchId,
                'course_id' => $courseId,
                'code_hash' => hash('sha256', $draft[0]),
                'code_prefix' => substr($draft[0], 0, 4),
                'code_suffix' => substr($draft[0], -4),
                'expires_at' => $expires,
            ], $drafts);
            try {
                Db::name('activation_codes')->insertAll($rows);
                return array_column($drafts, 1);
            } catch (\Throwable $e) {
                if ($attempt === self::HASH_RETRY - 1) {
                    throw $e;
                }
                foreach ($drafts as $index => $_draft) {
                    $drafts[$index] = $this->generateCodeText();
                }
            }
        }
        throw new \RuntimeException('ACTIVATION_CODE_GENERATION_FAILED');
    }

    /** @return array{0:string,1:string} normalized text, dashed plaintext */
    private function generateCodeText(): array
    {
        $alphabet = self::CODE_ALPHABET;
        $max = strlen($alphabet) - 1;
        $normalized = '';
        for ($i = 0; $i < self::CODE_LENGTH; $i++) {
            $normalized .= $alphabet[random_int(0, $max)];
        }
        $plain = implode('-', str_split($normalized, self::CODE_GROUP));
        return [$normalized, $plain];
    }

    /**
     * Trim spaces/dashes, uppercase, validate against the Crockford
     * alphabet; case and dashes never affect the hash lookup.
     */
    private function normalizeCode(string $input): ?string
    {
        $normalized = strtoupper(preg_replace('/[\s\-]/', '', $input) ?? '');
        if ($normalized === '' || preg_match('/^[0-9A-Z]+$/', $normalized) !== 1) {
            return null;
        }
        if (strpbrk($normalized, 'ILOU') !== false) {
            return null;
        }
        if (strlen($normalized) !== self::CODE_LENGTH) {
            return null;
        }
        return $normalized;
    }

    private function normalizeExpiresAt(mixed $expiresAt): ?string
    {
        if ($expiresAt === null || (is_string($expiresAt) && trim($expiresAt) === '')) {
            return null;
        }
        $input = trim((string) $expiresAt);
        // 规格假设:过期时间按站点统一时区 Asia/Shanghai 解释。不带时区的
        // 裸日期补 +08:00,带偏移量的 ISO8601 交给 strtotime 原样解析。
        if (preg_match('/^\d{4}-\d{2}-\d{2}([ T]\d{2}:\d{2}(:\d{2})?)?$/', $input) === 1) {
            $input = str_replace(' ', 'T', $input) . '+08:00';
        }
        $ts = strtotime($input);
        if ($ts === false) {
            throw new BusinessException('VALIDATION_FAILED', 'ACTIVATION_CODE_EXPIRES_INVALID');
        }
        if ($ts <= $this->nowTimestamp()) {
            throw new BusinessException('VALIDATION_FAILED', 'ACTIVATION_CODE_EXPIRES_INVALID');
        }
        return $this->formatShanghai($ts);
    }

    /** @return array<string,mixed> */
    private function assertCourseAccessible(int $staffId, int $courseId): array
    {
        $course = Db::name('courses')
            ->where('id', $courseId)
            ->field('id, title, status, price_mode, department_id, created_by_staff_id')
            ->find();
        if (!is_array($course)) {
            throw new BusinessException('NOT_FOUND', 'COURSE_NOT_FOUND');
        }
        DataScopeService::assertCourseAccessibleFromScope(
            $this->scope->resolveForCourses($staffId),
            (int) $course['department_id'],
            (int) $course['created_by_staff_id'],
            $staffId,
        );
        return $course;
    }

    /** @return array<string,mixed> */
    private function loadBatch(int $batchId): array
    {
        $row = Db::name('activation_code_batches')->where('id', $batchId)->find();
        if (!is_array($row)) {
            throw new BusinessException('NOT_FOUND', 'ACTIVATION_CODE_BATCH_NOT_FOUND');
        }
        return $this->shapeBatch($row);
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function shapeBatch(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'course_id' => (int) $row['course_id'],
            'quantity' => (int) $row['quantity'],
            'expires_at' => $row['expires_at'] !== null ? (string) $row['expires_at'] : null,
            'created_at' => (string) $row['created_at'],
        ];
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function shapeCodeRow(array $row): array
    {
        $status = (string) $row['status'];
        $expired = $status === ActivationCode::STATUS_UNUSED
            && $row['expires_at'] !== null
            && $this->nowTimestamp() >= $this->sqlDatetimeTimestamp((string) $row['expires_at']);

        $redeemedBy = null;
        if ($status === ActivationCode::STATUS_REDEEMED && $row['redeemed_by_learner_id'] !== null) {
            $identity = Db::name('learners')
                ->alias('l')
                ->join('accounts a', 'a.id = l.account_id')
                ->where('l.account_id', (int) $row['redeemed_by_learner_id'])
                ->field('a.id AS account_id, l.nickname')
                ->find();
            if (is_array($identity)) {
                $nickname = trim((string) ($identity['nickname'] ?? ''));
                $redeemedBy = [
                    'account_id' => (int) $identity['account_id'],
                    // 同课程学员名单的公开身份规则:昵称或「匿名学员」。
                    'nickname' => $nickname !== '' ? $nickname : '匿名学员',
                ];
            }
        }

        return [
            'id' => (int) $row['id'],
            'batch_id' => (int) $row['batch_id'],
            'course_id' => (int) $row['course_id'],
            'display_code' => (string) $row['code_prefix'] . '****' . (string) $row['code_suffix'],
            'status' => $expired ? 'expired' : $status,
            'expires_at' => $row['expires_at'] !== null ? (string) $row['expires_at'] : null,
            'redeemed_by' => $redeemedBy,
            'redeemed_at' => $row['redeemed_at'] !== null ? (string) $row['redeemed_at'] : null,
            'voided_at' => $row['voided_at'] !== null ? (string) $row['voided_at'] : null,
            'created_at' => (string) $row['created_at'],
        ];
    }

    private function assertStaff(int $staffId): void
    {
        if ($staffId <= 0) {
            throw new BusinessException('UNAUTHENTICATED', 'UNAUTHENTICATED');
        }
    }

    /** @param array<string,mixed> $payload */
    private function writeAudit(int $actorId, string $action, int $targetId, array $payload): void
    {
        Db::name('audit_log')->insert([
            'actor_id' => $actorId,
            'action' => $action,
            'target_type' => 'activation_code',
            'target_id' => $targetId,
            'payload_json' => json_encode($payload, JSON_UNESCAPED_UNICODE),
            'created_at' => $this->nowDatetime(),
        ]);
    }

    private function nowDatetime(): string
    {
        return $this->formatShanghai(time());
    }

    private function nowTimestamp(): int
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone(self::TIMEZONE)))->getTimestamp();
    }

    /** Parse SQL DATETIME as an Asia/Shanghai wall-clock value. */
    private function sqlDatetimeTimestamp(string $datetime): int
    {
        return (new \DateTimeImmutable($datetime, new \DateTimeZone(self::TIMEZONE)))->getTimestamp();
    }

    private function formatShanghai(int $timestamp): string
    {
        return (new \DateTimeImmutable('@' . $timestamp))
            ->setTimezone(new \DateTimeZone(self::TIMEZONE))
            ->format('Y-m-d H:i:s');
    }
}
