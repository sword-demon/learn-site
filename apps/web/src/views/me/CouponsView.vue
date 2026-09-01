<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue';
import { ElMessage } from 'element-plus';
import type { CouponPublicDTO, LearnerCouponDTO } from '@learn-site/contracts';
import {
  claimCoupon,
  fetchClaimableCoupons,
  fetchMyCoupons,
  type MyCouponsParams,
} from '@/api/coupons';

defineOptions({ name: 'CouponsView' });

type Tab = 'claimable' | 'mine';
type MineStatus = '' | 'unused' | 'used' | 'expired';

const tab = ref<Tab>('claimable');
const loading = ref(false);
const errorMessage = ref('');
const claimable = ref<CouponPublicDTO[]>([]);
const mineItems = ref<LearnerCouponDTO[]>([]);
const mineTotal = ref(0);
const claimingId = ref<number | null>(null);

const mineFilters = reactive<{ status: MineStatus; page: number; limit: number }>({
  status: '',
  page: 1,
  limit: 20,
});

const mineQuery = computed<MyCouponsParams>(() => ({
  page: mineFilters.page,
  limit: mineFilters.limit,
  status: mineFilters.status === '' ? undefined : mineFilters.status,
}));

async function loadClaimable(): Promise<void> {
  loading.value = true;
  errorMessage.value = '';
  try {
    claimable.value = await fetchClaimableCoupons();
  } catch (err) {
    errorMessage.value = err instanceof Error ? err.message : '加载失败';
  } finally {
    loading.value = false;
  }
}

async function loadMine(): Promise<void> {
  loading.value = true;
  errorMessage.value = '';
  try {
    const res = await fetchMyCoupons(mineQuery.value);
    mineItems.value = res.items;
    mineTotal.value = res.total;
  } catch (err) {
    errorMessage.value = err instanceof Error ? err.message : '加载失败';
  } finally {
    loading.value = false;
  }
}

async function load(): Promise<void> {
  if (tab.value === 'claimable') {
    await loadClaimable();
  } else {
    await loadMine();
  }
}

async function onClaim(row: CouponPublicDTO): Promise<void> {
  claimingId.value = row.id;
  try {
    const item = await claimCoupon(row.id);
    ElMessage.success(`已领取「${item.name}」`);
    await load();
  } catch (err) {
    const msg = err instanceof Error ? err.message : '领取失败';
    ElMessage.error(humanizeError(msg));
  } finally {
    claimingId.value = null;
  }
}

function onTabChange(next: Tab): void {
  tab.value = next;
  mineFilters.page = 1;
  void load();
}

function onStatusFilter(): void {
  mineFilters.page = 1;
  void loadMine();
}

function humanizeError(code: string): string {
  const map: Record<string, string> = {
    COUPON_NOT_CLAIMABLE: '当前不在领取期或未开放公开领取',
    COUPON_QUOTA_EXCEEDED: '已被领完',
    COUPON_CLAIM_LIMIT_EXCEEDED: '已超过每人限领次数',
    COUPON_ALREADY_CLAIMED: '已领取过该券',
  };
  return map[code] ?? '领取失败';
}

function statusTag(coupon: LearnerCouponDTO): {
  label: string;
  type: 'success' | 'info' | 'warning' | 'danger';
} {
  const map: Record<
    LearnerCouponDTO['status'],
    { label: string; type: 'success' | 'info' | 'warning' | 'danger' }
  > = {
    unused: { label: '未使用', type: 'success' },
    locked: { label: '已锁定', type: 'warning' },
    used: { label: '已使用', type: 'info' },
    expired: { label: '已过期', type: 'warning' },
    voided: { label: '已失效', type: 'danger' },
  };
  return map[coupon.status];
}

function formatDate(iso: string): string {
  return iso.replace('T', ' ').slice(0, 16);
}

function isExpiringSoon(coupon: LearnerCouponDTO): boolean {
  if (coupon.status !== 'unused') return false;
  const expiry = new Date(coupon.expires_at).getTime();
  const now = Date.now();
  return expiry - now < 7 * 86_400_000;
}

onMounted(load);
</script>

