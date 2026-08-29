<template>
  <main class="page checkout-page">
    <header class="checkout-head">
      <p class="eyebrow"><span class="eyebrow-rule" />订单确认 · 学员入口</p>
      <h1 class="display">确认课程订单</h1>
      <p class="lede">价格在提交瞬间再次确认，支付完成前不会开通课程访问权。</p>
    </header>

    <p v-if="loading" class="notice">正在读取课程价格…</p>
    <p v-else-if="error" class="notice error" role="alert">{{ error }}</p>
    <section v-else-if="course" class="checkout-layout">
      <article class="course-summary">
        <p class="eyebrow"><span class="eyebrow-rule" />购买内容</p>
        <h2 class="section-title display">{{ course.course.title }}</h2>
        <p class="teacher">讲师 · {{ course.course.teacher_name }}</p>
        <p class="summary">{{ course.course.summary }}</p>
        <p class="price">
          <strong>¥ {{ formatPrice(currentPrice) }}</strong>
          <del v-if="course.course.sale_price > 0"
            >¥ {{ formatPrice(course.course.list_price) }}</del
          >
        </p>
      </article>

      <section class="payment-panel" aria-live="polite">
        <template v-if="!order">
          <h2 class="panel-title">支付方式</h2>
          <p class="panel-copy">Fake 支付仅用于本地验收，订单仍由服务端异步结算。</p>
          <button type="button" class="btn btn-primary" :disabled="submitting" @click="submitOrder">
            {{ submitting ? '创建订单中…' : '创建支付订单' }}
          </button>
        </template>
        <template v-else>
          <h2 class="panel-title">{{ statusLabel(order.status) }}</h2>
          <p v-if="order.status === 'pending'" class="panel-copy">
            请使用支付二维码完成付款，页面会自动刷新状态。
          </p>
          <p v-else-if="order.status === 'succeeded'" class="panel-copy success">
            课程访问权已开通。
          </p>
          <p v-else class="panel-copy error">本次订单未完成支付，可以重新确认价格后再试。</p>
          <div v-if="payment" class="code-box">
            <code>{{ payment.code_url }}</code>
          </div>
          <p class="order-meta">订单 #{{ order.order_id }}</p>
          <div class="panel-actions">
            <router-link
              v-if="order.status === 'succeeded'"
              :to="`/courses/${order.course_id}`"
              class="btn btn-primary"
            >
              进入课程
            </router-link>
            <button
              v-else
              type="button"
              class="btn btn-ghost"
              :disabled="refreshing"
              @click="refreshOrder"
            >
              刷新状态
            </button>
            <router-link to="/me/orders" class="btn btn-link">查看订单记录</router-link>
          </div>
        </template>
      </section>
    </section>
  </main>
</template>

<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref } from 'vue';
import { useRoute } from 'vue-router';
import type {
  CreateOrderResponseDTO,
  OrderDTO,
  PublicCourseDetailDTO,
} from '@learn-site/contracts';
import { createCourseOrder, fetchCourseDetail, fetchOrder } from '@/api/learner';

defineOptions({ name: 'CheckoutView' });

const route = useRoute();
const course = ref<PublicCourseDetailDTO | null>(null);
const order = ref<OrderDTO | null>(null);
const payment = ref<CreateOrderResponseDTO['payment'] | null>(null);
const loading = ref(true);
const submitting = ref(false);
const refreshing = ref(false);
const error = ref('');
let pollTimer: number | null = null;

const courseId = computed(() => Number(route.params.courseId));
const currentPrice = computed(() => {
  const value = course.value?.course;
  if (!value) return 0;
  return value.sale_price > 0 ? value.sale_price : value.list_price;
});

function formatPrice(value: number): string {
  return value.toFixed(2);
}

function statusLabel(status: OrderDTO['status']): string {
  return {
    pending: '等待支付',
    succeeded: '支付成功',
    failed: '支付失败',
    cancelled: '订单已取消',
    unknown: '支付状态待确认',
  }[status];
}

async function submitOrder(): Promise<void> {
  submitting.value = true;
  error.value = '';
  try {
    const created = await createCourseOrder(courseId.value);
    payment.value = created.payment;
    await refreshOrderById(created.order_id);
    startPolling();
  } catch (err) {
    error.value =
      err instanceof Error && err.message === 'SALE_WINDOW_EXPIRED'
        ? '优惠已结束，请返回课程页刷新价格。'
        : '订单创建失败，请稍后再试。';
  } finally {
    submitting.value = false;
  }
}

async function refreshOrderById(id: number): Promise<void> {
  order.value = await fetchOrder(id);
  if (order.value.status !== 'pending') stopPolling();
}

async function refreshOrder(): Promise<void> {
  if (!order.value) return;
  refreshing.value = true;
  try {
    await refreshOrderById(order.value.order_id);
  } catch {
    error.value = '订单状态暂时读不到，请稍后再试。';
  } finally {
    refreshing.value = false;
  }
}

function startPolling(): void {
  stopPolling();
  pollTimer = window.setInterval(() => void refreshOrder(), 2000);
}

function stopPolling(): void {
  if (pollTimer !== null) {
    window.clearInterval(pollTimer);
    pollTimer = null;
  }
}

onMounted(async () => {
  if (!Number.isInteger(courseId.value) || courseId.value <= 0) {
    error.value = '课程地址无效。';
    loading.value = false;
    return;
  }
  try {
    course.value = await fetchCourseDetail(courseId.value);
  } catch {
    error.value = '课程不存在或已下架。';
  } finally {
    loading.value = false;
  }
});

onUnmounted(stopPolling);
</script>

<style scoped>
.checkout-page {
  display: grid;
  gap: 28px;
}
.checkout-head {
  padding: 18px 0 26px;
  border-bottom: 1px solid var(--line);
}
.checkout-head .display {
  margin: 0 0 9px;
  color: var(--pine-deep);
}
.checkout-layout {
  display: grid;
  grid-template-columns: minmax(0, 1.15fr) minmax(280px, 0.85fr);
  gap: 18px;
}
.course-summary,
.payment-panel {
  padding: 24px;
  border: 1px solid var(--line);
  background: var(--surface);
}
.section-title {
  margin: 0 0 8px;
  color: var(--pine-deep);
  font-size: 1.5rem;
}
.teacher,
.summary,
.panel-copy,
.order-meta {
  color: var(--muted);
  font-size: 0.85rem;
  line-height: 1.65;
}
.price {
  display: flex;
  align-items: baseline;
  gap: 10px;
  margin: 22px 0 0;
}
.price strong {
  color: var(--accent);
  font: 700 1.65rem var(--font-mono);
}
.price del {
  color: var(--muted);
  font: 0.8rem var(--font-mono);
}
.payment-panel {
  display: grid;
  align-content: start;
  gap: 14px;
}
.panel-title {
  margin: 0;
  color: var(--pine-deep);
  font-size: 1.1rem;
}
.panel-copy {
  margin: 0;
}
.success {
  color: var(--pine-deep);
}
.error {
  color: #9e3f2c;
}
.code-box {
  padding: 12px;
  overflow: auto;
  border: 1px dashed var(--line);
  background: var(--surface-muted);
  color: var(--pine-deep);
  font-size: 0.75rem;
}
.order-meta {
  margin: 0;
  font-family: var(--font-mono);
}
.panel-actions {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
}
@media (max-width: 700px) {
  .checkout-layout {
    grid-template-columns: 1fr;
  }
}
</style>
