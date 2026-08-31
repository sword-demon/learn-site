<script setup lang="ts">
import { onMounted, ref } from 'vue';
import type { AdminScheduledTaskRunListItemDTO } from '@learn-site/contracts';
import {
  getScheduledTaskRun,
  listScheduledTasks,
  listScheduledTaskRuns,
  type AdminScheduledTask,
} from '@/api/scheduledTasks';

defineOptions({ name: 'ScheduledTaskRunLogView' });

const items = ref<AdminScheduledTaskRunListItemDTO[]>([]);
const tasks = ref<AdminScheduledTask[]>([]);
const total = ref(0);
const loading = ref(false);
const errorMessage = ref('');
const detailOpen = ref(false);
const detailBody = ref('');
const detailMeta = ref('');

const filters = ref({
  task_id: '' as number | '',
  status: '' as '' | 'success' | 'failed' | 'skipped',
  trigger_type: '' as '' | 'schedule' | 'manual',
  started_from: '',
  started_to: '',
  page: 1,
  per_page: 20,
});

function triggerLabel(value: AdminScheduledTaskRunListItemDTO['trigger_type']): string {
  return value === 'manual' ? '手动' : '自动';
}

function statusLabel(value: AdminScheduledTaskRunListItemDTO['status']): string {
  if (value === 'success') return '成功';
  if (value === 'failed') return '失败';
  return '已跳过';
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
  try {
    const detail = await getScheduledTaskRun(row.id);
    detailBody.value = [
      detail.error_message ? `错误：${detail.error_message}` : '执行成功',
      detail.context ? `上下文：${JSON.stringify(detail.context)}` : '',
    ]
      .filter(Boolean)
      .join('\n');
    detailMeta.value = `${detail.task_name} · ${triggerLabel(detail.trigger_type)} · ${statusLabel(detail.status)} · ${detail.started_at}`;
    detailOpen.value = true;
  } catch (err) {
    errorMessage.value = (err as Error).message || '加载详情失败';
  }
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

    <el-card shadow="never" v-loading="loading">
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
            <el-button link type="primary" @click="openDetail(row)">详情</el-button>
          </template>
        </el-table-column>
      </el-table>

      <div class="pager">
        <el-pagination
          v-model:current-page="filters.page"
          :page-size="filters.per_page"
          layout="total, prev, pager, next"
          :total="total"
          @current-change="reload"
        />
      </div>
    </el-card>

    <el-dialog v-model="detailOpen" title="执行详情" width="560px">
      <p class="meta">{{ detailMeta }}</p>
      <pre class="detail-body">{{ detailBody }}</pre>
    </el-dialog>
  </div>
</template>

<style scoped>
.page-head {
  margin-bottom: 16px;
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
.pager {
  margin-top: 16px;
  display: flex;
  justify-content: flex-end;
}
.meta {
  margin-bottom: 12px;
  color: var(--el-text-color-secondary);
}
.detail-body {
  white-space: pre-wrap;
  font-family: inherit;
}
</style>
