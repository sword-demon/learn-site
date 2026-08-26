<template>
  <main class="page my-orders">
    <header class="head">
      <h1 class="display">我的订单</h1>
      <p class="lede">购买快照与支付状态. 只有自己创建的订单会列在这里.</p>
    </header>

    <p v-if="loading" class="notice">正在加载订单…</p>
    <p v-else-if="loadError" class="notice error">订单暂时读不到, 请稍后再试.</p>
    <p v-else-if="items.length === 0" class="empty">还没有订单.</p>
    <ul v-else class="order-list">
      <li v-for="order in items" :key="order.order_id" class="order-row" :data-status="order.status">
        <div class="info">
          <h2 class="title">
            <router-link :to="`/courses/${order.course_id}`">课程 #{{ order.course_id }}</router-link>
          </h2>
          <p class="meta">
            订单号 · {{ order.order_id }}
            <span class="dot">·</span>
            下单于 {{ formatDate(order.created_at) }}
          </p>
        </div>
        <div class="amount">
          <span class="now">¥ {{ order.paid_amount.toFixed(2) }}</span>
          <span v-if="order.sale_price_snapshot > 0" class="was">
            ¥ {{ order.list_price_snapshot.toFixed(2) }}
          </span>
        </div>
        <div class="status">
          <span class="tag" :data-status="order.status">{{ statusLabel(order.status) }}</span>
          <button
            v-if="order.status === 'pending'"
            type="button"
            class="btn btn-ghost"
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
import { onMounted, ref } from 'vue'
import type { OrderDTO, OrderStatus } from '@learn-site/contracts'
import { fetchOrders } from '@/api/learner'

defineOptions({ name: 'MyOrdersView' })

const items = ref<OrderDTO[]>([])
const loading = ref(true)
const loadError = ref(false)

async function refresh(): Promise<void> {
  loading.value = true
  loadError.value = false
  try {
    const result = await fetchOrders()
    items.value = result.items
  } catch {
    loadError.value = true
  } finally {
    loading.value = false
  }
}

async function simulate(orderId: number): Promise<void> {
  // The fake payment adapter is exercised by posting to the internal
  // /api/internal/v1/payments/fake/notify endpoint with the test-mode
  // header. This is the Phase 6 stand-in for a real WeChat Native
  // callback — the learner app can trigger it once to see the order
  // flip to succeeded and the entitlement land on the course.
  try {
    await fetch('/api/internal/v1/payments/fake/notify', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-Fake-Payment-Result': 'succeeded',
      },
      body: JSON.stringify({ order_id: orderId }),
    })
  } catch {
    /* surfaced on next refresh */
  }
  await refresh()
}

function statusLabel(s: OrderStatus): string {
  switch (s) {
    case 'pending':   return '待支付'
    case 'succeeded': return '已支付'
    case 'failed':    return '支付失败'
    case 'cancelled': return '已取消'
    case 'unknown':   return '待确认'
    default:          return s
  }
}

function formatDate(iso: string): string {
  const d = new Date(iso)
  if (Number.isNaN(d.getTime())) return iso
  const pad = (n: number): string => n.toString().padStart(2, '0')
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())} ${pad(d.getHours())}:${pad(d.getMinutes())}`
}

onMounted(refresh)
</script>

<style scoped>
.my-orders { display: grid; gap: 16px; }
.head .display { margin: 0 0 4px 0; }
.lede { color: var(--color-text-muted, #5b6472); margin: 0; }
.empty, .notice { color: var(--color-text-muted, #5b6472); }
.notice.error { color: #b42318; }
.order-list { list-style: none; padding: 0; margin: 0; display: grid; gap: 10px; }
.order-row {
  display: grid;
  grid-template-columns: 1fr auto auto;
  gap: 14px;
  align-items: center;
  border: 1px solid var(--color-border, #e3e6ee);
  border-radius: 10px;
  padding: 12px 14px;
  background: #fff;
}
.title { margin: 0 0 2px 0; font-size: 16px; }
.title a { color: inherit; text-decoration: none; }
.meta { margin: 0; color: var(--color-text-muted, #5b6472); font-size: 13px; }
.dot { margin: 0 6px; }
.amount { display: flex; flex-direction: column; align-items: flex-end; }
.now { font-size: 16px; font-weight: 600; }
.was { color: var(--color-text-muted, #5b6472); text-decoration: line-through; font-size: 12px; }
.status { display: flex; flex-direction: column; gap: 6px; align-items: flex-end; }
.tag {
  display: inline-block;
  padding: 2px 8px;
  border-radius: 4px;
  font-size: 12px;
  background: var(--color-bg-soft, #eef1f7);
  color: #5b6472;
}
.tag[data-status='pending']   { background: #fff1d6; color: #8a5a00; }
.tag[data-status='succeeded'] { background: #e7f6ec; color: #137a3c; }
.tag[data-status='failed']    { background: #fde7e7; color: #b42318; }
.tag[data-status='cancelled'] { background: var(--color-bg-soft, #eef1f7); color: #5b6472; }
.tag[data-status='unknown']   { background: #fff1d6; color: #8a5a00; }
.btn {
  display: inline-flex;
  align-items: center;
  padding: 4px 10px;
  border-radius: 4px;
  border: 1px solid transparent;
  font: inherit;
  cursor: pointer;
}
.btn-ghost { background: transparent; border-color: var(--color-border, #d0d4dc); color: inherit; }
</style>