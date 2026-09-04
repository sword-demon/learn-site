<template>
  <main class="page checkout" :data-course-id="id">
    <header class="checkout__head">
      <p class="checkout__eyebrow"><span class="checkout__rule" />订单确认 · 学员入口</p>
      <h1 class="checkout__title">确认课程订单</h1>
      <p class="checkout__lede">价格在提交瞬间再次确认,支付完成前不会开通课程访问权。</p>
    </header>

    <el-skeleton v-if="loading" animated :rows="6" />
    <div v-else-if="loadError" class="checkout__error">
      <el-alert :title="loadError" type="error" :closable="false" show-icon />
      <el-button data-action="retry" @click="load">重新加载</el-button>
    </div>

    <section v-else-if="course" class="checkout__grid">
      <article class="checkout-summary">
        <p class="checkout__eyebrow"><span class="checkout__rule" />购买内容</p>
        <h2 class="checkout-summary__title">《{{ course.course.title }}》</h2>
        <p class="checkout-summary__teacher">讲师 · {{ course.course.teacher_name }}</p>
        <p v-if="course.course.summary" class="checkout-summary__copy">
          {{ course.course.summary }}
        </p>

        <ul class="checkout-summary__price-list">
          <li class="checkout-summary__row">
            <span>课程原价</span>
            <span>¥ {{ formatPrice(course.course.list_price) }}</span>
          </li>
          <li v-if="discountAmount > 0" class="checkout-summary__row checkout-summary__row--disc">
            <span>限时优惠</span>
            <span>− ¥ {{ formatPrice(discountAmount) }}</span>
          </li>
          <li v-if="couponDiscount > 0" class="checkout-summary__row checkout-summary__row--coupon">
            <span>优惠券 ({{ selectedCouponName }})</span>
            <span>− ¥ {{ formatPrice(couponDiscount) }}</span>
          </li>
          <li class="checkout-summary__row checkout-summary__row--total">
            <span>应付金额</span>
            <b>¥ {{ formatPrice(payable) }}</b>
          </li>
        </ul>

        <p class="checkout-summary__refund">本课程为虚拟内容,购买成功后不支持退款</p>
      </article>

      <section class="checkout-payment" aria-live="polite">
        <template v-if="!order">
          <h2 class="checkout-payment__h">选择支付方式</h2>

          <el-radio-group
            v-model="paymentMethod"
            class="checkout-payment__methods"
            aria-label="支付方式"
          >
            <el-radio
              value="wechat"
              class="checkout-payment__method"
              data-action="pay-wechat"
              data-method="wechat"
              :disabled="!channelEnabled('wxpay')"
            >
              <span
                class="checkout-payment__method-icon checkout-payment__method-icon--wechat"
                aria-hidden="true"
                >微</span
              >
              <span class="checkout-payment__method-label">微信支付</span>
            </el-radio>
            <el-radio
              value="alipay"
              class="checkout-payment__method"
              data-action="pay-alipay"
              data-method="alipay"
              :disabled="!channelEnabled('alipay')"
            >
              <span
                class="checkout-payment__method-icon checkout-payment__method-icon--alipay"
                aria-hidden="true"
                >支</span
              >
              <span class="checkout-payment__method-label">支付宝</span>
            </el-radio>
          </el-radio-group>

          <div class="checkout-payment__coupons">
            <p class="checkout-payment__coupons-label">使用优惠券</p>
            <el-skeleton v-if="couponsLoading" animated :rows="2" />
            <el-radio-group
              v-else
              v-model="selectedCouponId"
              class="checkout-payment__coupon-list"
              data-testid="coupon-options"
            >
              <el-radio
                :value="null"
                class="checkout-payment__coupon-option"
                data-action="no-coupon"
              >
                <span class="checkout-payment__coupon-label">不使用</span>
              </el-radio>
              <el-radio
                v-for="opt in couponOptions"
                :key="opt.id"
                :value="opt.id"
                :disabled="!opt.eligible"
                class="checkout-payment__coupon-option"
                :data-action="`coupon-${opt.id}`"
                :data-eligible="opt.eligible ? '1' : '0'"
              >
                <div class="checkout-payment__coupon-row">
                  <span class="checkout-payment__coupon-label">
                    {{ opt.name }} · 满 {{ opt.min_amount }} 减 {{ opt.discount_amount }}
                  </span>
                  <span
                    v-if="!opt.eligible && opt.ineligible_reason"
                    class="checkout-payment__coupon-reason"
                  >
                    {{ humanizeCouponReason(opt.ineligible_reason) }}
                  </span>
                </div>
              </el-radio>
            </el-radio-group>
            <p
              v-if="couponOptions.length === 0 && !couponsLoading"
              class="checkout-payment__coupons-empty"
            >
              当前课程暂无可用优惠券
            </p>
          </div>

          <el-checkbox v-model="agreed" class="checkout-payment__agree">
            我已阅读并同意
            <router-link to="/terms" target="_blank" class="checkout-payment__terms"
              >《用户协议》</router-link
            >
            与
            <router-link to="/refund" target="_blank" class="checkout-payment__terms"
              >《退款说明》</router-link
            >
          </el-checkbox>

          <el-button
            type="primary"
            size="large"
            class="checkout-payment__submit"
            data-action="create-order"
            :icon="ShoppingCart"
            :loading="submitting"
            :disabled="
              !agreed ||
              submitting ||
              !paymentOptions.enabled ||
              paymentOptions.enabled_channels.length === 0
            "
            @click="submitOrder"
          >
            {{ submitLabel }}
          </el-button>
          <p v-if="submitError" class="checkout-payment__error" role="alert">{{ submitError }}</p>
        </template>

        <template v-else>
          <h2 class="checkout-payment__h">{{ statusLabel(order.status) }}</h2>
          <p v-if="order.status === 'pending'" class="checkout-payment__copy">
            请使用{{ paymentMethodLabel }}完成付款,页面会自动刷新状态。
          </p>
          <p
            v-else-if="order.status === 'succeeded'"
            class="checkout-payment__copy checkout-payment__copy--ok"
          >
            课程访问权已开通。
          </p>
          <p v-else class="checkout-payment__copy checkout-payment__copy--err">
            本次订单未完成支付,可以重新确认价格后再试。
          </p>
          <div v-if="payment" class="checkout-payment__code-box">
            <a
              v-if="payment.redirect_url"
              :href="payment.redirect_url"
              target="_blank"
              rel="noreferrer"
            >
              打开支付页面
            </a>
            <code v-else>{{ payment.code_url }}</code>
          </div>
          <p class="checkout-payment__meta">订单 #{{ order.order_id }}</p>
          <div class="checkout-payment__actions">
            <router-link
              v-if="order.status === 'succeeded'"
              :to="`/courses/${order.course_id}`"
              class="checkout-payment__primary"
            >
              进入课程
            </router-link>
            <el-button
              v-else
              data-action="refresh-order"
              :icon="Refresh"
              :loading="refreshing"
              @click="refreshOrder"
            >
              刷新状态
            </el-button>
            <router-link to="/me/orders" class="checkout-payment__link">查看订单记录</router-link>
          </div>
        </template>
      </section>
    </section>
  </main>
