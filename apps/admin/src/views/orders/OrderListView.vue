<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import {
  getOrder,
  listOrders,
  type AdminOrderDTO,
  type AdminOrderListDTO,
  type AdminOrderStatus,
} from '@/api/orders';

defineOptions({ name: 'OrderListView' });

const list = ref<AdminOrderListDTO | null>(null);
const loading = ref(false);
const loadError = ref<string | null>(null);
const selected = ref<AdminOrderDTO | null>(null);
const detailError = ref<string | null>(null);
const submitting = ref(false);

const statusOptions: Array<{ value: '' | AdminOrderStatus; label: string }> = [
  { value: '', label: '全部' },
  { value: 'pending', label: '待支付' },
  { value: 'succeeded', label: '已支付' },
  { value: 'failed', label: '失败' },
  { value: 'cancelled', label: '已取消' },
  { value: 'unknown', label: '未知' },
];

const filters = ref({
  status: '' as '' | AdminOrderStatus,
  course_id: '' as string,
  learner_id: '' as string,
  page: 1,
  limit: 20,
});

const total = computed(() => list.value?.total ?? 0);
const totalPages = computed(() =>
  list.value ? Math.max(1, Math.ceil(list.value.total / list.value.limit)) : 1,
);

function positiveId(raw: string, label: string): number | undefined {
  if (raw === '') return undefined;
  const value = Number(raw);
  if (!Number.isInteger(value) || value <= 0) {
    throw new Error(`${label} ID 必须为正整数`);
  }
  return value;
}

async function reload(): Promise<void> {
  loading.value = true;
  loadError.value = null;
  try {
    const params: {
      status?: AdminOrderStatus;
      course_id?: number;
      learner_id?: number;
      page: number;
      limit: number;
    } = { page: filters.value.page, limit: filters.value.limit };
    if (filters.value.status) params.status = filters.value.status;
    const courseId = positiveId(filters.value.course_id, '课程');
    const learnerId = positiveId(filters.value.learner_id, '学员');
    if (courseId !== undefined) params.course_id = courseId;
    if (learnerId !== undefined) params.learner_id = learnerId;
    list.value = await listOrders(params);
    if (list.value.items.length === 0) {
      selected.value = null;
    }
  } catch (err) {
    loadError.value = (err as Error).message || 'load_failed';
  } finally {
    loading.value = false;
  }
}

async function openDetail(id: number): Promise<void> {
  detailError.value = null;
  submitting.value = true;
  try {
    selected.value = await getOrder(id);
  } catch (err) {
    detailError.value = (err as Error).message || 'load_failed';
  } finally {
    submitting.value = false;
  }
}

function applyFilters(): void {
  filters.value.page = 1;
  void reload();
}

function gotoPage(p: number): void {
  if (p < 1 || p > totalPages.value) return;
  filters.value.page = p;
  void reload();
}

function statusLabel(s: AdminOrderStatus): string {
  return statusOptions.find((o) => o.value === s)?.label ?? s;
}

function formatMoney(amount: number, currency: string): string {
  return `${currency} ${amount.toFixed(2)}`;
}

onMounted(() => {
  void reload();
});
</script>