<template>
  <main class="page coupons-page" data-view="coupons">
    <header class="head">
      <p class="eyebrow"><span class="eyebrow-rule" />学员中心 · 优惠券</p>
      <h1 class="display">我的优惠券</h1>
      <p class="lede">公开领取的活动券会出现在领取中心；已持有优惠券按状态展示。</p>
    </header>

    <el-tabs v-model="tab" class="coupons-page__tabs" @tab-change="onTabChange">
      <el-tab-pane label="领取中心" name="claimable" />
      <el-tab-pane label="我的优惠券" name="mine" />
    </el-tabs>

    <el-alert
      v-if="errorMessage"
      :title="errorMessage"
      type="error"
      :closable="false"
      show-icon
      class="coupons-page__alert"
    />

    <section v-if="tab === 'claimable'" data-tab="claimable">
      <el-skeleton v-if="loading" animated :rows="4" />
      <el-empty v-else-if="claimable.length === 0" description="暂无可领取的活动" />
      <ul v-else class="coupon-list">
        <li v-for="row in claimable" :key="row.id" class="coupon-card" data-testid="claimable-card">
          <div class="coupon-card__value" aria-label="优惠金额">
            <strong>{{ row.discount_amount }}</strong>
            <span>立减</span>
          </div>
          <div class="coupon-card__details">
            <div class="coupon-card__heading">
              <h3 class="coupon-card__name">{{ row.name }}</h3>
              <p class="coupon-card__rule">满 {{ row.min_amount }} 减 {{ row.discount_amount }}</p>
            </div>
            <p class="coupon-card__scope">适用范围：{{ row.scope_summary }}</p>
            <p class="coupon-card__meta">
              领取截止：{{ formatDate(row.claim_ends_at) }}
              <span v-if="row.remaining_quota !== null">· 剩余 {{ row.remaining_quota }} 张</span>
            </p>
          </div>
          <el-button
            type="primary"
            :loading="claimingId === row.id"
            data-action="claim"
            @click="onClaim(row)"
          >
            立即领取
          </el-button>
        </li>
      </ul>
    </section>

    <section v-else data-tab="mine">
      <div class="coupons-page__filters">
        <el-select
          v-model="mineFilters.status"
          placeholder="状态"
          clearable
          @change="onStatusFilter"
        >
          <el-option label="未使用" value="unused" />
          <el-option label="已使用" value="used" />
          <el-option label="已过期" value="expired" />
        </el-select>
      </div>
      <el-skeleton v-if="loading" animated :rows="4" />
      <el-empty v-else-if="mineItems.length === 0" description="暂无优惠券" />
      <ul v-else class="coupon-list">
        <li
          v-for="row in mineItems"
          :key="row.id"
          class="coupon-card coupon-card--mine"
          data-testid="mine-card"
        >
          <div class="coupon-card__value" aria-label="优惠金额">
            <strong>{{ row.discount_amount }}</strong>
            <span>立减</span>
          </div>
          <div class="coupon-card__details">
            <div class="coupon-card__heading">
              <h3 class="coupon-card__name">{{ row.name }}</h3>
              <p class="coupon-card__rule">满 {{ row.min_amount }} 减 {{ row.discount_amount }}</p>
            </div>
            <p class="coupon-card__scope">适用范围：{{ row.scope_summary }}</p>
            <p class="coupon-card__meta">使用截止：{{ formatDate(row.expires_at) }}</p>
            <p v-if="isExpiringSoon(row)" class="coupon-card__warning">即将过期，请尽快使用</p>
          </div>
          <el-tag :type="statusTag(row).type" effect="plain" class="coupon-card__status">
            {{ statusTag(row).label }}
          </el-tag>
        </li>
      </ul>
      <el-pagination
        v-model:current-page="mineFilters.page"
        v-model:page-size="mineFilters.limit"
        :total="mineTotal"
        :page-sizes="[10, 20, 50]"
        layout="total, sizes, prev, pager, next"
        class="coupons-page__pager"
        data-action="mine-pager"
        @current-change="loadMine"
        @size-change="loadMine"
      />
    </section>
  </main>
</template>

<style scoped>
.coupons-page {
  min-width: 0;
}
.coupons-page__tabs {
  margin-bottom: 16px;
}
.coupons-page__alert {
  margin: 0 0 16px;
}
.coupons-page__filters {
  display: flex;
  justify-content: flex-end;
  margin-bottom: 12px;
}
.coupons-page__pager {
  justify-content: flex-end;
  margin-top: 16px;
}
.coupon-list {
  list-style: none;
  margin: 0;
  padding: 0;
  display: grid;
  gap: 16px;
}
.coupon-card {
  display: grid;
  grid-template-columns: 96px minmax(0, 1fr) auto;
  align-items: center;
  gap: 20px;
  padding: 16px;
  border: 1px solid var(--line);
  border-radius: var(--r);
  background: var(--card);
  box-shadow: var(--shadow);
  transition:
    background-color 0.15s ease,
    box-shadow 0.15s ease;
}
.coupon-card:hover {
  background: var(--paper-2);
  box-shadow: var(--shadow-lg);
}
.coupon-card__value {
  display: flex;
  min-width: 0;
  min-height: 64px;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  padding-right: 20px;
  border-right: 1px dashed var(--line-2);
  color: var(--seal);
}
.coupon-card__value strong {
  font-family: var(--font-display);
  font-size: 28px;
  line-height: 1;
}
.coupon-card__value span {
  margin-top: 6px;
  color: var(--ink-2);
  font-size: 12px;
}
.coupon-card__details {
  display: grid;
  min-width: 0;
  gap: 4px;
}
.coupon-card__heading {
  display: flex;
  min-width: 0;
  align-items: baseline;
  gap: 10px;
  flex-wrap: wrap;
}
.coupon-card__name {
  margin: 0;
  font-size: 16px;
  line-height: 1.4;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.coupon-card__rule {
  margin: 0;
  font-size: 13px;
  color: var(--seal);
  font-weight: 600;
}
.coupon-card__scope,
.coupon-card__meta {
  margin: 0;
  font-size: 12px;
  color: var(--ink-2);
  line-height: 1.5;
}
.coupon-card__warning {
  margin: 0;
  font-size: 12px;
  color: var(--gold);
}
.coupon-card__status {
  flex: none;
}
.coupon-card :deep(.el-button) {
  min-width: 88px;
}

@media (max-width: 640px) {
  .coupon-card {
    grid-template-columns: 72px minmax(0, 1fr);
    gap: 12px;
  }

  .coupon-card__value {
    min-height: 56px;
    padding-right: 12px;
  }

  .coupon-card__value strong {
    font-size: 24px;
  }

  .coupon-card > .el-button,
  .coupon-card > .coupon-card__status {
    grid-column: 2;
    justify-self: start;
  }

  .coupon-card__name {
    white-space: normal;
  }
}

@media (max-width: 420px) {
  .coupon-card {
    padding: 14px;
  }

  .coupon-card__heading {
    display: grid;
    gap: 3px;
  }
}
</style>
