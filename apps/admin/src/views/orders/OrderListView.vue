<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import {
  getOrder,
  listOrders,
  type AdminOrderDTO,
  type AdminOrderListDTO,
  type AdminOrderStatus,
} from '@/api/orders';
import { listCourses, type ListCoursesParams } from '@/api/catalog';
import { listLearners, type LearnerAccountDTO } from '@/api/learners';
import type { CourseDTO } from '@learn-site/contracts';
import { Document, RefreshRight, Search, Tickets, User } from '@element-plus/icons-vue';
import AdminListPager from '@/components/AdminListPager.vue';

defineOptions({ name: 'OrderListView' });

const list = ref<AdminOrderListDTO | null>(null);
const loading = ref(false);
const loadError = ref<string | null>(null);
const selected = ref<AdminOrderDTO | null>(null);
const detailError = ref<string | null>(null);
const submitting = ref(false);
const optionPageSize = 20;
const courseOptions = ref<CourseDTO[]>([]);
const courseOptionsPage = ref(0);
const courseOptionsTotal = ref(0);
const courseOptionsLoading = ref(false);
const courseOptionsQuery = ref('');
const learnerOptions = ref<LearnerAccountDTO[]>([]);
const learnerOptionsPage = ref(0);
const learnerOptionsTotal = ref(0);
const learnerOptionsLoading = ref(false);
const learnerOptionsQuery = ref('');
let courseRequestId = 0;
let learnerRequestId = 0;

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
  course_id: null as number | null,
  learner_id: null as number | null,
  from: '',
  to: '',
  date_range: [] as string[],
  page: 1,
  limit: 20,
});

const total = computed(() => list.value?.total ?? 0);
const hasMoreCourses = computed(
  () => courseOptionsPage.value * optionPageSize < courseOptionsTotal.value,
);
const hasMoreLearners = computed(
  () => learnerOptionsPage.value * optionPageSize < learnerOptionsTotal.value,
);

function positiveId(raw: number | null, label: string): number | undefined {
  if (raw === null) return undefined;
  const value = Number(raw);
  if (!Number.isInteger(value) || value <= 0) {
    throw new Error(`${label} ID 必须为正整数`);
  }
  return value;
}

function courseOptionLabel(course: CourseDTO): string {
  return `#${course.id} ${course.title}`;
}

function learnerOptionLabel(learner: LearnerAccountDTO): string {
  const name = learner.display_name || learner.login;
  return `#${learner.account_id} ${name} (${learner.login})`;
}

function mergeOptions<T>(current: T[], next: T[], getId: (item: T) => number): T[] {
  const seen = new Set<number>();
  return [...current, ...next].filter((item) => {
    const id = getId(item);
    if (seen.has(id)) return false;
    seen.add(id);
    return true;
  });
}

function replaceOptions<T>(
  current: T[],
  next: T[],
  selectedId: number | null,
  getId: (item: T) => number,
): T[] {
  if (selectedId === null || next.some((item) => getId(item) === selectedId)) return next;
  const selected = current.find((item) => getId(item) === selectedId);
  return selected ? [selected, ...next] : next;
}

async function loadCourseOptions(
  query = courseOptionsQuery.value,
  page = 1,
  append = false,
): Promise<void> {
  const requestId = ++courseRequestId;
  courseOptionsLoading.value = true;
  const trimmedQuery = query.trim();
  courseOptionsQuery.value = trimmedQuery;
  try {
    const params: ListCoursesParams = { page, limit: optionPageSize };
    if (trimmedQuery) params.q = trimmedQuery;
    const result = await listCourses(params);
    if (requestId !== courseRequestId) return;
    courseOptions.value = append
      ? mergeOptions(courseOptions.value, result.items, (item) => item.id)
      : replaceOptions(
          courseOptions.value,
          result.items,
          filters.value.course_id,
          (item) => item.id,
        );
    courseOptionsPage.value = result.page;
    courseOptionsTotal.value = result.total;
  } catch {
    if (requestId !== courseRequestId) return;
    if (!append) courseOptions.value = [];
    courseOptionsPage.value = 0;
    courseOptionsTotal.value = 0;
  } finally {
    if (requestId === courseRequestId) courseOptionsLoading.value = false;
  }
}

