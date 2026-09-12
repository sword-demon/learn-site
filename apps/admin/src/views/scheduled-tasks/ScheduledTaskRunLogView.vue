<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import type {
  AdminScheduledTaskRunDetailDTO,
  AdminScheduledTaskRunListItemDTO,
} from '@learn-site/contracts';
import {
  getScheduledTaskRun,
  listScheduledTasks,
  listScheduledTaskRuns,
  type AdminScheduledTask,
} from '@/api/scheduledTasks';
import AdminListPager from '@/components/AdminListPager.vue';

defineOptions({ name: 'ScheduledTaskRunLogView' });

const CONTEXT_LABELS: Record<string, string> = {
  deleted: '删除条数',
  count: '扫描条数',
  cancelled: '取消订单数',
  refund_windows_closed: '关闭退款窗口数',
  processed: '处理条数',
  sent: '发送条数',
  failed: '失败条数',
  batch_size: '批处理大小',
  skipped: '已跳过',
};

const items = ref<AdminScheduledTaskRunListItemDTO[]>([]);
const tasks = ref<AdminScheduledTask[]>([]);
const total = ref(0);
const loading = ref(false);
const errorMessage = ref('');
const detailOpen = ref(false);
const detailLoading = ref(false);
const detail = ref<AdminScheduledTaskRunDetailDTO | null>(null);

const filters = ref({
  task_id: '' as number | '',
  status: '' as '' | 'success' | 'failed' | 'skipped',
  trigger_type: '' as '' | 'schedule' | 'manual',
  started_from: '',
  started_to: '',
  page: 1,
  per_page: 20,
});

const contextEntries = computed(() => {
  const ctx = detail.value?.context;
  if (!ctx) return [];
  return Object.entries(ctx).map(([key, value]) => ({
    key,
    label: CONTEXT_LABELS[key] ?? key,
    display: formatContextValue(value),
    complex: isComplexContextValue(value),
  }));
});

const dialogTitle = computed(() =>
  detail.value?.task_name ? `执行详情 · ${detail.value.task_name}` : '执行详情',
);

function triggerLabel(value: AdminScheduledTaskRunListItemDTO['trigger_type']): string {
  return value === 'manual' ? '手动' : '自动';
}

function triggerTagType(
  value: AdminScheduledTaskRunListItemDTO['trigger_type'],
): 'warning' | 'info' {
  return value === 'manual' ? 'warning' : 'info';
}

function statusLabel(value: AdminScheduledTaskRunListItemDTO['status']): string {
  if (value === 'success') return '成功';
  if (value === 'failed') return '失败';
  return '已跳过';
}

function statusTagType(
  value: AdminScheduledTaskRunListItemDTO['status'],
): 'success' | 'danger' | 'warning' {
  if (value === 'success') return 'success';
  if (value === 'failed') return 'danger';
  return 'warning';
}

function formatDuration(ms: number | null): string {
  if (ms === null) return '—';
  if (ms < 1000) return `${ms} ms`;
  const seconds = ms / 1000;
  const secondsLabel = Number.isInteger(seconds) ? String(seconds) : seconds.toFixed(1);
  return `${secondsLabel} 秒（${ms} ms）`;
}

function actorLabel(row: AdminScheduledTaskRunDetailDTO): string {
  if (row.actor_login) return row.actor_login;
  if (row.actor_staff_id !== null) return `#${row.actor_staff_id}`;
  return row.trigger_type === 'schedule' ? '系统' : '—';
}

function isComplexContextValue(value: unknown): boolean {
  return value !== null && typeof value === 'object';
}

function formatContextValue(value: unknown): string {
  if (value === null || value === undefined) return '—';
  if (typeof value === 'boolean') return value ? '是' : '否';
  if (typeof value === 'number' || typeof value === 'string') return String(value);
  return JSON.stringify(value, null, 2);
}

async function reload(): Promise<void> {
  loading.value = true;
  errorMessage.value = '';
  try {
    const params: {
      page: number;
      per_page: number;
      task_id?: number;
      status?: 'success' | 'failed' | 'skipped';
      trigger_type?: 'schedule' | 'manual';
      started_from?: string;
      started_to?: string;
    } = {
      page: filters.value.page,
      per_page: filters.value.per_page,
    };
    if (filters.value.task_id) params.task_id = Number(filters.value.task_id);
    if (filters.value.status) params.status = filters.value.status;
    if (filters.value.trigger_type) params.trigger_type = filters.value.trigger_type;
    if (filters.value.started_from) params.started_from = filters.value.started_from;
    if (filters.value.started_to) params.started_to = filters.value.started_to;

    const result = await listScheduledTaskRuns(params);
    items.value = result.items;
    total.value = result.total;
  } catch (err) {
    errorMessage.value = (err as Error).message || '加载失败';
  } finally {
    loading.value = false;
  }
}

async function openDetail(row: AdminScheduledTaskRunListItemDTO): Promise<void> {
  detail.value = null;
  detailOpen.value = true;
  detailLoading.value = true;
  errorMessage.value = '';
  try {
    detail.value = await getScheduledTaskRun(row.id);
  } catch (err) {
    detailOpen.value = false;
    errorMessage.value = (err as Error).message || '加载详情失败';
  } finally {
    detailLoading.value = false;
  }
}

function onDetailClosed(): void {
  detail.value = null;
}

onMounted(async () => {
  try {
    tasks.value = await listScheduledTasks();
  } catch {
    tasks.value = [];
  }
  await reload();
});
</script>

