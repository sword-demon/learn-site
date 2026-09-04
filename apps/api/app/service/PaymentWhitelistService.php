<?php

declare(strict_types=1);

namespace App\service;

use support\think\Db;

final class PaymentWhitelistService
{
    private const PHONE_PATTERN = '/^1\d{10}$/';

    public function add(int $staffId, string $phone, bool $enabled = true, ?string $note = null): int
    {
        $this->assertStaff($staffId);
        $phone = trim($phone);
        $this->validatePhone($phone);
        if (Db::name('payment_whitelist')->where('phone', $phone)->whereNull('deleted_at')->find()) {
            throw new BusinessException('CONFLICT', 'WHITELIST_DUPLICATE');
        }
        if ($note !== null && strlen($note) > 120) {
            throw new BusinessException('VALIDATION_FAILED', 'INVALID_NOTE');
        }
        $now = date('Y-m-d H:i:s');
        $id = (int) Db::name('payment_whitelist')->insertGetId([
            'phone' => $phone,
            'enabled' => $enabled,
            'note' => $note,
            'created_by' => $staffId,
            'created_at' => $now,
            'updated_at' => $now,
            'deleted_at' => null,
        ]);
        $this->writeAudit($staffId, 'payment_whitelist.create', $id, ['phone_masked' => $this->maskPhone($phone)]);
        return $id;
    }

    public function toggle(int $staffId, int $id, bool $enabled): void
    {
        $this->update($staffId, $id, ['enabled' => $enabled, 'note' => null], false);
    }

    /** @param array<string, mixed> $input */
    public function update(int $staffId, int $id, array $input, bool $writeNote = true): void
    {
        $this->assertStaff($staffId);
        if (!is_bool($input['enabled'] ?? null)) {
            throw new BusinessException('VALIDATION_FAILED', 'INVALID_ENABLED');
        }
        $note = $input['note'] ?? null;
        if ($note !== null && (!is_string($note) || strlen($note) > 120)) {
            throw new BusinessException('VALIDATION_FAILED', 'INVALID_NOTE');
        }
        $query = Db::name('payment_whitelist')->where('id', $id)->whereNull('deleted_at');
        $row = $query->find();
        if (!is_array($row)) {
            throw new BusinessException('NOT_FOUND', 'WHITELIST_NOT_FOUND');
        }
        $data = ['enabled' => $input['enabled'], 'updated_at' => date('Y-m-d H:i:s')];
        if ($writeNote) {
            $data['note'] = $note;
        }
        $query->update($data);
        $this->writeAudit($staffId, 'payment_whitelist.update', $id, [
            'enabled' => $input['enabled'],
            'note_changed' => $writeNote,
        ]);
    }

    public function softDelete(int $staffId, int $id): void
    {
        $this->assertStaff($staffId);
        $row = Db::name('payment_whitelist')->where('id', $id)->whereNull('deleted_at')->find();
        if (!is_array($row)) {
            return;
        }
        Db::name('payment_whitelist')->where('id', $id)->update(['deleted_at' => date('Y-m-d H:i:s')]);
        $this->writeAudit($staffId, 'payment_whitelist.delete', $id, []);
    }

    /** @return array<string, mixed> */
    public function get(int $id): array
    {
        $row = Db::name('payment_whitelist')->where('id', $id)->whereNull('deleted_at')->find();
        if (!is_array($row)) {
            throw new BusinessException('NOT_FOUND', 'WHITELIST_NOT_FOUND');
        }
        return $this->shape($row);
    }

    /** @return array{items:list<array<string,mixed>>,total:int,page:int,limit:int} */
    public function list(int $page = 1, int $limit = 20): array
    {
        $page = max(1, $page);
        $limit = min(200, max(1, $limit));
        $query = Db::name('payment_whitelist')->whereNull('deleted_at');
        $total = (int) $query->count();
        $rows = $query->order('id', 'desc')->page($page, $limit)->select()->toArray();
        return [
            'items' => array_map(fn (array $row): array => $this->shape($row), $rows),
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
        ];
    }

    public function isWhitelisted(string $phone): bool
    {
        return Db::name('payment_whitelist')
            ->where('phone', $phone)
            ->where('enabled', true)
            ->whereNull('deleted_at')
            ->find() !== null;
    }

    private function assertStaff(int $staffId): void
    {
        if ($staffId <= 0) {
            throw new BusinessException('UNAUTHENTICATED', 'UNAUTHENTICATED');
        }
    }

    private function validatePhone(string $phone): void
    {
        if (preg_match(self::PHONE_PATTERN, $phone) !== 1) {
            throw new BusinessException('VALIDATION_FAILED', 'INVALID_PHONE');
        }
    }

    /**
     * @param array<string, mixed> $row
     * @return array{id: int, phone_masked: string, enabled: bool, note: string|null, created_at: string, updated_at: string}
     */
    private function shape(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'phone_masked' => $this->maskPhone((string) $row['phone']),
            'enabled' => (bool) $row['enabled'],
            'note' => $row['note'] !== null ? (string) $row['note'] : null,
            'created_at' => $this->iso8601($row['created_at']),
            'updated_at' => $this->iso8601($row['updated_at']),
        ];
    }

    private function maskPhone(string $phone): string
    {
        return substr($phone, 0, 3) . '****' . substr($phone, -4);
    }

    private function iso8601(mixed $value): string
    {
        return (new \DateTimeImmutable((string) $value, new \DateTimeZone('Asia/Shanghai')))
            ->format(DATE_ATOM);
    }

    /** @param array<string, mixed> $payload */
    private function writeAudit(int $staffId, string $action, int $targetId, array $payload): void
    {
        Db::name('audit_log')->insert([
            'actor_id' => $staffId,
            'action' => $action,
            'target_type' => 'payment_whitelist',
            'target_id' => $targetId,
            'payload_json' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }
}