async function loadLearnerOptions(
  query = learnerOptionsQuery.value,
  page = 1,
  append = false,
): Promise<void> {
  const requestId = ++learnerRequestId;
  learnerOptionsLoading.value = true;
  const trimmedQuery = query.trim();
  learnerOptionsQuery.value = trimmedQuery;
  try {
    const result = await listLearners({
      ...(trimmedQuery ? { search: trimmedQuery } : {}),
      page,
      limit: optionPageSize,
    });
    if (requestId !== learnerRequestId) return;
    learnerOptions.value = append
      ? mergeOptions(learnerOptions.value, result.items, (item) => item.account_id)
      : replaceOptions(
          learnerOptions.value,
          result.items,
          filters.value.learner_id,
          (item) => item.account_id,
        );
    learnerOptionsPage.value = result.page;
    learnerOptionsTotal.value = result.total;
  } catch {
    if (requestId !== learnerRequestId) return;
    if (!append) learnerOptions.value = [];
    learnerOptionsPage.value = 0;
    learnerOptionsTotal.value = 0;
  } finally {
    if (requestId === learnerRequestId) learnerOptionsLoading.value = false;
  }
}

function searchCourses(query: string): void {
  void loadCourseOptions(query);
}

function searchLearners(query: string): void {
  void loadLearnerOptions(query);
}

function loadMoreCourses(): void {
  if (courseOptionsLoading.value || !hasMoreCourses.value) return;
  void loadCourseOptions(courseOptionsQuery.value, courseOptionsPage.value + 1, true);
}

function loadMoreLearners(): void {
  if (learnerOptionsLoading.value || !hasMoreLearners.value) return;
  void loadLearnerOptions(learnerOptionsQuery.value, learnerOptionsPage.value + 1, true);
}

function onCourseVisibleChange(visible: boolean): void {
  if (visible && courseOptionsPage.value === 0) void loadCourseOptions();
}

function onLearnerVisibleChange(visible: boolean): void {
  if (visible && learnerOptionsPage.value === 0) void loadLearnerOptions();
}

