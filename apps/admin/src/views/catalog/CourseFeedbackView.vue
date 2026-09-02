<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
import { useRoute } from 'vue-router';
import { ElMessage } from 'element-plus';
import type {
  AdminCourseFeedbackDetailDTO,
  AdminCourseFeedbackListItemDTO,
  CourseFeedbackStatus,
} from '@learn-site/contracts';
import { getFeedback, listFeedback, updateFeedbackStatus } from '@/api/courseFeedback';
import AdminListPager from '@/components/AdminListPager.vue';

defineOptions({ name: 'CourseFeedbackView' });

const route = useRoute();
const courseId = computed(() => {
  const value = route.params.id;
  if (value === undefined || value === null || Array.isArray(value)) return null;
  const parsed = Number(value);
  return Number.isInteger(parsed) && parsed > 0 ? parsed : null;
});

const items = ref<AdminCourseFeedbackListItemDTO[]>([]);
const total = ref(0);
const page = ref(1);
const limit = ref(20);
const status = ref<CourseFeedbackStatus | ''>('');
const loading = ref(false);
const listError = ref('');

const detailOpen = ref(false);
const detailLoading = ref(false);
const detailError = ref('');
const detail = ref<AdminCourseFeedbackDetailDTO | null>(null);
const updating = ref(false);

const nextStatus = computed<CourseFeedbackStatus | null>(() => {
  if (!detail.value) return null;
  return detail.value.status === 'pending' ? 'processed' : 'pending';
});

function statusLabel(value: CourseFeedbackStatus): string {
  return value === 'pending' ? '待处理' : '已处理';
}

function statusTagType(value: CourseFeedbackStatus): 'warning' | 'success' {
  return value === 'pending' ? 'warning' : 'success';
}

function formatTimestamp(value: string | null): string {
  if (!value) return '—';
  return value
    .replace('T', ' ')
    .replace(/(?:Z|[+-]\d{2}:\d{2})$/, '')
    .slice(0, 19);
}

async function reload(): Promise<void> {
  if (courseId.value === null) {
    items.value = [];
    total.value = 0;
    listError.value = '课程编号无效。';
    return;
  }

  loading.value = true;
  listError.value = '';
  try {
    const params: { page: number; limit: number; status?: CourseFeedbackStatus } = {
      page: page.value,
      limit: limit.value,
    };
    if (status.value) params.status = status.value;
    const result = await listFeedback(courseId.value, params);
    items.value = result.items;
    total.value = result.total;
    page.value = result.page;
  } catch (error) {
    items.value = [];
    total.value = 0;
    listError.value = feedbackErrorMessage(error, 'list');
  } finally {
    loading.value = false;
  }
}

function applyStatusFilter(): void {
  page.value = 1;
  detailOpen.value = false;
  detail.value = null;
  detailError.value = '';
  void reload();
}

function onPagerChange(): void {
  detailOpen.value = false;
  detail.value = null;
  detailError.value = '';
  void reload();
}

async function openDetail(row: AdminCourseFeedbackListItemDTO): Promise<void> {
  if (courseId.value === null || detailLoading.value) return;
  detailOpen.value = true;
  detailLoading.value = true;
  detailError.value = '';
  detail.value = null;
  try {
    detail.value = await getFeedback(courseId.value, row.id);
  } catch (error) {
    detail.value = null;
    detailError.value = feedbackErrorMessage(error, 'detail');
  } finally {
    detailLoading.value = false;
  }
}

async function changeStatus(): Promise<void> {
  if (courseId.value === null || !detail.value || !nextStatus.value || updating.value) return;
  updating.value = true;
  detailError.value = '';
  try {
    const updated = await updateFeedbackStatus(courseId.value, detail.value.id, nextStatus.value);
    detail.value = updated;
    ElMessage.success(updated.status === 'processed' ? '已标记为已处理' : '已打回待处理');
    await reload();
  } catch (error) {
    detailError.value = feedbackErrorMessage(error, 'update');
  } finally {
    updating.value = false;
  }
}