<template>
  <section class="page order-list">
    <header class="head">
      <h1 class="display">订单管理</h1>
      <p class="hint">只读视图 — 订单状态由支付回调驱动,管理员不可修改.</p>
    </header>

    <form class="filters" @submit.prevent="applyFilters">
      <label>
        状态
        <select v-model="filters.status" name="status">
          <option v-for="opt in statusOptions" :key="opt.value" :value="opt.value">
            {{ opt.label }}
          </option>
        </select>
      </label>
      <label>
        课程ID
        <input v-model="filters.course_id" name="course_id" type="number" min="1" step="1" />
      </label>
      <label>
        学员ID
        <input v-model="filters.learner_id" name="learner_id" type="number" min="1" step="1" />
      </label>
      <button type="submit" class="btn btn-primary" :disabled="loading">查询</button>
    </form>

    <p v-if="loading" class="notice">加载中…</p>
    <p v-else-if="loadError" class="notice error">暂时读不到 ({{ loadError }}).</p>

    <div v-else class="layout">
      <article class="left-pane">
        <table class="grid">
          <thead>
            <tr>
              <th>订单号</th>
              <th>课程</th>
              <th>学员</th>
              <th>实付</th>
              <th>状态</th>
              <th>创建时间</th>
            </tr>
          </thead>
          <tbody>
            <tr
              v-for="row in list?.items ?? []"
              :key="row.order_id"
              class="row"
              :class="{ active: selected && selected.order_id === row.order_id }"
              @click="openDetail(row.order_id)"
            >
              <td>#{{ row.order_id }}</td>
              <td>
                <span class="course-title">{{ row.course_title ?? `课程 #${row.course_id}` }}</span>
                <span class="muted">#{{ row.course_id }}</span>
              </td>
              <td>#{{ row.learner_id }}</td>
              <td>{{ formatMoney(row.paid_amount, row.currency) }}</td>
              <td>
                <span class="badge" :data-status="row.status">{{ statusLabel(row.status) }}</span>
              </td>
              <td>{{ row.created_at }}</td>
            </tr>
            <tr v-if="!list || list.items.length === 0">
              <td colspan="6" class="muted center">暂无订单.</td>
            </tr>
          </tbody>
        </table>

        <nav v-if="total > 0" class="pager">
          <button
            type="button"
            class="btn"
            :disabled="filters.page <= 1"
            @click="gotoPage(filters.page - 1)"
          >
            上一页
          </button>
          <span>{{ filters.page }} / {{ totalPages }} · 共 {{ total }} 条</span>
          <button
            type="button"
            class="btn"
            :disabled="filters.page >= totalPages"
            @click="gotoPage(filters.page + 1)"
          >
            下一页
          </button>
        </nav>
      </article>

      <aside class="right-pane" aria-live="polite">
        <p v-if="submitting" class="notice">加载订单详情…</p>
        <p v-else-if="detailError" class="notice error">{{ detailError }}</p>
        <p v-else-if="!selected" class="notice">从列表选择一行查看详情.</p>
        <template v-else>
          <h2>订单 #{{ selected.order_id }}</h2>
          <dl class="meta">
            <dt>状态</dt>
            <dd>
              <span class="badge" :data-status="selected.status">{{
                statusLabel(selected.status)
              }}</span>
            </dd>
            <dt>课程</dt>
            <dd>
              {{ selected.course_title ?? `课程 #${selected.course_id}` }} (#{{
                selected.course_id
              }})
            </dd>
            <dt>学员</dt>
            <dd>#{{ selected.learner_id }}</dd>
            <dt>部门</dt>
            <dd>{{ selected.department_id ? `#${selected.department_id}` : '—' }}</dd>
            <dt>标价</dt>
            <dd>{{ formatMoney(selected.list_price_snapshot, selected.currency) }}</dd>
            <dt>售价</dt>
            <dd>{{ formatMoney(selected.sale_price_snapshot, selected.currency) }}</dd>
            <dt>实付</dt>
            <dd>{{ formatMoney(selected.paid_amount, selected.currency) }}</dd>
            <dt>渠道</dt>
            <dd>
              {{ selected.provider
              }}{{ selected.provider_ref ? ` / ${selected.provider_ref}` : '' }}
            </dd>
            <dt>支付成功时间</dt>
            <dd>{{ selected.succeeded_at ?? '—' }}</dd>
            <dt>创建时间</dt>
            <dd>{{ selected.created_at }}</dd>
            <dt v-if="selected.failed_reason">失败原因</dt>
            <dd v-if="selected.failed_reason">{{ selected.failed_reason }}</dd>
          </dl>
          <p class="hint">支付状态由 FakePaymentAdapter / 真实回调驱动,本页只读.</p>
        </template>
      </aside>
    </div>
  </section>