<template>
  <div class="page">
    <header class="page-head">
      <div>
        <h1>自动任务执行日志</h1>
        <p>按任务、结果与时间筛选查看执行记录。</p>
      </div>
    </header>

    <el-alert v-if="errorMessage" type="error" :title="errorMessage" show-icon class="mb-4" />

    <el-card v-loading="loading" shadow="never">
      <div class="filters">
        <el-select v-model="filters.task_id" clearable placeholder="全部任务" style="width: 220px">
          <el-option v-for="task in tasks" :key="task.id" :label="task.name" :value="task.id" />
        </el-select>
        <el-select v-model="filters.status" clearable placeholder="全部结果" style="width: 140px">
          <el-option label="成功" value="success" />
          <el-option label="失败" value="failed" />
          <el-option label="已跳过" value="skipped" />
        </el-select>
        <el-select
          v-model="filters.trigger_type"
          clearable
          placeholder="触发方式"
          style="width: 140px"
        >
          <el-option label="自动" value="schedule" />
          <el-option label="手动" value="manual" />
        </el-select>
        <el-date-picker
          v-model="filters.started_from"
          type="date"
          value-format="YYYY-MM-DD"
          placeholder="开始日期"
        />
        <el-date-picker
          v-model="filters.started_to"
          type="date"
          value-format="YYYY-MM-DD"
          placeholder="结束日期"
        />
        <el-button @click="reload">筛选</el-button>
      </div>

      <el-table :data="items" row-key="id" empty-text="暂无执行记录" class="mt-4">
        <el-table-column prop="task_name" label="任务" min-width="180" />
        <el-table-column label="触发" width="90">
          <template #default="{ row }">{{ triggerLabel(row.trigger_type) }}</template>
        </el-table-column>
        <el-table-column label="结果" width="100">
          <template #default="{ row }">{{ statusLabel(row.status) }}</template>
        </el-table-column>
        <el-table-column prop="started_at" label="开始时间" min-width="160" />
        <el-table-column prop="duration_ms" label="耗时(ms)" width="110" />
        <el-table-column label="操作" width="100">
          <template #default="{ row }">
            <el-button link type="primary" data-action="detail" @click="openDetail(row)"
              >详情</el-button
            >
          </template>
        </el-table-column>
      </el-table>

      <AdminListPager
        v-model:page="filters.page"
        v-model:page-size="filters.per_page"
        :total="total"
        @change="reload"
      />
    </el-card>

    <el-dialog
      v-model="detailOpen"
      :title="dialogTitle"
      width="min(640px, calc(100vw - 32px))"
      :append-to-body="false"
      destroy-on-close
      @closed="onDetailClosed"
    >
      <el-skeleton v-if="detailLoading" :rows="6" animated />
      <div v-else-if="detail" class="run-detail" data-role="run-detail">
        <el-descriptions :column="2" border>
          <el-descriptions-item label="任务" :span="2">{{ detail.task_name }}</el-descriptions-item>
          <el-descriptions-item label="结果">
            <el-tag :type="statusTagType(detail.status)" effect="light" size="small">
              {{ statusLabel(detail.status) }}
            </el-tag>
          </el-descriptions-item>
          <el-descriptions-item label="触发方式">
            <el-tag :type="triggerTagType(detail.trigger_type)" effect="light" size="small">
              {{ triggerLabel(detail.trigger_type) }}
            </el-tag>
          </el-descriptions-item>
          <el-descriptions-item label="操作人">{{ actorLabel(detail) }}</el-descriptions-item>
          <el-descriptions-item label="耗时">{{
            formatDuration(detail.duration_ms)
          }}</el-descriptions-item>
          <el-descriptions-item label="开始时间">{{ detail.started_at }}</el-descriptions-item>
          <el-descriptions-item label="结束时间">{{
            detail.finished_at ?? '—'
          }}</el-descriptions-item>
        </el-descriptions>

        <el-alert
          v-if="detail.status === 'failed'"
          class="detail-alert"
          type="error"
          :title="detail.error_message || '执行失败'"
          show-icon
          :closable="false"
        />
        <el-alert
          v-else-if="detail.status === 'skipped'"
          class="detail-alert"
          type="info"
          title="本次执行已跳过，未进行实际处理。"
          show-icon
          :closable="false"
        />

        <section v-if="contextEntries.length > 0" class="context-section" data-role="run-context">
          <h3>执行结果</h3>
          <el-descriptions :column="2" border>
            <el-descriptions-item
              v-for="entry in contextEntries"
              :key="entry.key"
              :label="entry.label"
              :span="entry.complex ? 2 : 1"
            >
              <pre v-if="entry.complex" class="context-json">{{ entry.display }}</pre>
              <span v-else>{{ entry.display }}</span>
            </el-descriptions-item>
          </el-descriptions>
        </section>
      </div>

      <template #footer>
        <el-button @click="detailOpen = false">关闭</el-button>
      </template>
    </el-dialog>
  </div>
</template>

<style scoped>
.page-head {
  margin-bottom: 16px;
}
.page-head h1 {
  margin: 0 0 4px;
}
.page-head p {
  margin: 0;
  color: var(--el-text-color-secondary);
}
.filters {
  display: flex;
  flex-wrap: wrap;
  gap: 12px;
  align-items: center;
}
.mb-4 {
  margin-bottom: 16px;
}
.mt-4 {
  margin-top: 16px;
}
.run-detail {
  display: grid;
  gap: 16px;
}
.detail-alert {
  margin: 0;
}
.context-section h3 {
  margin: 0 0 8px;
  color: var(--el-text-color-regular);
  font-size: 13px;
  font-weight: 600;
}
.context-json {
  margin: 0;
  padding: 8px 12px;
  border-radius: 4px;
  background: var(--el-fill-color-light);
  color: var(--el-text-color-primary);
  font-size: 12px;
  line-height: 1.6;
  white-space: pre-wrap;
  overflow-wrap: anywhere;
}
</style>
