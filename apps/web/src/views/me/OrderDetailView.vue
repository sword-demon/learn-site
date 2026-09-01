<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { useRoute } from 'vue-router';
import type { OrderDTO, OrderStatus } from '@learn-site/contracts';
import { fetchOrder } from '@/api/learner';

defineOptions({ name: 'OrderDetailView' });

const route = useRoute();
const order = ref<OrderDTO | null>(null);
const loading = ref(true);
const errorMessage = ref('');

const orderId = computed(() => Number(route.params.orderId));

function formatMoney(value: number): string {
  return value.toFixed(2);
}

function formatDate(value: string | null): string {
  if (!value) return '-';
  const date = new Date(value);
  if (Number.isNaN(date.getTime())) return value;
  return date.toLocaleString('zh-CN', {
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
    hour: '2-digit',
    minute: '2-digit',
  });
}

function statusLabel(status: OrderStatus): string {
  return {
    pending: '待支付',
    succeeded: '支付成功',
    failed: '支付失败',
    cancelled: '已取消',
    unknown: '待确认',
  }[status];
}

function statusClass(status: OrderStatus): string {
  if (status === 'succeeded') return 'is-success';
  if (status === 'pending' || status === 'unknown') return 'is-pending';
  return 'is-failed';
}

const actionLabel = computed(() => {
  if (!order.value) return '';
  if (order.value.status === 'pending') return '继续支付';
  if (order.value.status === 'failed' || order.value.status === 'cancelled') return '重新购买';
  return order.value.status === 'succeeded' ? '进入课程' : '';
});

const actionPath = computed(() => {
  if (!order.value) return '';
  return order.value.status === 'succeeded'
    ? `/courses/${order.value.course_id}`
    : `/checkout/${order.value.course_id}`;
});

async function load(): Promise<void> {
  loading.value = true;
  errorMessage.value = '';
  if (!Number.isInteger(orderId.value) || orderId.value <= 0) {
    errorMessage.value = '订单地址无效。';
    loading.value = false;
    return;
  }
  try {
    order.value = await fetchOrder(orderId.value);
  } catch (error) {
    order.value = null;
    errorMessage.value =
      (error as Error).message === 'ORDER_NOT_FOUND'
        ? '订单不存在或无权查看。'
        : '订单暂时读不到，请稍后再试。';
  } finally {
    loading.value = false;
  }
}

onMounted(load);
</script>

<template>
  <main class="page order-detail-page">
    <header class="order-detail-head">
      <router-link to="/me/orders" class="back-link">返回我的订单</router-link>
      <p class="eyebrow"><span class="eyebrow-rule" />订单记录</p>
      <h1 class="display">订单详情</h1>
      <p class="lede">查看本次购买的价格快照与支付结果。</p>
    </header>

    <el-skeleton v-if="loading" animated :rows="7" />
    <el-alert
      v-else-if="errorMessage"
      :title="errorMessage"
      type="error"
      :closable="false"
      show-icon
    />
    <section v-else-if="order" class="order-detail-panel" data-testid="order-detail">
      <div class="order-detail-summary">
        <div>
          <span class="detail-label">订单号</span>
          <strong>#{{ order.order_id }}</strong>
        </div>
        <span class="status" :class="statusClass(order.status)">{{
          statusLabel(order.status)
        }}</span>
      </div>

      <dl class="order-detail-grid">
        <div class="detail-item detail-item--course">
          <dt>课程</dt>
          <dd>
            <router-link :to="`/courses/${order.course_id}`">{{
              order.course_title || `课程 #${order.course_id}`
            }}</router-link>
          </dd>
        </div>
        <div class="detail-item">
          <dt>标准价</dt>
          <dd>¥ {{ formatMoney(order.list_price_snapshot) }}</dd>
        </div>
        <div class="detail-item">
          <dt>优惠价</dt>
          <dd>
            {{
              order.sale_price_snapshot > 0 ? `¥ ${formatMoney(order.sale_price_snapshot)}` : '-'
            }}
          </dd>
        </div>
        <div class="detail-item">
          <dt>优惠券抵扣</dt>
          <dd>¥ {{ formatMoney(order.coupon_discount_snapshot) }}</dd>
        </div>
        <div class="detail-item detail-item--total">
          <dt>实付金额</dt>
          <dd>¥ {{ formatMoney(order.paid_amount) }}</dd>
        </div>
        <div class="detail-item">
          <dt>创建时间</dt>
          <dd>{{ formatDate(order.created_at) }}</dd>
        </div>
        <div class="detail-item">
          <dt>支付完成时间</dt>
          <dd>{{ formatDate(order.succeeded_at) }}</dd>
        </div>
      </dl>

      <div v-if="actionLabel" class="order-detail-actions">
        <router-link :to="actionPath" class="action-primary">{{ actionLabel }}</router-link>
      </div>
    </section>
  </main>
</template>

<style scoped>
.order-detail-page {
  max-width: 920px;
  margin: 0 auto;
  padding: 40px 24px 64px;
}

.order-detail-head {
  margin-bottom: 24px;
}

.back-link {
  display: inline-block;
  margin-bottom: 24px;
  color: var(--muted);
  font-size: 0.9rem;
}

.order-detail-panel {
  padding: 28px;
  border: 1px solid var(--line, #d9e5df);
  border-radius: var(--r, 8px);
  background: var(--paper, #fff);
}

.order-detail-summary {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 16px;
  padding-bottom: 20px;
  border-bottom: 1px solid var(--line, #d9e5df);
}

.detail-label,
.detail-item dt {
  display: block;
  margin-bottom: 6px;
  color: var(--muted);
  font-size: 0.82rem;
}

.status {
  padding: 6px 10px;
  border-radius: 4px;
  font-size: 0.88rem;
  font-weight: 600;
}

.status.is-success {
  color: #2f6b46;
  background: #eaf5ed;
}

.status.is-pending {
  color: #8a6318;
  background: #fff6df;
}

.status.is-failed {
  color: #9a1f37;
  background: #fbecef;
}

.order-detail-grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 22px 32px;
  margin: 24px 0 0;
}

.detail-item {
  min-width: 0;
  margin: 0;
}

.detail-item dd {
  margin: 0;
  color: var(--ink, #21352b);
  font-size: 1rem;
}

.detail-item--course,
.detail-item--total {
  grid-column: 1 / -1;
}

.detail-item--total dd {
  color: var(--seal, #9a1f37);
  font-size: 1.35rem;
  font-weight: 700;
}

.order-detail-actions {
  display: flex;
  justify-content: flex-end;
  margin-top: 28px;
  padding-top: 20px;
  border-top: 1px solid var(--line, #d9e5df);
}

.action-primary {
  display: inline-flex;
  min-height: 40px;
  align-items: center;
  padding: 0 18px;
  border-radius: 4px;
  color: #fff;
  background: var(--seal, #9a1f37);
  text-decoration: none;
}

@media (max-width: 640px) {
  .order-detail-page {
    padding: 28px 16px 48px;
  }

  .order-detail-panel {
    padding: 20px;
  }

  .order-detail-grid {
    grid-template-columns: 1fr;
  }

  .detail-item--course,
  .detail-item--total {
    grid-column: auto;
  }

  .order-detail-actions {
    justify-content: stretch;
  }

  .action-primary {
    width: 100%;
    justify-content: center;
  }
}
</style>
