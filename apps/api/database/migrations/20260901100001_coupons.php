<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * 009-learner-coupons - coupon campaigns and learner instances.
 *
 * Tables:
 *  - coupon_campaigns:运营配置的活动模板(满减、范围、有效期、名额)。
 *  - coupon_campaign_categories / coupon_campaign_courses:适用范围 junction。
 *  - learner_coupons:学员持有实例,状态机驱动领取/锁定/核销/过期。
 *  - orders.learner_coupon_id + orders.coupon_discount_snapshot:订单扩展。
 *
 * 设计依据:specs/009-learner-coupons/{spec,plan,research,data-model}.md。
 *
 * Each table is gated on hasTable so the migration is idempotent and a
 * half-applied run can resume without dropping partial state.
 */
final class Coupons extends AbstractMigration
{
    public function up(): void
    {
        if (!$this->hasTable('coupon_campaigns')) {
            $this->table('coupon_campaigns', ['id' => false, 'primary_key' => ['id']])
                ->addColumn('id', 'biginteger', ['identity' => true, 'signed' => false])
                ->addColumn('name', 'string', ['limit' => 120])
                ->addColumn('scope_type', 'enum', ['values' => ['category', 'course', 'all']])
                ->addColumn('min_amount', 'decimal', ['precision' => 10, 'scale' => 2, 'default' => 0])
                ->addColumn('discount_amount', 'decimal', ['precision' => 10, 'scale' => 2, 'default' => 0])
                ->addColumn('claim_mode', 'enum', ['values' => ['public', 'admin_only']])
                ->addColumn('claim_starts_at', 'datetime')
                ->addColumn('claim_ends_at', 'datetime')
                ->addColumn('use_ends_at', 'datetime', ['null' => true])
                ->addColumn('total_quota', 'integer', ['signed' => false, 'null' => true])
                ->addColumn('claimed_count', 'integer', ['signed' => false, 'default' => 0])
                ->addColumn('used_count', 'integer', ['signed' => false, 'default' => 0])
                ->addColumn('per_learner_claim_limit', 'integer', ['signed' => false, 'default' => 1])
                ->addColumn('per_learner_use_limit', 'integer', ['signed' => false, 'default' => 1])
                ->addColumn('status', 'enum', ['values' => ['active', 'disabled'], 'default' => 'active'])
                ->addColumn('created_by', 'biginteger', ['signed' => false])
                ->addColumn('created_at', 'datetime')
                ->addColumn('updated_at', 'datetime')
                ->addForeignKey('created_by', 'staff_users', 'account_id', [
                    'delete' => 'RESTRICT',
                    'update' => 'CASCADE',
                ])
                ->addIndex(['status', 'claim_starts_at', 'claim_ends_at'], ['name' => 'idx_coupon_claimable'])
                ->addIndex(['created_at'], ['name' => 'idx_coupon_created_at'])
                ->create();
        }

        if (!$this->hasTable('coupon_campaign_categories')) {
            $this->table('coupon_campaign_categories', ['id' => false, 'primary_key' => ['campaign_id', 'category_id']])
                ->addColumn('campaign_id', 'biginteger', ['signed' => false, 'null' => false])
                ->addColumn('category_id', 'biginteger', ['signed' => false, 'null' => false])
                ->addForeignKey('campaign_id', 'coupon_campaigns', 'id', ['delete' => 'CASCADE', 'update' => 'CASCADE'])
                ->addForeignKey('category_id', 'categories', 'id', ['delete' => 'RESTRICT', 'update' => 'CASCADE'])
                ->create();
        }

        if (!$this->hasTable('coupon_campaign_courses')) {
            $this->table('coupon_campaign_courses', ['id' => false, 'primary_key' => ['campaign_id', 'course_id']])
                ->addColumn('campaign_id', 'biginteger', ['signed' => false, 'null' => false])
                ->addColumn('course_id', 'biginteger', ['signed' => false, 'null' => false])
                ->addForeignKey('campaign_id', 'coupon_campaigns', 'id', ['delete' => 'CASCADE', 'update' => 'CASCADE'])
                ->addForeignKey('course_id', 'courses', 'id', ['delete' => 'RESTRICT', 'update' => 'CASCADE'])
                ->create();
        }

        if (!$this->hasTable('learner_coupons')) {
            $this->table('learner_coupons', ['id' => false, 'primary_key' => ['id']])
                ->addColumn('id', 'biginteger', ['identity' => true, 'signed' => false])
                ->addColumn('campaign_id', 'biginteger', ['signed' => false])
                ->addColumn('learner_id', 'biginteger', ['signed' => false])
                ->addColumn('status', 'enum', [
                    'values' => ['unused', 'locked', 'used', 'expired', 'voided'],
                    'default' => 'unused',
                ])
                ->addColumn('source', 'enum', ['values' => ['claim', 'grant']])
                ->addColumn('granted_by', 'biginteger', ['signed' => false, 'null' => true])
                ->addColumn('locked_order_id', 'biginteger', ['signed' => false, 'null' => true])
                ->addColumn('used_order_id', 'biginteger', ['signed' => false, 'null' => true])
                ->addColumn('expires_at', 'datetime')
                ->addColumn('locked_at', 'datetime', ['null' => true])
                ->addColumn('used_at', 'datetime', ['null' => true])
                ->addColumn('created_at', 'datetime')
                ->addForeignKey('campaign_id', 'coupon_campaigns', 'id', ['delete' => 'RESTRICT', 'update' => 'CASCADE'])
                ->addForeignKey('learner_id', 'accounts', 'id', ['delete' => 'RESTRICT', 'update' => 'CASCADE'])
                ->addForeignKey('granted_by', 'staff_users', 'account_id', ['delete' => 'SET_NULL', 'update' => 'CASCADE'])
                ->addForeignKey('locked_order_id', 'orders', 'id', ['delete' => 'SET_NULL', 'update' => 'CASCADE'])
                ->addForeignKey('used_order_id', 'orders', 'id', ['delete' => 'SET_NULL', 'update' => 'CASCADE'])
                ->addIndex(['learner_id', 'status', 'expires_at'], ['name' => 'idx_learner_coupon_mine'])
                ->addIndex(['campaign_id', 'learner_id'], ['name' => 'idx_learner_coupon_campaign'])
                ->addIndex(['used_order_id'], ['unique' => true, 'name' => 'uniq_learner_coupon_used_order'])
                ->addIndex(['locked_order_id'], ['name' => 'idx_learner_coupon_locked'])
                ->create();
        }

        if ($this->hasTable('orders')) {
            $orders = $this->table('orders');
            $hasCouponId = $orders->hasColumn('learner_coupon_id');
            $hasDiscount = $orders->hasColumn('coupon_discount_snapshot');
            if (!$hasCouponId) {
                $orders->addColumn('learner_coupon_id', 'biginteger', ['signed' => false, 'null' => true, 'after' => 'course_id']);
            }
            if (!$hasDiscount) {
                $orders->addColumn('coupon_discount_snapshot', 'decimal', ['precision' => 10, 'scale' => 2, 'default' => 0, 'after' => 'sale_price_snapshot']);
            }
            $orders->update();
            if (!$hasCouponId) {
                $this->table('orders')->addForeignKey('learner_coupon_id', 'learner_coupons', 'id', ['delete' => 'SET_NULL', 'update' => 'CASCADE'])->update();
            }
        }
    }

    public function down(): void
    {
        if ($this->hasTable('orders') && $this->table('orders')->hasColumn('learner_coupon_id')) {
            $orders = $this->table('orders');
            $orders->dropForeignKey('learner_coupon_id');
            $orders->removeColumn('coupon_discount_snapshot');
            $orders->removeColumn('learner_coupon_id');
            $orders->update();
        }
        if ($this->hasTable('learner_coupons')) {
            $this->table('learner_coupons')->drop()->save();
        }
        if ($this->hasTable('coupon_campaign_courses')) {
            $this->table('coupon_campaign_courses')->drop()->save();
        }
        if ($this->hasTable('coupon_campaign_categories')) {
            $this->table('coupon_campaign_categories')->drop()->save();
        }
        if ($this->hasTable('coupon_campaigns')) {
            $this->table('coupon_campaigns')->drop()->save();
        }
    }
}