# Data Model — 012 Z-Pay 接入

**Feature**: `012-zpay-payment-integration`
**Date**: 2026-09-04
**Status**: ✅ Schema 落定

---

## 1. 实体清单

| Entity | 用途 | 关系 |
|--------|------|------|
| `payment_config` | 商户支付配置单例 | 1 行（`id=1` 强约束） |
| `payment_whitelist` | 测试白名单条目（按手机号） | N 条，对应 `accounts.login` |

不修改既有 `orders` 表结构（仅 `provider` 字段从 `'fake'` 扩展为 `'fake'\|'zpay'`）。

---

## 2. `payment_config`

### 字段

| 字段 | 类型 | 约束 | 含义 |
|------|------|------|------|
| `id` | TINYINT UNSIGNED | PK, DEFAULT 1, CHECK (id=1) | 单例标识；强约束全表只有 1 行 |
| `api_url` | VARCHAR(255) | NOT NULL | z-pay 基础地址，默认 `https://z-pay.cn/` |
| `pid` | VARCHAR(64) | NOT NULL | 商户 ID |
| `merchant_key_cipher` | TEXT | NOT NULL | AES-256-GCM 密文，格式 `v1:<b64_iv>:<b64_cipher>:<b64_tag>` |
| `notify_url` | VARCHAR(255) | NOT NULL | 异步回调地址（z-pay 服务端推送） |
| `return_url` | VARCHAR(255) | NOT NULL | 同步回调地址（学员浏览器跳转） |
| `enabled_channels` | JSON | NOT NULL | 启用通道数组，如 `["wxpay","alipay"]` |
| `enabled` | TINYINT(1) | NOT NULL DEFAULT 0 | 总开关 |
| `whitelist_only` | TINYINT(1) | NOT NULL DEFAULT 0 | 白名单模式 |
| `version` | INT UNSIGNED | NOT NULL DEFAULT 1 | 乐观锁 |
| `created_at` | DATETIME | NULL | 首次创建时间 |
| `updated_at` | DATETIME | NULL | 最近一次更新 |
| `updated_by_staff_id` | BIGINT UNSIGNED | NULL | 最近一次更新人（FK → `staff_users.account_id`） |

### 约束

- 主键 + CHECK：`id = 1`（应用层 + 数据库层双重保护）
- 唯一：`id = 1`（主键即唯一）
- 外键：`updated_by_staff_id` → `staff_users(account_id)`，ON DELETE RESTRICT

### 状态

无显式状态字段。`enabled` 是布尔开关，不参与状态机。

---

## 3. `payment_whitelist`

### 字段

| 字段 | 类型 | 约束 | 含义 |
|------|------|------|------|
| `id` | BIGINT UNSIGNED | PK, AUTO_INCREMENT | 主键 |
| `phone` | VARCHAR(11) | NOT NULL, UNIQUE（与 deleted_at 组合） | 中国大陆手机号，精确匹配 `accounts.login` |
| `enabled` | TINYINT(1) | NOT NULL DEFAULT 1 | 是否启用 |
| `note` | VARCHAR(120) | NULL | 备注（如「运营测试账号」） |
| `created_by` | BIGINT UNSIGNED | NOT NULL, FK → `staff_users(account_id)` | 创建人 |
| `created_at` | DATETIME | NOT NULL | 创建时间 |
| `updated_at` | DATETIME | NOT NULL | 最近一次更新 |
| `deleted_at` | DATETIME | NULL | 软删时间；NULL 表示未删除 |
| `active_phone` | VARCHAR(11) | GENERATED | `deleted_at IS NULL` 时为 `phone`，否则为 NULL；仅用于数据库唯一约束 |

### 索引

- 主键：`id`
- 唯一索引：`(phone, deleted_at)` —— 同一手机号只能存在一条未删除记录；软删后允许重新添加
- 唯一索引：`active_phone` —— 补足 MySQL 对组合唯一索引中 NULL 值的语义限制
- 普通索引：`(enabled, deleted_at)` —— 学员侧白名单命中查询的热点

### 外键

- `created_by` → `staff_users(account_id)`，ON DELETE RESTRICT

### 状态

无显式状态机；`enabled` 是布尔开关；`deleted_at` 软删标记。

---

## 4. 关联图

```
┌──────────────────┐
│  payment_config  │  (id=1, singleton)
│  pid, key, urls  │
└──────────────────┘
        │
        │ (read by)
        ▼
┌──────────────────────────────────────────┐
│  OrderService::createPending             │
│   ├─→ PaymentConfigService.isWhitelisted │──┐
│   │   (if whitelist_only)                │  │
│   └─→ ZPayPaymentAdapter.createCharge    │  │
└──────────────────────────────────────────┘  │
                                              ▼
                                     ┌──────────────────┐
                                     │ payment_whitelist│
                                     │  (by phone)      │
                                     └──────────────────┘
                                              ▲
                                              │ (matched against)
                                              │
                                     ┌──────────────────┐
                                     │   accounts       │
                                     │  (login = phone) │
                                     └──────────────────┘
```