</template>

<style scoped>
.page {
  display: grid;
  gap: 16px;
}
.head {
  display: flex;
  justify-content: space-between;
  align-items: baseline;
  gap: 12px;
  flex-wrap: wrap;
}
.display {
  margin: 0;
  font-size: 1.4rem;
}
.hint {
  color: var(--color-text-muted, #5b6472);
  margin: 0;
  font-size: 0.85rem;
}
.filters {
  display: flex;
  gap: 12px;
  flex-wrap: wrap;
  align-items: end;
}
.filters label {
  display: grid;
  gap: 4px;
  font-size: 0.85rem;
}
.filters input,
.filters select {
  padding: 6px 8px;
  border: 1px solid var(--color-border, #d0d4dc);
  border-radius: 6px;
  font: inherit;
}
.layout {
  display: grid;
  grid-template-columns: minmax(0, 1.4fr) minmax(280px, 1fr);
  gap: 16px;
}
@media (max-width: 1100px) {
  .layout {
    grid-template-columns: 1fr;
  }
}
.left-pane,
.right-pane {
  border: 1px solid var(--color-border, #d0d4dc);
  border-radius: 8px;
  padding: 16px;
  background: #fff;
}
.grid {
  width: 100%;
  border-collapse: collapse;
  font-size: 0.9rem;
}
.grid th,
.grid td {
  border-bottom: 1px solid var(--color-border, #e6e8ee);
  padding: 8px;
  text-align: left;
}
.grid th {
  background: var(--color-bg-soft, #fafbfd);
  font-weight: 600;
}
.row {
  cursor: pointer;
}
.row:hover {
  background: var(--color-bg-soft, #f5f6fa);
}
.row.active {
  background: rgba(37, 99, 235, 0.08);
}
.course-title {
  font-weight: 500;
}
.muted {
  color: var(--color-text-muted, #5b6472);
  font-size: 0.8rem;
  margin-left: 4px;
}
.center {
  text-align: center;
  padding: 24px 0;
}
.badge {
  padding: 2px 8px;
  border-radius: 999px;
  background: var(--color-bg-soft, #f5f6fa);
  border: 1px solid var(--color-border, #d0d4dc);
  font-size: 0.78rem;
}
.badge[data-status='pending'] {
  background: #fff7e6;
  border-color: #d99a26;
}
.badge[data-status='succeeded'] {
  background: #e7f7ee;
  border-color: #2bb673;
}
.badge[data-status='failed'] {
  background: #fde8e6;
  border-color: #b42318;
}
.badge[data-status='cancelled'] {
  background: #eef0f3;
  border-color: #8a8f99;
}
.badge[data-status='unknown'] {
  background: #f0e8fb;
  border-color: #6b46c1;
}
.pager {
  display: flex;
  gap: 12px;
  align-items: center;
  margin-top: 12px;
}
.meta {
  display: grid;
  grid-template-columns: max-content 1fr;
  gap: 4px 16px;
  margin: 0;
}
.meta dt {
  color: var(--color-text-muted, #5b6472);
  font-size: 0.85rem;
}
.meta dd {
  margin: 0;
  font-size: 0.9rem;
}
.btn {
  padding: 6px 12px;
  border: 1px solid var(--color-border, #d0d4dc);
  border-radius: 6px;
  background: transparent;
  font: inherit;
  cursor: pointer;
}
.btn:disabled {
  opacity: 0.5;
  cursor: not-allowed;
}
.btn-primary {
  background: var(--color-primary, #2563eb);
  color: #fff;
  border-color: transparent;
}
.notice {
  color: var(--color-text-muted, #5b6472);
  margin: 0;
}
.notice.error {
  color: #b42318;
}
</style>