function feedbackErrorMessage(error: unknown, context: 'list' | 'detail' | 'update'): string {
  const candidate = error as {
    code?: string;
    response?: {
      status?: number;
      data?: { error?: { code?: string; message?: string } };
    };
  };
  const statusCode = candidate.response?.status;
  const apiCode = candidate.response?.data?.error?.code ?? candidate.code;

  if (statusCode === 403 || apiCode === 'FORBIDDEN') {
    return '无权访问该课程的意见反馈。';
  }
  if (statusCode === 404 || apiCode === 'NOT_FOUND') {
    return context === 'list' ? '课程不存在或已被删除。' : '反馈不存在或已被删除。';
  }
  if (context === 'detail') return '加载反馈详情失败，请稍后重试。';
  if (context === 'update') return '更新处理状态失败，请稍后重试。';
  return '加载反馈列表失败，请稍后重试。';
}

watch(courseId, (current, previous) => {
  if (current === previous) return;
  page.value = 1;
  detailOpen.value = false;
  detail.value = null;
  detailError.value = '';
  void reload();
});

onMounted(() => {
  void reload();
});
</script>

<template>
  <main class="feedback-page">
    <header class="page-head">
      <div>
        <h1>课程意见反馈</h1>
        <p class="course-reference">课程 #{{ courseId ?? '—' }}</p>
      </div>
      <div class="count" aria-label="反馈总数">
        <strong>{{ total }}</strong>
        <span>条反馈</span>
      </div>
    </header>

    <section class="filter-bar" aria-label="反馈筛选">
      <el-form inline @submit.prevent>
        <el-form-item label="处理状态">
          <el-select
            v-model="status"
            class="status-filter"
            data-field="status"
            placeholder="全部状态"
            :teleported="true"
            placement="bottom-start"
            @change="applyStatusFilter"
          >
            <el-option label="全部状态" value="" />
            <el-option label="待处理" value="pending" />
            <el-option label="已处理" value="processed" />
          </el-select>
        </el-form-item>
      </el-form>
    </section>

    <el-alert
      v-if="listError"
      class="list-error"
      :title="listError"
      type="error"
      show-icon
      :closable="false"
    />

    <section class="table-panel" aria-label="反馈列表">
      <el-table v-loading="loading" :data="items" row-key="id" stripe empty-text="暂无意见反馈">
        <el-table-column label="提交学员" min-width="160">
          <template #default="{ row }">
            <div class="learner-cell">
              <strong>{{ row.learner.nickname }}</strong>
              <span>账号 #{{ row.learner.account_id }}</span>
            </div>
          </template>
        </el-table-column>
        <el-table-column label="内容摘要" min-width="320">
          <template #default="{ row }">
            <span class="excerpt" :title="row.body_excerpt">{{ row.body_excerpt || '—' }}</span>
          </template>
        </el-table-column>
        <el-table-column label="状态" width="100">
          <template #default="{ row }">
            <el-tag :type="statusTagType(row.status)" effect="light" size="small">
              {{ statusLabel(row.status) }}
            </el-tag>
          </template>
        </el-table-column>
        <el-table-column label="提交时间" width="180">
          <template #default="{ row }">{{ formatTimestamp(row.created_at) }}</template>
        </el-table-column>
        <el-table-column label="处理时间" width="180">
          <template #default="{ row }">{{ formatTimestamp(row.processed_at) }}</template>
        </el-table-column>
        <el-table-column label="操作" width="92" align="right" fixed="right">
          <template #default="{ row }">
            <el-button
              link
              type="primary"
              data-action="detail"
              :aria-label="`查看反馈 #${row.id}`"
              @click="openDetail(row)"
            >
              查看
            </el-button>
          </template>
        </el-table-column>
      </el-table>

      <AdminListPager
        v-model:page="page"
        v-model:page-size="limit"
        :total="total"
        @change="onPagerChange"
      />
    </section>

    <el-dialog
      v-model="detailOpen"
      class="feedback-dialog"
      title="反馈详情"
      width="min(720px, calc(100vw - 32px))"
      :append-to-body="false"
    >
      <div v-loading="detailLoading" class="detail-content">
        <el-alert
          v-if="detailError"
          class="detail-error"
          :title="detailError"
          type="error"
          show-icon
          :closable="false"
        />

        <template v-if="detail">
          <dl class="detail-meta">
            <div>
              <dt>提交学员</dt>
              <dd>{{ detail.learner.nickname }} · 账号 #{{ detail.learner.account_id }}</dd>
            </div>
            <div>
              <dt>处理状态</dt>
              <dd>
                <el-tag :type="statusTagType(detail.status)" effect="light" size="small">
                  {{ statusLabel(detail.status) }}
                </el-tag>
              </dd>
            </div>
            <div>
              <dt>提交时间</dt>
              <dd>{{ formatTimestamp(detail.created_at) }}</dd>
            </div>
            <div>
              <dt>处理时间</dt>
              <dd>{{ formatTimestamp(detail.processed_at) }}</dd>
            </div>
            <div v-if="detail.processed_by_staff_id">
              <dt>处理员工</dt>
              <dd>#{{ detail.processed_by_staff_id }}</dd>
            </div>
          </dl>

          <article class="feedback-body" data-role="feedback-body" v-html="detail.body_html" />
        </template>
      </div>

      <template #footer>
        <el-button @click="detailOpen = false">关闭</el-button>
        <el-button
          v-if="detail && nextStatus"
          :type="detail.status === 'pending' ? 'primary' : 'warning'"
          data-action="change-status"
          :loading="updating"
          :disabled="detailLoading"
          @click="changeStatus"
        >
          {{ detail.status === 'pending' ? '标记已处理' : '打回待处理' }}
        </el-button>
      </template>
    </el-dialog>
  </main>