</template>

<script setup lang="ts">
import { computed, onUnmounted, ref, watch } from 'vue';
import { useRoute } from 'vue-router';
import { Refresh, ShoppingCart } from '@element-plus/icons-vue';
import type {
  CreateOrderResponseDTO,
  OrderDTO,
  PaymentChannel,
  PaymentOptionsDTO,
  PublicCourseDetailDTO,
} from '@learn-site/contracts';
import {
  createCourseOrder,
  fetchCourseDetail,
  fetchOrder,
  fetchPaymentOptions,
} from '@/api/learner';
import { fetchCheckoutCoupons, type CheckoutCouponsResult } from '@/api/coupons';

defineOptions({ name: 'CheckoutView' });

type PaymentMethod = 'wechat' | 'alipay';
type CouponOption = CheckoutCouponsResult['items'][number];

const route = useRoute();
const id = computed(() => Number(route.params.courseId));
const course = ref<PublicCourseDetailDTO | null>(null);
const order = ref<OrderDTO | null>(null);
const payment = ref<CreateOrderResponseDTO['payment'] | null>(null);
const loading = ref(true);
const loadError = ref('');
const submitting = ref(false);
const refreshing = ref(false);
const submitError = ref('');
const paymentMethod = ref<PaymentMethod>('wechat');
const paymentOptions = ref<PaymentOptionsDTO>({ enabled: false, enabled_channels: [] });
const agreed = ref(false);
const couponsLoading = ref(false);
const couponOptions = ref<CouponOption[]>([]);
const basePrice = ref(0);
const selectedCouponId = ref<number | null>(null);

