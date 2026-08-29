<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { useRouter } from 'vue-router';
import type { AdminScheduledTaskDTO } from '@learn-site/contracts';
import {
  listScheduledTasks,
  runScheduledTask,
} from '@/api/scheduledTasks';
import ScheduledTaskEditDialog from '@/views/scheduled-tasks/ScheduledTaskEditDialog.vue';

defineOptions({ name: 'ScheduledTaskListView' });

const router = useRouter();
const items = ref<AdminScheduledTaskDTO[]>([]);
const loading = ref(false);
const runningId = ref<number | null>(null);
const errorMessage = ref('');
const editOpen = ref(false);
const editingTask = ref<AdminScheduledTaskDTO | null>(null);

function statusLabel(status: string | null | undefined): string {
  if (!status) return '尚未执行';
  if (status === 'success') return '成功';
  if (status === 'failed') return '失败';
  return '已跳过';
}

function statusTagType(status: string | null | undefined): 'success' | 'danger' | 'warning' | 'info' {
  if (status === 'success') return 'success';
  if (status === 'failed') return 'danger';
  if (status === 'skipped') return 'warning';
  return 'info';
}

async function reload(): Promise<void> {
  loading.value = true;
  errorMessage.value = '';
  try {
    items.value = await listScheduledTasks();
  } catch (err) {
    errorMessage.value = (err as Error).message || '加载失败';
  } finally {
    loading.value = false;
  }
}

function openEdit(row: AdminScheduledTaskDTO): void {
  editingTask.value = row;
  editOpen.value = true;
}

async function onSaved(): Promise<void> {
  editOpen.value = false;
  await reload();
}

async function handleRun(row: AdminScheduledTaskDTO): Promise<void> {
  runningId.value = row.id;
  errorMessage.value = '';
  try {
    await runScheduledTask(row.id);
    await reload();
  } catch (err) {
    errorMessage.value = (err as Error).message || '执行失败';
  } finally {
    runningId.value = null;
  }
}

function goRuns(): void {
  void router.push({ name: 'scheduled-task-runs' });
}

onMounted(() => {
  void reload();
});
</script>

<template>
  <div class="page">
    <header class="page-head">
      <div>
        <h1>自动任务</h1>
        <p>查看并配置站点后台定时任务，预览下次执行时间与执行日志。</p>
      </div>
      <div class="head-actions">
        <el-button @click="goRuns">执行日志</el-button>
      </div>
    </header>

    <el-alert v-if="errorMessage" type="error" :title="errorMessage" show-icon class="mb-4" />

    <el-card shadow="never" v-loading="loading">
      <el-table :data="items" row-key="id" empty-text="暂无自动任务">
        <el-table-column prop="name" label="任务名称" min-width="180" />
        <el-table-column prop="handler_code" label="类型" min-width="160" />
        <el-table-column prop="schedule_expression" label="调度表达式" min-width="160" />
        <el-table-column label="启用" width="90">
          <template #default="{ row }">
            <el-tag :type="row.enabled ? 'success' : 'info'">{{ row.enabled ? '启用' : '停用' }}</el-tag>
          </template>
        </el-table-column>
        <el-table-column label="最近执行" min-width="120">
          <template #default="{ row }">
            <el-tag :type="statusTagType(row.last_run_status)">{{ statusLabel(row.last_run_status) }}</el-tag>
          </template>
        </el-table-column>
        <el-table-column prop="next_run_at" label="下次执行" min-width="160">
          <template #default="{ row }">
            {{ row.next_run_at ?? '—' }}
          </template>
        </el-table-column>
        <el-table-column label="操作" width="200" fixed="right">
          <template #default="{ row }">
            <el-button link type="primary" @click="openEdit(row)">编辑</el-button>
            <el-button
              link
              type="primary"
              :loading="runningId === row.id"
              :disabled="row.handler_status !== 'available'"
              @click="handleRun(row)"
            >
              立即执行
            </el-button>
          </template>
        </el-table-column>
      </el-table>
    </el-card>

    <ScheduledTaskEditDialog
      v-if="editingTask"
      v-model="editOpen"
      :task="editingTask"
      @saved="onSaved"
    />
  </div>
</template>

<style scoped>
.page-head {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  gap: 16px;
  margin-bottom: 16px;
}
.head-actions {
  display: flex;
  gap: 8px;
}
.mb-4 {
  margin-bottom: 16px;
}
</style>
