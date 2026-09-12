<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { ElMessage, ElMessageBox } from 'element-plus';
import type {
  AdminCommissionByOrderDTO,
  CommissionRecordDTO,
  CommissionStatus,
  CourseDTO,
  LearnerAccountDTO,
} from '@learn-site/contracts';
import {
  exportCommissionsCsv,
  fetchCommissions,
  fetchReconcileByOrder,
  voidCommission,
  type AdminCommissionListParams,
} from '@/api/distribution';
import { listCourses, type ListCoursesParams } from '@/api/catalog';
import { listLearners } from '@/api/learners';
import AdminListPager from '@/components/AdminListPager.vue';
import './distribution.css';

defineOptions({ name: 'DistributionReconcileView' });

const STATUS_OPTIONS: Array<{ value: '' | CommissionStatus; label: string }> = [
  { value: '', label: '全部状态' },
  { value: 'pending', label: '待结算' },
  { value: 'settled', label: '已结算' },
  { value: 'voided', label: '已撤销' },
  { value: 'pending_blocked', label: '已拦截' },
];

const items = ref<CommissionRecordDTO[]>([]);
const total = ref(0);
const loading = ref(false);
const exporting = ref(false);
const searching = ref(false);
const errorMsg = ref<string | null>(null);
const orderIdInput = ref('');
const result = ref<AdminCommissionByOrderDTO | null>(null);
const detailOpen = ref(false);
const detailLoading = ref(false);
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

const filters = ref({
  status: '' as '' | CommissionStatus,
  course_id: null as number | null,
  learner_id: null as number | null,
  page: 1,
  limit: 20,
});

const tooMany = computed(() => (result.value?.receivers.length ?? 0) > 3);
const hasMoreCourses = computed(
  () => courseOptionsPage.value * optionPageSize < courseOptionsTotal.value,
);
const hasMoreLearners = computed(
  () => learnerOptionsPage.value * optionPageSize < learnerOptionsTotal.value,
);

function parsePositiveInt(raw: unknown): number | null {
  if (raw === null || raw === undefined || raw === '') return null;
  const value = typeof raw === 'number' ? raw : Number(String(raw).trim());
  if (!Number.isInteger(value) || value <= 0) return null;
  return value;
}

function formatYuan(cents: number): string {
  return `¥ ${(cents / 100).toFixed(2)}`;
}

function statusLabel(status: CommissionStatus): string {
  return STATUS_OPTIONS.find((item) => item.value === status)?.label ?? status;
}

