<template>
  <main class="page my-orders">
    <div class="list-head">
      <h2>我的订单</h2>
      <span v-if="!loading && !loadError" class="cnt">{{ items.length }} 笔</span>
    </div>

    <p v-if="loading" class="notice">正在加载订单…</p>
    <p v-else-if="loadError" class="notice error">订单暂时读不到，请稍后再试。</p>
    <div v-else-if="items.length === 0" class="empty">
      <span class="serif">还没有订单</span>
      购买收费课程后，这里会保留价格快照
    </div>
    <div v-else>
      <article v-for="order in items" :key="order.order_id" class="panel order-row">
        <div>
          <router-link :to="`/courses/${order.course_id}`" class="o-course">
            课程 #{{ order.course_id }}
          </router-link>
          <div class="o-snap">
            订单 {{ order.order_id }} · 标准价 ¥{{ order.list_price_snapshot.toFixed(2) }}
            <template v-if="order.sale_price_snapshot > 0">
              · 优惠价 ¥{{ order.sale_price_snapshot.toFixed(2) }}
            </template>
            · 实付 ¥{{ order.paid_amount.toFixed(2) }} · {{ formatDate(order.created_at) }}
          </div>
        </div>
        <div class="pay-state" :class="payStateClass(order.status)">
          {{ statusLabel(order.status) }}
          <router-link
            v-if="canRetry(order.status)"
            :to="`/checkout/${order.course_id}`"
            class="btn-link"
            style="display: block; margin-top: 6px"
          >
            {{ order.status === 'pending' ? '继续支付' : '重新购买' }}
          </router-link>
        </div>
      </article>
    </div>
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

function statusLabel(status: OrderStatus): string {
  switch (status) {
    case 'pending':
      return '支付处理中…';
    case 'succeeded':
      return '✓ 支付成功 · 已开通';
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

function payStateClass(status: OrderStatus): string {
  if (status === 'succeeded') return 's-ok';
  if (status === 'pending' || status === 'unknown') return 's-paying';
  return '';
}

function canRetry(status: OrderStatus): boolean {
  return status === 'pending' || status === 'failed' || status === 'cancelled' || status === 'unknown';
}

function formatDate(iso: string): string {
  const date = new Date(iso);
  if (Number.isNaN(date.getTime())) return iso;
  const pad = (value: number): string => value.toString().padStart(2, '0');
  return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())} ${pad(date.getHours())}:${pad(date.getMinutes())}`;
}

onMounted(refresh);
</script>
