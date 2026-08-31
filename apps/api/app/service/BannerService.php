<?php

declare(strict_types=1);

namespace App\service;

use App\support\cache\HomeCache;
use support\think\Db;

final class BannerService
{
    private const IMAGE_KEY_PATTERN = '#^banners/\d{4}/\d{2}/[a-f0-9]{32}\.(jpg|jpeg|png|webp)$#D';
    private const MAX_INTERNAL_LINK_LENGTH = 512;
    private const MAX_EXTERNAL_LINK_LENGTH = 2048;
    private const MAX_SORT_ORDER = 9999;
    private const TIMEZONE = 'Asia/Shanghai';

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function create(array $input, int $staffId): array
    {
        $this->assertActor($staffId);
        [$imageUrl, $imageKey] = $this->validateImage($input, true);
        $linkUrl = $this->validateLink($input['link_url'] ?? null);
        $sortOrder = $this->validateSortOrder($input['sort_order'] ?? 0);
        $isEnabled = array_key_exists('is_enabled', $input)
            ? $this->validateBoolean($input['is_enabled'], 'BANNER_STATUS_INVALID')
            : true;
        $now = $this->nowDatetime();

        $id = (int) Db::name('banners')->insertGetId([
            'image_url' => $imageUrl,
            'image_key' => $imageKey,
            'link_url' => $linkUrl,
            'sort_order' => $sortOrder,
            'is_enabled' => $isEnabled ? 1 : 0,
            'deleted_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $this->writeAudit($staffId, 'banner.create', $id, [
            'image_key' => $imageKey,
            'link_url' => $linkUrl,
            'sort_order' => $sortOrder,
            'is_enabled' => $isEnabled,
        ]);
        (new HomeCache())->forget(HomeCache::KEY_BANNERS);

        return $this->getForAdmin($id);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function update(int $id, array $input, int $staffId): array
    {
        $this->assertActor($staffId);
        $row = $this->activeRow($id);
        if ($row === null) {
            throw new BusinessException('NOT_FOUND', 'BANNER_NOT_FOUND');
        }
        $expectedUpdatedAt = $this->validateExpectedUpdatedAt($input['expected_updated_at'] ?? null);

        $updates = [];
        if (array_key_exists('image_url', $input) || array_key_exists('image_key', $input)) {
            if (!array_key_exists('image_url', $input) || !array_key_exists('image_key', $input)) {
                throw new BusinessException('VALIDATION_FAILED', 'BANNER_IMAGE_PAIR_REQUIRED');
            }
            [$imageUrl, $imageKey] = $this->validateImage($input, true);
            $updates['image_url'] = $imageUrl;
            $updates['image_key'] = $imageKey;
        }
        if (array_key_exists('link_url', $input)) {
            $updates['link_url'] = $this->validateLink($input['link_url']);
        }
        if (array_key_exists('sort_order', $input)) {
            $updates['sort_order'] = $this->validateSortOrder($input['sort_order']);
        }
        if (array_key_exists('is_enabled', $input)) {
            $updates['is_enabled'] = $this->validateBoolean($input['is_enabled'], 'BANNER_STATUS_INVALID') ? 1 : 0;
        }
        if ($updates === []) {
            throw new BusinessException('VALIDATION_FAILED', 'EMPTY_UPDATE');
        }

        $updates['updated_at'] = $this->nextUpdatedAt((string) $row['updated_at']);
        $updated = Db::name('banners')
            ->where('id', $id)
            ->whereNull('deleted_at')
            ->where('updated_at', $expectedUpdatedAt)
            ->update($updates);
        if ($updated !== 1) {
            throw new BusinessException('CONFLICT', 'BANNER_VERSION_CONFLICT');
        }
        $this->writeAudit($staffId, 'banner.update', $id, $updates);
        (new HomeCache())->forget(HomeCache::KEY_BANNERS);

        return $this->getForAdmin($id);
    }

    /** @return array<string, mixed> */
    public function getForAdmin(int $id): array
    {
        $row = $this->activeRow($id);
        if ($row === null) {
            throw new BusinessException('NOT_FOUND', 'BANNER_NOT_FOUND');
        }
        return $this->shapeAdmin($row);
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{items:list<array<string,mixed>>,total:int,page:int,limit:int}
     */
    public function listForAdmin(array $filters = []): array
    {
        [$page, $limit] = $this->normalizePagination($filters);
        $query = Db::name('banners')->whereNull('deleted_at');
        if (
            array_key_exists('is_enabled', $filters)
            && $filters['is_enabled'] !== null
            && $filters['is_enabled'] !== ''
        ) {
            $enabled = $this->validateBoolean($filters['is_enabled'], 'BANNER_STATUS_INVALID');
            $query->where('is_enabled', $enabled ? 1 : 0);
        }

        $total = (int) (clone $query)->count();
        $rows = $query->order('sort_order', 'asc')->order('id', 'asc')
            ->page($page, $limit)->select()->toArray();

        return [
            'items' => array_map(fn (array $row): array => $this->shapeAdmin($row), $rows),
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
        ];
    }

    /** @return list<array{id:int,image_url:string,link_url:?string,sort_order:int}> */
    public function listPublic(): array
    {
        $rows = Db::name('banners')
            ->whereNull('deleted_at')
            ->where('is_enabled', 1)
            ->order('sort_order', 'asc')
            ->order('id', 'asc')
            ->select()->toArray();

        return array_map(fn (array $row): array => $this->shapePublic($row), $rows);
    }

    public function softDelete(int $id, int $staffId): void
    {
        $this->assertActor($staffId);
        $row = $this->activeRow($id);
        if ($row === null) {
            return;
        }

        $deletedAt = $this->nowDatetime();
        $updated = Db::name('banners')->where('id', $id)->whereNull('deleted_at')->update([
            'deleted_at' => $deletedAt,
            'updated_at' => $deletedAt,
        ]);
        if ($updated > 0) {
            $this->writeAudit($staffId, 'banner.delete', $id, [
                'image_url' => (string) $row['image_url'],
                'link_url' => $row['link_url'] !== null ? (string) $row['link_url'] : null,
            ]);
            (new HomeCache())->forget(HomeCache::KEY_BANNERS);
        }
    }

    public function validateLink(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (!is_string($value)) {
            throw new BusinessException('VALIDATION_FAILED', 'BANNER_LINK_INVALID');
        }
        $link = trim($value);
        if ($link === '') {
            return null;
        }
        if (strlen($link) > self::MAX_EXTERNAL_LINK_LENGTH || preg_match('/[\x00-\x1F\x7F]/', $link) === 1) {
            throw new BusinessException('VALIDATION_FAILED', 'BANNER_LINK_INVALID');
        }
        if (str_starts_with($link, '/') && !str_starts_with($link, '//')) {
            if (strlen($link) > self::MAX_INTERNAL_LINK_LENGTH) {
                throw new BusinessException('VALIDATION_FAILED', 'BANNER_LINK_INVALID');
            }
            return $link;
        }

        $parts = parse_url($link);
        $scheme = is_array($parts) ? strtolower((string) ($parts['scheme'] ?? '')) : '';
        $host = is_array($parts) ? (string) ($parts['host'] ?? '') : '';
        if (
            !in_array($scheme, ['http', 'https'], true)
            || $host === ''
            || filter_var($link, FILTER_VALIDATE_URL) === false
        ) {
            throw new BusinessException('VALIDATION_FAILED', 'BANNER_LINK_INVALID');
        }
        return $link;
    }

    /**
     * @param array<string,mixed> $input
     * @return array{0:string,1:string}
     */
    private function validateImage(array $input, bool $required): array
    {
        $imageUrl = $input['image_url'] ?? null;
        $imageKey = $input['image_key'] ?? null;
        if (!$required && $imageUrl === null && $imageKey === null) {
            return ['', ''];
        }
        if (!is_string($imageUrl) || !is_string($imageKey)) {
            throw new BusinessException('VALIDATION_FAILED', 'BANNER_IMAGE_REQUIRED');
        }
        $imageUrl = trim($imageUrl);
        $imageKey = trim($imageKey);
        if (
            preg_match(self::IMAGE_KEY_PATTERN, $imageKey) !== 1
            || $imageUrl !== '/api/media/' . $imageKey
        ) {
            throw new BusinessException('VALIDATION_FAILED', 'BANNER_IMAGE_INVALID');
        }
        return [$imageUrl, $imageKey];
    }

    private function validateSortOrder(mixed $value): int
    {
        if (is_string($value) && ctype_digit($value)) {
            $value = (int) $value;
        }
        if (!is_int($value) || $value < 0 || $value > self::MAX_SORT_ORDER) {
            throw new BusinessException('VALIDATION_FAILED', 'BANNER_SORT_INVALID');
        }
        return $value;
    }

    private function validateBoolean(mixed $value, string $message): bool
    {
        if ($value === true || $value === false) {
            return $value;
        }
        if ($value === 1 || $value === 0) {
            return $value === 1;
        }
        if (is_string($value) && ($value === '1' || $value === '0')) {
            return $value === '1';
        }
        throw new BusinessException('VALIDATION_FAILED', $message);
    }

    private function validateExpectedUpdatedAt(mixed $value): string
    {
        if (
            !is_string($value)
            || preg_match(
                '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})$/D',
                $value,
            ) !== 1
        ) {
            throw new BusinessException('VALIDATION_FAILED', 'BANNER_VERSION_REQUIRED');
        }

        try {
            return (new \DateTimeImmutable($value))
                ->setTimezone(new \DateTimeZone(self::TIMEZONE))
                ->format('Y-m-d H:i:s');
        } catch (\Exception) {
            throw new BusinessException('VALIDATION_FAILED', 'BANNER_VERSION_INVALID');
        }
    }

    private function nextUpdatedAt(string $current): string
    {
        $timezone = new \DateTimeZone(self::TIMEZONE);
        $now = new \DateTimeImmutable('now', $timezone);
        $currentTime = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $current, $timezone);
        // ponytail: optimistic lock requires updated_at (stored as DATETIME second
        // precision) to strictly increase each write — otherwise two writes inside the
        // same wall-clock second produce an identical stored value and the WHERE clause
        // matches a stale expected_updated_at. Always advance past currentTime + 1s.
        $candidate = $now;
        if ($currentTime instanceof \DateTimeImmutable) {
            $candidate = max($candidate, $currentTime->modify('+1 second'));
        }
        return $candidate->format('Y-m-d H:i:s');
    }

    /**
     * @param array<string,mixed> $filters
     * @return array{0:int,1:int}
     */
    private function normalizePagination(array $filters): array
    {
        $page = max(1, (int) ($filters['page'] ?? 1));
        $limit = min(100, max(1, (int) ($filters['limit'] ?? 20)));
        return [$page, $limit];
    }

    /** @return array<string,mixed>|null */
    private function activeRow(int $id): ?array
    {
        $row = Db::name('banners')->where('id', $id)->whereNull('deleted_at')->find();
        return is_array($row) ? $row : null;
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function shapeAdmin(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'image_url' => (string) $row['image_url'],
            'image_key' => (string) $row['image_key'],
            'link_url' => $row['link_url'] !== null ? (string) $row['link_url'] : null,
            'sort_order' => (int) $row['sort_order'],
            'is_enabled' => (int) $row['is_enabled'] === 1,
            'created_at' => $this->toIso8601((string) $row['created_at']),
            'updated_at' => $this->toIso8601((string) $row['updated_at']),
        ];
    }

    /**
     * @param array<string,mixed> $row
     * @return array{id:int,image_url:string,link_url:?string,sort_order:int}
     */
    private function shapePublic(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'image_url' => (string) $row['image_url'],
            'link_url' => $row['link_url'] !== null ? (string) $row['link_url'] : null,
            'sort_order' => (int) $row['sort_order'],
        ];
    }

    private function assertActor(int $staffId): void
    {
        if ($staffId <= 0) {
            throw new BusinessException('UNAUTHENTICATED', 'UNAUTHENTICATED');
        }
    }

    /** @param array<string,mixed> $payload */
    private function writeAudit(int $staffId, string $action, int $targetId, array $payload): void
    {
        Db::name('audit_log')->insert([
            'actor_id' => $staffId,
            'action' => $action,
            'target_type' => 'banners',
            'target_id' => $targetId,
            'payload_json' => json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'created_at' => $this->nowDatetime(),
        ]);
    }

    private function nowDatetime(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone(self::TIMEZONE)))->format('Y-m-d H:i:s');
    }

    private function toIso8601(string $datetime): string
    {
        return (new \DateTimeImmutable($datetime, new \DateTimeZone(self::TIMEZONE)))->format(DATE_ATOM);
    }
}