function statusType(status: CommissionStatus): 'success' | 'warning' | 'info' | 'danger' {
  if (status === 'settled') return 'success';
  if (status === 'pending') return 'warning';
  if (status === 'pending_blocked') return 'danger';
  return 'info';
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
    const data = await listCourses(params);
    if (requestId !== courseRequestId) return;
    courseOptions.value = append
      ? mergeOptions(courseOptions.value, data.items, (item) => item.id)
      : replaceOptions(courseOptions.value, data.items, filters.value.course_id, (item) => item.id);
    courseOptionsPage.value = data.page;
    courseOptionsTotal.value = data.total;
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
    const data = await listLearners({
      ...(trimmedQuery ? { search: trimmedQuery } : {}),
      page,
      limit: optionPageSize,
    });
    if (requestId !== learnerRequestId) return;
    learnerOptions.value = append
      ? mergeOptions(learnerOptions.value, data.items, (item) => item.account_id)
      : replaceOptions(
          learnerOptions.value,
          data.items,
          filters.value.learner_id,
          (item) => item.account_id,
        );
    learnerOptionsPage.value = data.page;
    learnerOptionsTotal.value = data.total;
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

function listParams(): AdminCommissionListParams {
  const params: AdminCommissionListParams = {
    page: filters.value.page,
    limit: filters.value.limit,
  };
  const orderId = parsePositiveInt(orderIdInput.value);
  if (orderId !== null) params.order_id = orderId;
  if (filters.value.status) params.status = filters.value.status;
  const courseId = parsePositiveInt(filters.value.course_id);
  const learnerId = parsePositiveInt(filters.value.learner_id);
  if (courseId !== null) params.course_id = courseId;
  if (learnerId !== null) params.learner_id = learnerId;
  return params;
}

async function reload(): Promise<void> {
  loading.value = true;
  errorMsg.value = null;
  try {
    const data = await fetchCommissions(listParams());
    items.value = data.items;
    total.value = data.total;
  } catch (error) {
    errorMsg.value = (error as Error).message || 'load_failed';
    items.value = [];
    total.value = 0;
  } finally {
    loading.value = false;
  }
}

async function search(): Promise<void> {
  const raw = orderIdInput.value.trim();
  if (raw !== '' && parsePositiveInt(raw) === null) {
    errorMsg.value = '订单号必须为正整数';
    return;
  }
  searching.value = true;
  filters.value.page = 1;
  try {
    await reload();
    const orderId = parsePositiveInt(raw);
    if (orderId !== null) await openOrder(orderId);
  } finally {
    searching.value = false;
  }
}

function resetFilters(): void {
  orderIdInput.value = '';
  filters.value.status = '';
  filters.value.course_id = null;
  filters.value.learner_id = null;
  filters.value.page = 1;
  void reload();
}

async function openOrder(orderId: number): Promise<void> {
  detailOpen.value = true;
  detailLoading.value = true;
  errorMsg.value = null;
  try {
    result.value = await fetchReconcileByOrder(orderId);
    if (result.value === null) {
      errorMsg.value = null;
    }
  } catch (error) {
    result.value = null;
    errorMsg.value = (error as Error).message;
  } finally {
    detailLoading.value = false;
  }
}

async function voidOne(id: number): Promise<void> {
  try {
    const { value } = await ElMessageBox.prompt('撤销原因 (至少 5 字)', '撤销佣金', {
      type: 'warning',
      inputValidator: (v) => (v && v.trim().length >= 5 ? true : '原因至少 5 个字符'),
    });
    await voidCommission(id, value);
    ElMessage.success('已撤销');
    await reload();
    if (result.value) await openOrder(result.value.order_id);
  } catch {
    return;
  }
}

async function exportCsv(): Promise<void> {
  exporting.value = true;
  try {
    const csv = await exportCommissionsCsv({ ...listParams(), page: 1, limit: 200 });
    const blob = new Blob([csv], { type: 'text/csv;charset=utf-8' });
    const url = URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.href = url;
    link.download = 'commissions.csv';
    link.click();
    URL.revokeObjectURL(url);
  } catch (error) {
    errorMsg.value = (error as Error).message || 'export_failed';
  } finally {
    exporting.value = false;
  }
}

onMounted(() => void reload());
</script>

<template>
  <main class="dist-page">
    <header class="dist-head">
      <div>
        <span class="dist-kicker">分销运营 / 对账</span>
        <h1 class="dist-display">佣金对账</h1>
        <p class="dist-subtitle">按订单、状态和课程核对分成, 空结果不再报 404.</p>
      </div>
      <div class="dist-metric">
        <span>当前筛选</span>
        <strong>{{ total }}</strong>
        <span>条佣金</span>
      </div>
    </header>

    <el-alert v-if="errorMsg" :title="errorMsg" type="error" :closable="false" show-icon />

    <el-card class="dist-panel" shadow="never">
      <el-form class="dist-filter" inline aria-label="佣金对账筛选" @submit.prevent="search">
        <el-form-item label="订单号">
          <el-input
            v-model="orderIdInput"
            class="dist-control"
            name="order_id"
            data-field="order_id"
            clearable
            placeholder="输入订单号"
          />
        </el-form-item>
        <el-form-item label="状态">
          <el-select
            v-model="filters.status"
            class="dist-control"
            clearable
            placeholder="全部状态"
            data-field="status"
          >
            <el-option
              v-for="opt in STATUS_OPTIONS"
              :key="opt.value || 'all'"
              :label="opt.label"
              :value="opt.value"
            />
          </el-select>
        </el-form-item>
        <el-form-item label="课程">
          <el-select
            v-model="filters.course_id"
            class="dist-control-wide"
            filterable
            remote
            clearable
            :loading="courseOptionsLoading"
            :remote-method="searchCourses"
            placeholder="选择课程"
            data-field="course_id"
            @visible-change="onCourseVisibleChange"
          >
            <el-option
              v-for="course in courseOptions"
              :key="course.id"
              :label="courseOptionLabel(course)"
              :value="course.id"
            />
            <template #footer>
              <div v-if="courseOptionsTotal > 0" class="option-footer">
                <el-button
                  v-if="hasMoreCourses"
                  text
                  type="primary"
                  size="small"
                  :loading="courseOptionsLoading"
                  @click="loadMoreCourses"
                >
                  加载更多
                </el-button>
                <span v-else>已加载全部</span>
              </div>
            </template>
          </el-select>
        </el-form-item>
        <el-form-item label="接收人">
          <el-select
            v-model="filters.learner_id"
            class="dist-control-wide"
            filterable
            remote
            clearable
            :loading="learnerOptionsLoading"
            :remote-method="searchLearners"
            placeholder="选择学员"
            data-field="learner_id"
            @visible-change="onLearnerVisibleChange"
          >
            <el-option
              v-for="learner in learnerOptions"
              :key="learner.account_id"
              :label="learnerOptionLabel(learner)"
              :value="learner.account_id"
            />
            <template #footer>
              <div v-if="learnerOptionsTotal > 0" class="option-footer">
                <el-button
                  v-if="hasMoreLearners"
                  text
                  type="primary"
                  size="small"
                  :loading="learnerOptionsLoading"
                  @click="loadMoreLearners"
                >
                  加载更多
                </el-button>
                <span v-else>已加载全部</span>
              </div>
            </template>
          </el-select>
        </el-form-item>
        <el-form-item>
          <el-button type="primary" native-type="submit" :loading="searching || loading">
            查询
          </el-button>
          <el-button @click="resetFilters">重置</el-button>
          <el-button :loading="exporting" @click="exportCsv">导出 CSV</el-button>
        </el-form-item>
      </el-form>
    </el-card>

    <el-card class="dist-panel" shadow="never">
      <el-table v-loading="loading" :data="items" stripe empty-text="暂无佣金记录">
        <el-table-column prop="order_id" label="订单" width="100" />
        <el-table-column prop="course_title" label="课程" min-width="160" />
        <el-table-column prop="referee_masked_phone" label="下单学员" min-width="130" />
        <el-table-column label="级别" width="80">
          <template #default="{ row }">L{{ row.level }}</template>
        </el-table-column>
        <el-table-column label="金额" width="120">
          <template #default="{ row }">
            <span class="dist-money">{{ formatYuan(row.amount_cents) }}</span>
          </template>
        </el-table-column>
        <el-table-column label="状态" width="110">
          <template #default="{ row }">
            <el-tag :type="statusType(row.status)" size="small">{{
              statusLabel(row.status)
            }}</el-tag>
          </template>
        </el-table-column>
        <el-table-column prop="created_at" label="创建时间" min-width="170" />
        <el-table-column label="操作" width="160" fixed="right">
          <template #default="{ row }">
            <el-button text type="primary" @click="openOrder(row.order_id)">按订单</el-button>
            <el-button
              text
              type="danger"
              :disabled="row.status === 'voided'"
              @click="voidOne(row.id)"
            >
              撤销
            </el-button>
          </template>
        </el-table-column>
        <template #empty><el-empty description="没有匹配的佣金记录" :image-size="88" /></template>
      </el-table>
      <AdminListPager
        v-model:page="filters.page"
        v-model:page-size="filters.limit"
        :total="total"
        @change="reload"
      />
    </el-card>

    <el-drawer
      v-model="detailOpen"
      title="订单佣金明细"
      size="520px"
      destroy-on-close
      :append-to-body="false"
    >
      <el-skeleton v-if="detailLoading" :rows="6" animated />
      <el-empty v-else-if="!result" description="该订单没有佣金记录" :image-size="88" />
      <div v-else class="order-detail">
        <el-descriptions :column="2" border>
          <el-descriptions-item label="订单">#{{ result.order_id }}</el-descriptions-item>
          <el-descriptions-item label="课程">#{{ result.course_id }}</el-descriptions-item>
          <el-descriptions-item label="实付快照" :span="2">
            <span class="dist-money">{{ formatYuan(result.order_paid_cents_snapshot) }}</span>
          </el-descriptions-item>
        </el-descriptions>
        <el-alert
          v-if="tooMany"
          class="detail-alert"
          type="error"
          title="数据完整性硬约束"
          :closable="false"
          show-icon
        />
        <el-table :data="result.receivers" stripe empty-text="该订单暂无接收人">
          <el-table-column prop="referrer_masked_phone" label="接收人" min-width="130" />
          <el-table-column label="级别" width="70">
            <template #default="{ row }">L{{ row.level }}</template>
          </el-table-column>
          <el-table-column label="金额" width="110">
            <template #default="{ row }">
              <span class="dist-money">{{ formatYuan(row.amount_cents) }}</span>
            </template>
          </el-table-column>
          <el-table-column label="状态" width="100">
            <template #default="{ row }">
              <el-tag :type="statusType(row.status)" size="small">{{
                statusLabel(row.status)
              }}</el-tag>
            </template>
          </el-table-column>
          <el-table-column label="操作" width="90">
            <template #default="{ row }">
              <el-button
                text
                type="danger"
                :disabled="tooMany || row.status === 'voided'"
                @click="voidOne(row.id)"
              >
                撤销
              </el-button>
            </template>
          </el-table-column>
        </el-table>
      </div>
    </el-drawer>
  </main>
</template>

<style scoped>
.option-footer {
  display: flex;
  min-height: 36px;
  align-items: center;
  justify-content: center;
  color: #829ab1;
  font-size: 12px;
}

.order-detail {
  display: grid;
  gap: 16px;
}

.detail-alert {
  margin: 0;
}
</style>