let pollTimer: number | null = null;

async function loadCourse(): Promise<void> {
  if (!Number.isFinite(id.value) || id.value <= 0) {
    loadError.value = '课程地址无效。';
    loading.value = false;
    return;
  }
  loading.value = true;
  loadError.value = '';
  try {
    course.value = await fetchCourseDetail(id.value);
  } catch {
    loadError.value = '课程不存在或已下架。';
    course.value = null;
  } finally {
    loading.value = false;
  }
}

async function loadCoupons(): Promise<void> {
  couponsLoading.value = true;
  try {
    const res = await fetchCheckoutCoupons(id.value);
    basePrice.value = res.base_price;
    couponOptions.value = res.items;
    const stillValid = res.items.some((opt) => opt.id === selectedCouponId.value && opt.eligible);
    if (!stillValid) {
      selectedCouponId.value = null;
    }
  } catch {
    couponOptions.value = [];
    basePrice.value = 0;
  } finally {
    couponsLoading.value = false;
  }
}

function channelEnabled(channel: PaymentChannel): boolean {
  return paymentOptions.value.enabled && paymentOptions.value.enabled_channels.includes(channel);
}

function selectAvailablePaymentMethod(): void {
  if (channelEnabled(paymentMethod.value === 'wechat' ? 'wxpay' : 'alipay')) return;
  const first = paymentOptions.value.enabled_channels[0];
  if (first === 'wxpay') paymentMethod.value = 'wechat';
  if (first === 'alipay') paymentMethod.value = 'alipay';
}

async function loadPaymentOptions(): Promise<void> {
  try {
    paymentOptions.value = await fetchPaymentOptions();
    selectAvailablePaymentMethod();
  } catch {
    paymentOptions.value = { enabled: false, enabled_channels: [] };
  }
}

async function load(): Promise<void> {
  await Promise.all([loadCourse(), loadCoupons(), loadPaymentOptions()]);
}

watch(id, () => void load(), { immediate: true });

const currentPrice = computed(() => {
  const c = course.value?.course;
  if (!c) return 0;
  return c.sale_price > 0 ? c.sale_price : c.list_price;
});

const discountAmount = computed(() => {
  const c = course.value?.course;
  if (!c) return 0;
  return c.sale_price > 0 ? c.list_price - c.sale_price : 0;
});

const selectedCoupon = computed<CouponOption | null>(() => {
  if (selectedCouponId.value === null) return null;
  return couponOptions.value.find((o) => o.id === selectedCouponId.value) ?? null;
});

const couponDiscount = computed(() => {
  const opt = selectedCoupon.value;
  if (!opt || !opt.eligible) return 0;
  return opt.discount_amount;
});

const selectedCouponName = computed(() => selectedCoupon.value?.name ?? '');

const payable = computed(() => Math.max(0, currentPrice.value - couponDiscount.value));

const submitLabel = computed(() => {
  const m = paymentMethod.value === 'wechat' ? '微信支付' : '支付宝';
  return `提交并使用${m}`;
});

const paymentMethodLabel = computed(() =>
  paymentMethod.value === 'wechat' ? '微信支付' : '支付宝',
);

function statusLabel(status: OrderDTO['status']): string {
  return {
    pending: '等待支付',
    succeeded: '支付成功',
    failed: '支付失败',
    cancelled: '订单已取消',
    unknown: '支付状态待确认',
  }[status];
}

function humanizeCouponReason(code: string): string {
  const map: Record<string, string> = {
    COUPON_MIN_AMOUNT_NOT_MET: '未达到满减门槛',
    COUPON_USE_LIMIT_EXCEEDED: '已超过每人限用次数',
    COUPON_NOT_APPLICABLE: '不适用于当前课程',
    COUPON_EXPIRED: '已过期',
    COUPON_VOIDED: '已失效',
    COUPON_NOT_FOUND: '所选优惠券已失效,请重新选择优惠券',
  };
  return map[code] ?? '不可用';
}