---

## 5. 迁移脚本骨架

```php
// apps/api/database/migrations/20260904000001_payment_config_and_whitelist.php
final class PaymentConfigAndWhitelist extends AbstractMigration
{
    public function up(): void
    {
        if (!$this->hasTable('payment_config')) {
            $this->table('payment_config', ['id' => false, 'primary_key' => ['id']])
                ->addColumn('id', 'integer', [
                    'limit' => \Phinx\Db\Adapter\MysqlAdapter::INT_TINY,
                    'default' => 1, 'null' => false,
                ])
                ->addColumn('api_url', 'string', ['limit' => 255, 'null' => false])
                ->addColumn('pid', 'string', ['limit' => 64, 'null' => false])
                ->addColumn('merchant_key_cipher', 'text', [
                    'limit' => \Phinx\Db\Adapter\MysqlAdapter::TEXT_MEDIUM,
                    'null' => false,
                ])
                ->addColumn('notify_url', 'string', ['limit' => 255, 'null' => false])
                ->addColumn('return_url', 'string', ['limit' => 255, 'null' => false])
                ->addColumn('enabled_channels', 'json', ['null' => false])
                ->addColumn('enabled', 'boolean', ['default' => false, 'null' => false])
                ->addColumn('whitelist_only', 'boolean', ['default' => false, 'null' => false])
                ->addColumn('version', 'integer', [
                    'limit' => \Phinx\Db\Adapter\MysqlAdapter::INT_REGULAR,
                    'default' => 1, 'null' => false, 'signed' => false,
                ])
                ->addColumn('created_at', 'datetime', ['null' => true])
                ->addColumn('updated_at', 'datetime', ['null' => true])
                ->addColumn('updated_by_staff_id', 'biginteger', [
                    'signed' => false, 'null' => true,
                ])
                ->addForeignKey('updated_by_staff_id', 'staff_users', 'account_id', [
                    'delete' => 'RESTRICT', 'update' => 'RESTRICT',
                    'constraint' => 'fk_payment_config_updated_by_staff',
                ])
                ->create();
            $this->execute(
                'ALTER TABLE payment_config '
                . 'ADD CONSTRAINT chk_payment_config_singleton CHECK (id = 1)'
            );
        }

        if (!$this->hasTable('payment_whitelist')) {
            $this->table('payment_whitelist', ['id' => false, 'primary_key' => ['id']])
                ->addColumn('id', 'biginteger', ['identity' => true, 'signed' => false])
                ->addColumn('phone', 'string', ['limit' => 11, 'null' => false])
                ->addColumn('enabled', 'boolean', ['default' => true, 'null' => false])
                ->addColumn('note', 'string', ['limit' => 120, 'null' => true])
                ->addColumn('created_by', 'biginteger', ['signed' => false, 'null' => false])
                ->addColumn('created_at', 'datetime', ['null' => false])
                ->addColumn('updated_at', 'datetime', ['null' => false])
                ->addColumn('deleted_at', 'datetime', ['null' => true])
                ->addForeignKey('created_by', 'staff_users', 'account_id', [
                    'delete' => 'RESTRICT', 'update' => 'CASCADE',
                    'constraint' => 'fk_payment_whitelist_created_by',
                ])
                ->addIndex(['phone', 'deleted_at'], [
                    'unique' => true,
                    'name' => 'uk_payment_whitelist_phone',
                ])
                ->addIndex(['enabled', 'deleted_at'], [
                    'name' => 'idx_payment_whitelist_enabled',
                ])
                ->create();
        }
    }

    public function down(): void
    {
        if ($this->hasTable('payment_whitelist')) {
            $this->table('payment_whitelist')->drop()->save();
        }
        if ($this->hasTable('payment_config')) {
            $this->table('payment_config')->drop()->save();
        }
    }
}
```

---

## 6. 现有表影响

| 表 | 影响 | 备注 |
|----|------|------|
| `orders` | 无 DDL 变化；运行时 `provider` 字段从 `'fake'` 扩展为 `'fake'\|'zpay'` | 字段 `varchar` 已足；语义在 `OrderService` 内体现 |
| `orders` 新增查询 | `provider='zpay' AND channel='wxpay'`（在 controller 输出层） | 不需要新字段；channel 从 `provider_ref` 前缀或额外 `meta_json` 字段读出（待 R009 决策敲定，落到 implementation tasks） |
| `accounts` | 无 DDL；白名单匹配 `accounts.login`（既有字段） | - |
| `staff_users` | 无 DDL；外键引用 `account_id` | - |
| `audit_log` | 无 DDL；写入既有 schema（actor_id / action / target_type / target_id / payload_json / created_at） | - |

> 备注：若实现期间发现 `orders.channel` 需要持久化（例如管理端订单列表按通道筛选），追加一次小迁移；当前不预设。