async function reload(): Promise<void> {
  loading.value = true;
  loadError.value = null;
  try {
    const params: {
      status?: AdminOrderStatus;
      course_id?: number;
      learner_id?: number;
      from?: string;
      to?: string;
      page: number;
      limit: number;
    } = { page: filters.value.page, limit: filters.value.limit };
    if (filters.value.status) params.status = filters.value.status;
    const courseId = positiveId(filters.value.course_id, '课程');
    const learnerId = positiveId(filters.value.learner_id, '学员');
    if (courseId !== undefined) params.course_id = courseId;
    if (learnerId !== undefined) params.learner_id = learnerId;
    if (filters.value.from) params.from = filters.value.from;
    if (filters.value.to) params.to = filters.value.to;
    const result = await listOrders(params);
    list.value = { ...result, items: result.items ?? [] };
    if ((list.value.items?.length ?? 0) === 0) {
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
  filters.value.from = filters.value.date_range[0] ?? '';
  filters.value.to = filters.value.date_range[1] ?? '';
  filters.value.page = 1;
  void reload();
}

function statusLabel(s: AdminOrderStatus): string {
  return statusOptions.find((o) => o.value === s)?.label ?? s;
}

function statusType(s: AdminOrderStatus): 'warning' | 'success' | 'danger' | 'info' {
  if (s === 'succeeded') return 'success';
  if (s === 'failed' || s === 'unknown') return 'danger';
  if (s === 'pending') return 'warning';
  return 'info';
}

function rowClassName({ row }: { row: AdminOrderDTO }): string {
  return selected.value?.order_id === row.order_id ? 'is-selected' : '';
}

function formatMoney(amount: number, currency: string): string {
  return `${currency} ${amount.toFixed(2)}`;
}

onMounted(() => {
  const query = new URLSearchParams(window.location.search);
  const from = query.get('from') ?? '';
  const to = query.get('to') ?? '';
  if (from && to) {
    filters.value.from = from;
    filters.value.to = to;
    filters.value.date_range = [from, to];
  }
  void Promise.all([reload(), loadCourseOptions(), loadLearnerOptions()]);
});
</script>

<template>
  <section class="page order-list">
    <header class="page-head">
      <div class="title-block">
        <span class="section-kicker">交易运营 / 订单</span>
        <h1 class="display">订单管理</h1>
        <p class="subtitle">查看订单、支付快照和交易渠道，支付状态仅由回调驱动。</p>
      </div>
      <div class="head-metric">
        <span>筛选结果</span>
        <strong>{{ total }}</strong>
        <span>笔订单</span>
      </div>
    </header>

    <el-card class="filter-panel" shadow="never">
      <el-form class="filters filter-form" inline @submit.prevent="applyFilters">
        <el-form-item label="订单状态">
          <el-select
            v-model="filters.status"
            class="filter-control"
            placement="bottom-start"
            clearable
            :teleported="true"
            placeholder="全部"
            data-field="status"
          >
            <el-option
              v-for="opt in statusOptions"
              :key="opt.value"
              :label="opt.label"
              :value="opt.value"
            />
          </el-select>
        </el-form-item>
        <el-form-item label="课程 ID">
          <el-select
            v-model="filters.course_id"
            class="filter-control option-filter"
            name="course_id"
            filterable
            remote
            clearable
            :teleported="true"
            :loading="courseOptionsLoading"
            :remote-method="searchCourses"
            :reserve-keyword="false"
            placeholder="选择课程"
            no-data-text="暂无课程"
            no-match-text="无匹配课程"
            placement="bottom-start"
            data-field="course_id"
            @visible-change="onCourseVisibleChange"
          >
            <el-option
              v-for="courseOption in courseOptions"
              :key="courseOption.id"
              :label="courseOptionLabel(courseOption)"
              :value="courseOption.id"
            />
            <template #footer>
              <div v-if="courseOptionsTotal > 0" class="option-footer">
                <el-button
                  v-if="hasMoreCourses"
                  text
                  type="primary"
                  size="small"
                  :loading="courseOptionsLoading"
                  data-action="load-more-courses"
                  @click="loadMoreCourses"
                >
                  加载更多
                </el-button>
                <span v-else>已加载全部</span>
              </div>
            </template>
          </el-select>
        </el-form-item>
        <el-form-item label="学员 ID">
          <el-select
            v-model="filters.learner_id"
            class="filter-control option-filter"
            name="learner_id"
            filterable
            remote
            clearable
            :teleported="true"
            :loading="learnerOptionsLoading"
            :remote-method="searchLearners"
            :reserve-keyword="false"
            placeholder="选择学员"
            no-data-text="暂无学员"
            no-match-text="无匹配学员"
            placement="bottom-start"
            data-field="learner_id"
            @visible-change="onLearnerVisibleChange"
          >
            <el-option
              v-for="learnerOption in learnerOptions"
              :key="learnerOption.account_id"
              :label="learnerOptionLabel(learnerOption)"
              :value="learnerOption.account_id"
            />
            <template #footer>
              <div v-if="learnerOptionsTotal > 0" class="option-footer">
                <el-button
                  v-if="hasMoreLearners"
                  text
                  type="primary"
                  size="small"
                  :loading="learnerOptionsLoading"
                  data-action="load-more-learners"
                  @click="loadMoreLearners"
                >
                  加载更多
                </el-button>
                <span v-else>已加载全部</span>
              </div>
            </template>
          </el-select>
        </el-form-item>
        <el-form-item label="创建日期">
          <el-date-picker
            v-model="filters.date_range"
            class="date-filter"
            type="daterange"
            value-format="YYYY-MM-DD"
            range-separator="至"
            start-placeholder="开始日期"
            end-placeholder="结束日期"
            clearable
            data-field="created_at"
          />
        </el-form-item>
        <el-form-item>
          <el-button type="primary" native-type="submit" :loading="loading">
            <el-icon><Search /></el-icon>
            查询订单
          </el-button>
        </el-form-item>
      </el-form>
    </el-card>

    <el-alert
      v-if="loadError"
      title="订单列表暂时读不到"
      :description="loadError"
      type="error"
      show-icon
      :closable="false"
    />

    <div class="layout">
      <el-card class="left-pane" shadow="never">
        <template #header>
          <div class="panel-heading">
            <div>
              <h2>订单列表</h2>
              <p>点击订单查看支付快照</p>
            </div>
            <el-button text :loading="loading" title="刷新订单" @click="reload">
              <el-icon><RefreshRight /></el-icon>
            </el-button>
          </div>
        </template>
        <el-table
          v-loading="loading"
          :data="list?.items ?? []"
          stripe
          highlight-current-row
          class="order-table"
          :row-class-name="rowClassName"
          @row-click="(row: AdminOrderDTO) => openDetail(row.order_id)"
        >
          <el-table-column label="订单号" width="110">
            <template #default="{ row }"
              ><span class="order-number">#{{ row.order_id }}</span></template
            >
          </el-table-column>
          <el-table-column label="课程" min-width="210">
            <template #default="{ row }">
              <div class="course-cell">
                <strong>{{ row.course_title ?? `课程 #${row.course_id}` }}</strong>
                <span>课程 ID {{ row.course_id }}</span>
              </div>
            </template>
          </el-table-column>
          <el-table-column label="学员" width="110">
            <template #default="{ row }"
              ><span class="id-cell"><User /> #{{ row.learner_id }}</span></template
            >
          </el-table-column>
          <el-table-column label="实付" width="130">
            <template #default="{ row }"
              ><strong class="amount">{{
                formatMoney(row.paid_amount, row.currency)
              }}</strong></template
            >
          </el-table-column>
          <el-table-column label="优惠券抵扣" width="120">
            <template #default="{ row }">
              <span v-if="row.coupon_discount_snapshot > 0" class="amount-coupon">
                − ¥ {{ formatMoney(row.coupon_discount_snapshot, row.currency) }}
              </span>
              <span v-else class="amount-none">—</span>
            </template>
          </el-table-column>
          <el-table-column label="状态" width="110">
            <template #default="{ row }">
              <el-tag :type="statusType(row.status)" effect="light" size="small">{{
                statusLabel(row.status)
              }}</el-tag>
            </template>
          </el-table-column>
          <el-table-column prop="created_at" label="创建时间" min-width="175" />
          <template #empty><el-empty description="暂无订单记录" :image-size="88" /></template>
        </el-table>
        <AdminListPager
          v-model:page="filters.page"
          v-model:page-size="filters.limit"
          :total="total"
          @change="reload"
        />
      </el-card>

      <el-card class="right-pane" shadow="never" aria-live="polite">
        <template #header>
          <div class="detail-heading">
            <div class="detail-icon"><Tickets /></div>
            <div>
              <h2>订单详情</h2>
              <p>支付快照只读</p>
            </div>
          </div>
        </template>
        <el-skeleton v-if="submitting" :rows="9" animated />
        <el-alert
          v-else-if="detailError"
          title="订单详情暂时读不到"
          :description="detailError"
          type="error"
          show-icon
          :closable="false"
        />
        <el-empty
          v-else-if="!selected"
          description="从列表选择一笔订单查看详情"
          :image-size="110"
        />
        <template v-else>
          <div class="detail-title-row">
            <div>
              <span class="section-kicker">交易记录</span>
              <h2>订单 #{{ selected.order_id }}</h2>
            </div>
            <el-tag :type="statusType(selected.status)" effect="light">{{
              statusLabel(selected.status)
            }}</el-tag>
          </div>
          <dl class="meta">
            <dt><Document /> 课程</dt>
            <dd>
              {{ selected.course_title ?? `课程 #${selected.course_id}` }} (#{{
                selected.course_id
              }})
            </dd>
            <dt><User /> 学员</dt>
            <dd>#{{ selected.learner_id }}</dd>
            <dt>部门</dt>
            <dd>{{ selected.department_id ? `#${selected.department_id}` : '—' }}</dd>
            <dt>标价</dt>
            <dd>{{ formatMoney(selected.list_price_snapshot, selected.currency) }}</dd>
            <dt>售价</dt>
            <dd>{{ formatMoney(selected.sale_price_snapshot, selected.currency) }}</dd>
            <dt>优惠券抵扣</dt>
            <dd>
              <span v-if="selected.coupon_discount_snapshot > 0">
                − ¥ {{ formatMoney(selected.coupon_discount_snapshot, selected.currency) }}
                <small v-if="selected.learner_coupon_id">
                  (券 #{{ selected.learner_coupon_id }})
                </small>
              </span>
              <span v-else>—</span>
            </dd>
            <dt class="emphasis">实付</dt>
            <dd class="emphasis">{{ formatMoney(selected.paid_amount, selected.currency) }}</dd>
            <dt>支付渠道</dt>
            <dd>
              {{ selected.provider
              }}{{ selected.provider_ref ? ` / ${selected.provider_ref}` : '' }}
            </dd>
            <dt>支付成功时间</dt>
            <dd>{{ selected.succeeded_at ?? '—' }}</dd>
            <dt>创建时间</dt>
            <dd>{{ selected.created_at }}</dd>
            <template v-if="selected.failed_reason">
              <dt>失败原因</dt>
              <dd class="failure">{{ selected.failed_reason }}</dd>
            </template>
          </dl>
          <el-alert
            title="只读数据"
            description="支付状态由支付渠道回调驱动，管理员不能在此页面修改。"
            type="info"
            show-icon
            :closable="false"
          />
        </template>
      </el-card>
    </div>
  </section>
</template>

<style scoped>
.page {
  display: grid;
  gap: 18px;
  min-width: 0;
}
.page-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  flex-wrap: wrap;
  gap: 18px;
}
.title-block {
  min-width: 0;
}
.section-kicker {
  display: block;
  margin-bottom: 6px;
  color: #168da7;
  font-size: 11px;
  font-weight: 700;
  letter-spacing: 0.12em;
}
.display {
  margin: 0;
  color: #102a43;
  font-size: clamp(1.6rem, 2vw, 2rem);
  letter-spacing: -0.025em;
}
.subtitle {
  max-width: 620px;
  margin: 7px 0 0;
  color: #6b7c93;
  font-size: 13px;
}
.head-metric {
  display: grid;
  min-width: 132px;
  padding-left: 18px;
  border-left: 1px solid #d8e2eb;
  color: #6b7c93;
  font-size: 12px;
  line-height: 1.4;
}
.head-metric strong {
  color: #102a43;
  font-size: 25px;
  line-height: 1.15;
}
.filter-panel,
.left-pane,
.right-pane {
  --el-card-border-color: #dce6ef;
  --el-card-padding: 18px;
  min-width: 0;
  border-radius: 8px;
  box-shadow: 0 8px 24px rgba(16, 42, 67, 0.04);
}
.filter-panel :deep(.el-card__body) {
  min-width: 0;
  padding: 14px 18px;
}
.filter-form {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 0 18px;
  min-width: 0;
}
.filter-form :deep(.el-form-item) {
  margin-bottom: 0;
}
.filter-form :deep(.el-form-item__label) {
  color: #52667a;
  font-size: 13px;
  font-weight: 600;
}
.filter-control {
  width: 168px;
}
.option-filter {
  width: 260px;
}
.date-filter {
  width: 270px;
}
.filters :deep(.el-input) {
  width: 150px;
}
.option-footer {
  display: flex;
  min-height: 36px;
  align-items: center;
  justify-content: center;
  color: #829ab1;
  font-size: 12px;
}
.layout {
  display: grid;
  grid-template-columns: minmax(0, 1.45fr) minmax(320px, 0.8fr);
  align-items: start;
  gap: 18px;
  min-width: 0;
}
.left-pane :deep(.el-card__header),
.right-pane :deep(.el-card__header) {
  padding: 16px 18px;
}
.panel-heading,
.detail-heading,
.detail-title-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
}
.panel-heading h2,
.detail-heading h2,
.detail-title-row h2 {
  margin: 0;
  color: #102a43;
  font-size: 15px;
}
.panel-heading p,
.detail-heading p {
  margin: 3px 0 0;
  color: #829ab1;
  font-size: 12px;
}
.detail-heading {
  justify-content: flex-start;
}
.detail-icon {
  display: grid;
  width: 34px;
  height: 34px;
  place-items: center;
  border-radius: 8px;
  color: #168da7;
  background: #e7f6f8;
  font-size: 18px;
}
.order-table {
  --el-table-header-bg-color: #f4f8fb;
  --el-table-row-hover-bg-color: #f3fbfc;
  --el-table-current-row-bg-color: #eef9fa;
  --el-table-border-color: #e6edf3;
}
.order-table :deep(.el-table__header th) {
  color: #52667a;
  font-size: 12px;
  font-weight: 700;
}
.order-table :deep(.el-table__row) {
  cursor: pointer;
}
.order-table :deep(.el-table__row.is-selected) td {
  background: #eef9fa !important;
}
.order-number {
  color: #168da7;
  font-weight: 700;
}
.course-cell {
  display: grid;
  gap: 3px;
  min-width: 0;
}
.course-cell strong {
  overflow-wrap: anywhere;
  color: #243b53;
  font-weight: 600;
}
.course-cell span {
  color: #829ab1;
  font-size: 11px;
}
.id-cell {
  display: inline-flex;
  align-items: center;
  gap: 5px;
  color: #52667a;
}
.id-cell svg {
  width: 14px;
  color: #829ab1;
}
.amount {
  color: #102a43;
  font-variant-numeric: tabular-nums;
}
.pager {
  justify-content: flex-end;
  margin-top: 18px;
  padding-top: 14px;
  border-top: 1px solid #edf2f6;
}
.detail-title-row {
  align-items: flex-start;
  padding-bottom: 16px;
  margin-bottom: 6px;
  border-bottom: 1px solid #e6edf3;
}
.detail-title-row h2 {
  font-size: 20px;
  letter-spacing: -0.02em;
}
.meta {
  display: grid;
  grid-template-columns: minmax(88px, max-content) minmax(0, 1fr);
  gap: 12px 16px;
  margin: 0;
}
.meta dt {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  color: #829ab1;
  font-size: 12px;
}
.meta dt svg {
  width: 14px;
}
.meta dd {
  min-width: 0;
  margin: 0;
  overflow-wrap: anywhere;
  color: #243b53;
  font-size: 13px;
}
.meta dd span {
  color: #829ab1;
  font-size: 12px;
}
.meta .emphasis {
  color: #168da7;
  font-weight: 700;
}
.meta dd.emphasis {
  font-size: 17px;
  font-variant-numeric: tabular-nums;
}
.meta .failure {
  color: #b42318;
}
.right-pane :deep(.el-alert) {
  margin-top: 20px;
}
.right-pane :deep(.el-empty) {
  min-height: 320px;
  justify-content: center;
}
@media (max-width: 1100px) {
  .layout {
    grid-template-columns: 1fr;
  }
}
@media (max-width: 620px) {
  .filter-form {
    display: grid;
    grid-template-columns: 1fr;
    gap: 4px;
  }
  .filter-form :deep(.el-form-item) {
    display: grid;
    grid-template-columns: 88px minmax(0, 1fr);
    align-items: center;
  }
  .filter-control,
  .option-filter,
  .date-filter,
  .filters :deep(.el-input) {
    width: 100%;
  }
  .meta {
    grid-template-columns: 1fr;
    gap: 4px;
  }
  .meta dd {
    padding-bottom: 8px;
    border-bottom: 1px solid #f0f4f7;
  }
}
</style>