function formatPrice(value: number): string {
  return value.toFixed(2);
}

async function submitOrder(): Promise<void> {
  if (!course.value || !agreed.value) return;
  submitting.value = true;
  submitError.value = '';
  try {
    const channel = paymentMethod.value === 'wechat' ? 'wxpay' : 'alipay';
    const created = await createCourseOrder(id.value, selectedCouponId.value, channel);
    payment.value = created.payment;
    await refreshOrderById(created.order_id);
    startPolling();
  } catch (err) {
    if (err instanceof Error && err.message === 'SALE_WINDOW_EXPIRED') {
      submitError.value = '优惠已结束,请返回课程页刷新价格。';
    } else if (err instanceof Error && err.message.startsWith('COUPON_')) {
      selectedCouponId.value = null;
      const reason = humanizeCouponReason(err.message);
      submitError.value =
        err.message === 'COUPON_NOT_FOUND' ? `${reason}。` : '优惠券不可用:' + reason;
      await loadCoupons();
    } else {
      submitError.value = '订单创建失败,请稍后再试。';
    }
  } finally {
    submitting.value = false;
  }
}

async function refreshOrderById(orderId: number): Promise<void> {
  order.value = await fetchOrder(orderId);
  if (order.value.status !== 'pending') stopPolling();
}