</template>

<style scoped>
.feedback-page {
  display: grid;
  gap: 16px;
  min-width: 0;
}

.page-head {
  display: flex;
  align-items: flex-end;
  justify-content: space-between;
  gap: 16px;
}

.page-head h1 {
  margin: 0;
  color: #172b3a;
  font-size: 22px;
  letter-spacing: 0;
}

.course-reference {
  margin: 5px 0 0;
  color: #718096;
  font-size: 13px;
}

.count {
  display: flex;
  align-items: baseline;
  gap: 5px;
  color: #718096;
  font-size: 13px;
}

.count strong {
  color: #172b3a;
  font-size: 22px;
}

.filter-bar {
  padding: 12px 16px;
  border: 1px solid #dfe6ec;
  border-radius: 8px;
  background: #fff;
}

.filter-bar :deep(.el-form-item) {
  margin: 0;
}

.filter-bar :deep(.el-form-item__label) {
  color: #52667a;
  font-weight: 600;
}

.status-filter {
  width: 160px;
}

.list-error,
.detail-error {
  margin: 0;
}

.table-panel {
  min-width: 0;
  overflow: hidden;
  border: 1px solid #dfe6ec;
  border-radius: 8px;
  background: #fff;
}

.table-panel :deep(.el-table) {
  width: 100%;
}

.learner-cell {
  display: grid;
  gap: 2px;
  min-width: 0;
}

.learner-cell strong,
.learner-cell span {
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.learner-cell strong {
  color: #243b53;
  font-size: 13px;
}

.learner-cell span {
  color: #8294a5;
  font-size: 12px;
}

.excerpt {
  display: block;
  overflow: hidden;
  color: #334e68;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.detail-content {
  min-height: 160px;
}

.detail-meta {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 12px 24px;
  margin: 0 0 18px;
  padding-bottom: 16px;
  border-bottom: 1px solid #e5ebf0;
}

.detail-meta div {
  min-width: 0;
}

.detail-meta dt {
  margin-bottom: 3px;
  color: #8294a5;
  font-size: 12px;
}

.detail-meta dd {
  margin: 0;
  overflow-wrap: anywhere;
  color: #243b53;
  font-size: 13px;
}

.feedback-body {
  min-height: 80px;
  overflow-wrap: anywhere;
  color: #243b53;
  font-size: 14px;
  line-height: 1.7;
}

.feedback-body :deep(:first-child) {
  margin-top: 0;
}

.feedback-body :deep(:last-child) {
  margin-bottom: 0;
}

.feedback-body :deep(img) {
  max-width: 100%;
  height: auto;
}

.feedback-body :deep(a) {
  color: var(--el-color-primary);
}

@media (max-width: 640px) {
  .page-head {
    align-items: flex-start;
  }

  .detail-meta {
    grid-template-columns: 1fr;
  }

  .status-filter {
    width: min(220px, 100%);
  }
}
</style>
