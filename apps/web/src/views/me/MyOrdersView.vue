<template>
  <main class="page my-orders account-page">
    <header class="account-head">
      <div>
        <p class="eyebrow"><span class="eyebrow-rule" />个人书架 · 订单记录</p>
        <h1 class="display">我的订单</h1>
        <p class="lede">购买快照与支付状态都保存在这里，只有自己创建的订单会显示。</p>
      </div>
      <p class="account-count">
        <strong>{{ items.length }}</strong> 笔
      </p>
    </header>

    <p v-if="loading" class="notice">正在加载订单…</p>
    <p v-else-if="loadError" class="notice error">订单暂时读不到，请稍后再试。</p>
    <p v-else-if="items.length === 0" class="empty">还没有订单。购买课程后，订单会出现在这里。</p>
    <ul v-else class="order-list">
      <li
        v-for="order in items"
        :key="order.order_id"
        class="order-row"
        :data-status="order.status"
      >
        <div class="order-mark" aria-hidden="true">
          {{ String(order.order_id).slice(-2).padStart(2, '0') }}
        </div>
        <div class="info">
          <p class="order-kicker">订单记录 · {{ order.provider }}</p>
          <h2 class="title">
            <router-link :to="`/courses/${order.course_id}`">
              课程 #{{ order.course_id }}
            </router-link>
          </h2>
          <p class="meta">
            订单号 {{ order.order_id }} · 下单于 {{ formatDate(order.created_at) }}
          </p>
        </div>
        <div class="amount">
          <span class="now">¥ {{ order.paid_amount.toFixed(2) }}</span>
          <span v-if="order.sale_price_snapshot > 0" class="was">¥ {{ order.list_price_snapshot.toFixed(2) }}</span>
        </div>
        <div class="status">
          <span class="tag" :data-status="order.status">{{ statusLabel(order.status) }}</span>
          <button
            v-if="order.status === 'pending'"
            type="button"
            class="text-button"
            @click="simulate(order.order_id)"
          >
            模拟支付
          </button>
        </div>
      </li>
    </ul>
  </main>
</template>

<script setup lang="ts">
import { onMounted, ref } from 'vue';
import type { OrderDTO, OrderStatus } from '@learn-site/contracts';
import { fetchOrders } from '@/api/learner';

defineOptions({ name: 'MyOrdersView' });

const items = ref<OrderDTO[]>([]);
const loading = ref(true);
const loadError = ref(false);

async function refresh(): Promise<void> {
  loading.value = true;
  loadError.value = false;
  try {
    const result = await fetchOrders();
    items.value = result.items;
  } catch {
    loadError.value = true;
  } finally {
    loading.value = false;
  }
}

async function simulate(orderId: number): Promise<void> {
  try {
    await fetch('/api/internal/v1/payments/fake/notify', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-Fake-Payment-Result': 'succeeded' },
      body: JSON.stringify({ order_id: orderId }),
    });
  } catch {
    // Refresh below surfaces the final order state.
  }
  await refresh();
}

function statusLabel(status: OrderStatus): string {
  switch (status) {
    case 'pending':
      return '待支付';
    case 'succeeded':
      return '已支付';
    case 'failed':
      return '支付失败';
    case 'cancelled':
      return '已取消';
    case 'unknown':
      return '待确认';
    default:
      return status;
  }
}

function formatDate(iso: string): string {
  const date = new Date(iso);
  if (Number.isNaN(date.getTime())) return iso;
  const pad = (value: number): string => value.toString().padStart(2, '0');
  return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())} ${pad(date.getHours())}:${pad(date.getMinutes())}`;
}

onMounted(refresh);
</script>

<style scoped>
.account-page {
  display: grid;
  gap: 28px;
}
.account-head {
  display: flex;
  align-items: end;
  justify-content: space-between;
  gap: 20px;
  padding: 18px 0 26px;
  border-bottom: 1px solid var(--line);
}
.account-head .eyebrow {
  margin-bottom: 16px;
}
.account-head .display {
  margin: 0 0 9px;
  color: var(--pine-deep);
}
.account-count {
  flex-shrink: 0;
  margin: 0 0 4px;
  color: var(--muted);
  font-size: 0.8rem;
}
.account-count strong {
  color: var(--accent);
  font: 700 1.5rem var(--font-mono);
}
.order-list {
  display: grid;
  gap: 10px;
  margin: 0;
  padding: 0;
  list-style: none;
}
.order-row {
  display: grid;
  grid-template-columns: 42px minmax(0, 1fr) auto auto;
  gap: 16px;
  align-items: center;
  padding: 17px 16px;
  border: 1px solid var(--line);
  border-left: 3px solid var(--pine);
  background: var(--surface);
}
.order-row[data-status='pending'] {
  border-left-color: var(--accent);
}
.order-mark {
  display: grid;
  width: 40px;
  height: 40px;
  place-items: center;
  border: 1px solid var(--line);
  color: var(--accent);
  font: 700 0.72rem var(--font-mono);
}
.info {
  min-width: 0;
}
.order-kicker {
  margin: 0 0 4px;
  color: var(--accent);
  font: 700 0.67rem var(--font-mono);
  letter-spacing: 0.05em;
}
.title {
  margin: 0 0 4px;
  font-size: 1rem;
}
.title a {
  color: var(--pine-deep);
  text-decoration: none;
}
.title a:hover {
  color: var(--accent);
}
.meta {
  margin: 0;
  overflow: hidden;
  color: var(--muted);
  font-size: 0.75rem;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.amount {
  display: flex;
  flex-direction: column;
  align-items: end;
}
.now {
  color: var(--ink);
  font: 700 0.95rem var(--font-mono);
}
.was {
  color: var(--muted);
  font: 0.7rem var(--font-mono);
  text-decoration: line-through;
}
.status {
  display: flex;
  min-width: 66px;
  flex-direction: column;
  align-items: end;
  gap: 6px;
}
.tag[data-status='pending'],
.tag[data-status='unknown'] {
  border-color: #e2c38f;
  background: #fff7e5;
  color: #8b5b13;
}
.tag[data-status='succeeded'] {
  border-color: #bad4c1;
  background: #eef7f0;
  color: var(--pine-deep);
}
.tag[data-status='failed'] {
  border-color: #e7b8ab;
  background: #fff5f1;
  color: #9e3f2c;
}
.tag[data-status='cancelled'] {
  color: var(--muted);
}
.text-button {
  padding: 0;
  border: 0;
  background: transparent;
  color: var(--accent);
  font: inherit;
  font-size: 0.74rem;
  cursor: pointer;
  white-space: nowrap;
}
@media (max-width: 700px) {
  .order-row {
    grid-template-columns: 40px minmax(0, 1fr) auto;
  }
  .status {
    grid-column: 2 / -1;
    align-items: start;
    flex-direction: row;
  }
}
@media (max-width: 560px) {
  .account-head {
    align-items: start;
    flex-direction: column;
    gap: 8px;
  }
  .order-row {
    grid-template-columns: 36px minmax(0, 1fr);
    gap: 12px;
  }
  .order-mark {
    width: 34px;
    height: 34px;
  }
  .amount {
    grid-column: 2;
    align-items: start;
  }
  .status {
    grid-column: 2;
  }
}
</style>