async function refreshOrder(): Promise<void> {
  if (!order.value) return;
  refreshing.value = true;
  try {
    await refreshOrderById(order.value.order_id);
  } catch {
    submitError.value = '订单状态暂时读不到,请稍后再试。';
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

onUnmounted(stopPolling);
</script>

<style scoped>
.checkout {
  display: grid;
  gap: 28px;
  padding-bottom: 48px;
}
.checkout__head {
  padding: 18px 0 26px;
  border-bottom: 1px solid var(--line, #ebeef5);
}
.checkout__eyebrow {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  margin: 0 0 12px;
  font-size: 12px;
  color: var(--ink-2, #909399);
  letter-spacing: 0.5px;
}
.checkout__rule {
  display: inline-block;
  width: 24px;
  height: 1px;
  background: var(--seal, #409eff);
}
.checkout__title {
  margin: 0 0 8px;
  font-size: 24px;
  color: var(--ink, #303133);
}
.checkout__lede {
  margin: 0;
  font-size: 13px;
  color: var(--ink-2, #606266);
}
.checkout__error {
  display: flex;
  flex-direction: column;
  gap: 12px;
  align-items: flex-start;
}
.checkout__grid {
  display: grid;
  grid-template-columns: minmax(0, 7fr) minmax(320px, 5fr);
  gap: 24px;
  align-items: start;
}
.checkout-summary,
.checkout-payment {
  padding: 24px;
  border: 1px solid var(--line);
  border-radius: 12px;
  background: var(--card);
  box-shadow: var(--shadow);
}
.checkout-payment {
  position: sticky;
  top: 80px;
  display: flex;
  flex-direction: column;
  gap: 16px;
}
.checkout-summary__title {
  margin: 0 0 8px;
  font-size: 20px;
  color: var(--ink);
}
.checkout-summary__teacher,
.checkout-summary__copy {
  margin: 0 0 12px;
  font-size: 13px;
  color: var(--ink-2);
  line-height: 1.6;
}
.checkout-summary__price-list {
  list-style: none;
  margin: 20px 0 0;
  padding: 16px 0 0;
  border-top: 1px dashed var(--line);
  display: flex;
  flex-direction: column;
  gap: 8px;
}
.checkout-summary__row {
  display: flex;
  justify-content: space-between;
  font-size: 13px;
  color: var(--ink-2);
}
.checkout-summary__row--disc {
  color: var(--moss, #67c23a);
}
.checkout-summary__row--coupon {
  color: var(--seal, #409eff);
}
.checkout-summary__row--total {
  font-size: 14px;
  color: var(--ink);
  padding-top: 8px;
  border-top: 1px solid var(--line);
}
.checkout-summary__row--total b {
  font-size: 18px;
  color: var(--seal);
}
.checkout-summary__refund {
  margin: 16px 0 0;
  padding: 8px 12px;
  font-size: 12px;
  color: var(--ink-2, #909399);
  background: var(--card-2, #f5f7fa);
  border-radius: var(--r, 6px);
}
.checkout-payment__h {
  margin: 0;
  font-size: 16px;
  color: var(--ink);
}
.checkout-payment__methods {
  display: flex;
  flex-direction: column;
  gap: 8px;
}
.checkout-payment__method {
  display: flex;
  align-items: center;
  justify-content: space-between;
  width: 100%;
  padding: 16px;
  border: 1px solid var(--line-2);
  border-radius: var(--r);
  background: var(--card);
  margin-right: 0;
  height: auto;
}
.checkout-payment__method:hover {
  border-color: var(--seal-soft, #c6e2ff);
}
.checkout-payment__method.el-radio--checked {
  border-color: var(--seal);
  background: var(--seal-soft, #ecf5ff);
  box-shadow: 0 0 0 1px var(--seal) inset;
}
.checkout-payment__method-icon {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 32px;
  height: 32px;
  border-radius: 50%;
  font-size: 14px;
  color: #fff;
  font-weight: 600;
}
.checkout-payment__method-icon--wechat {
  background: #07c160;
}
.checkout-payment__method-icon--alipay {
  background: #1677ff;
}
.checkout-payment__method-label {
  font-size: 14px;
  color: var(--ink);
}
.checkout-payment__coupons {
  display: flex;
  flex-direction: column;
  gap: 8px;
  padding: 12px;
  border: 1px dashed var(--line);
  border-radius: var(--r);
  background: var(--card-2);
}
.checkout-payment__coupons-label {
  margin: 0;
  font-size: 13px;
  color: var(--ink-2);
  font-weight: 600;
}
.checkout-payment__coupon-list {
  display: flex;
  flex-direction: column;
  gap: 6px;
}
.checkout-payment__coupon-option {
  width: 100%;
  padding: 8px 10px;
  border: 1px solid var(--line-2);
  border-radius: var(--r);
  background: var(--card);
  margin-right: 0;
  height: auto;
}
.checkout-payment__coupon-option.is-disabled {
  color: var(--ink-2, #909399);
}
.checkout-payment__coupon-row {
  display: flex;
  flex-direction: column;
  gap: 2px;
}
.checkout-payment__coupon-label {
  font-size: 13px;
  color: var(--ink);
}
.checkout-payment__coupon-reason {
  font-size: 12px;
  color: var(--ink-2, #909399);
}
.checkout-payment__coupons-empty {
  margin: 0;
  font-size: 12px;
  color: var(--ink-2);
}
.checkout-payment__agree {
  font-size: 12px;
  color: var(--ink-2);
}
.checkout-payment__terms {
  color: var(--seal);
  text-decoration: none;
}
.checkout-payment__submit {
  width: 100%;
  margin-left: 0;
}
.checkout-payment__error {
  margin: 0;
  font-size: 12px;
  color: var(--danger, #f56c6c);
}
.checkout-payment__copy {
  margin: 0;
  font-size: 13px;
  color: var(--ink-2);
}
.checkout-payment__copy--ok {
  color: var(--moss);
}
.checkout-payment__copy--err {
  color: var(--danger);
}
.checkout-payment__code-box {
  padding: 12px;
  overflow: auto;
  border: 1px dashed var(--line);
  background: var(--card-2);
  color: var(--ink);
  font-size: 12px;
  border-radius: var(--r);
}
.checkout-payment__meta {
  margin: 0;
  font-size: 12px;
  color: var(--ink-2, #909399);
  font-family: var(--font-mono);
}
.checkout-payment__actions {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  align-items: center;
}
.checkout-payment__primary {
  display: inline-block;
  padding: 8px 16px;
  background: var(--seal);
  color: #fff;
  text-decoration: none;
  border-radius: var(--r);
  font-size: 13px;
}
.checkout-payment__link {
  font-size: 13px;
  color: var(--seal);
  text-decoration: none;
}
</style>
