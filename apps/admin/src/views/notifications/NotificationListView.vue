<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { ElMessage } from 'element-plus';
import type { AdminNotificationListItemDTO } from '@learn-site/contracts';
import { getNotification, listNotifications, retryNotificationFanOut } from '@/api/notifications';
import NotificationComposeDialog from '@/views/notifications/NotificationComposeDialog.vue';
import AdminListPager from '@/components/AdminListPager.vue';

defineOptions({ name: 'NotificationListView' });

const items = ref<AdminNotificationListItemDTO[]>([]);
const total = ref(0);
const loading = ref(false);
const errorMessage = ref('');
const composeOpen = ref(false);
const detailOpen = ref(false);
const detailTitle = ref('');
const detailBody = ref('');
const detailMeta = ref('');
const retryingId = ref<number | null>(null);

const filters = ref({
  type: '' as '' | 'announcement' | 'internal_message' | 'course_published',
  from: '',
  to: '',
  page: 1,
  limit: 20,
});

function typeLabel(type: AdminNotificationListItemDTO['type']): string {
  if (type === 'announcement') return '公告';
  if (type === 'course_published') return '课程发布';
  return '站内信';
}

function fanOutLabel(status: AdminNotificationListItemDTO['fan_out_status']): string {
  const labels: Record<string, string> = {
    pending: '待投递',
    running: '投递中',
    completed: '已完成',
    failed: '失败',
  };
  return status ? (labels[status] ?? status) : '—';
}

function fanOutTagType(
  status: AdminNotificationListItemDTO['fan_out_status'],
): 'primary' | 'success' | 'warning' | 'danger' | 'info' {
  if (status === 'completed') return 'success';
  if (status === 'failed') return 'danger';
  if (status === 'running') return 'primary';
  if (status === 'pending') return 'warning';
  return 'info';
}

function isRetryable(row: AdminNotificationListItemDTO): boolean {
  return row.fan_out_status === 'failed' || row.fan_out_status === 'pending';
}

async function reload(): Promise<void> {
  loading.value = true;
  errorMessage.value = '';
  try {
    const params: {
      page: number;
      limit: number;
      type?: 'announcement' | 'internal_message' | 'course_published';
      from?: string;
      to?: string;
    } = { page: filters.value.page, limit: filters.value.limit };
    if (filters.value.type) params.type = filters.value.type;
    if (filters.value.from) params.from = filters.value.from;
    if (filters.value.to) params.to = filters.value.to;
    const result = await listNotifications(params);
    items.value = result.items;
    total.value = result.total;
  } catch (err) {
    errorMessage.value = (err as Error).message || '加载失败';
  } finally {
    loading.value = false;
  }
}

async function openDetail(row: AdminNotificationListItemDTO): Promise<void> {
  try {
    const detail = await getNotification(row.id);
    detailTitle.value = detail.title;
    detailBody.value = detail.body;
    detailMeta.value = `${typeLabel(detail.type)} · ${detail.recipient_summary} · ${detail.created_at}`;
    detailOpen.value = true;
  } catch (err) {
    errorMessage.value = (err as Error).message || '加载详情失败';
  }
}

async function retryFanOut(row: AdminNotificationListItemDTO): Promise<void> {
  retryingId.value = row.id;
  try {
    await retryNotificationFanOut(row.id);
    ElMessage.success('已重新加入投递队列');
    await reload();
  } catch (err) {
    const code = (err as { code?: string }).code;
    if (code === 'DISPATCH_NOT_RETRYABLE') {
      ElMessage.warning('当前状态不支持重试（已完成或投递中）');
    } else {
      ElMessage.error('重试失败，请稍后再试');
    }
  } finally {
    retryingId.value = null;
  }
}

onMounted(() => {
  void reload();
});
</script>

<template>
  <div class="page">
    <header class="page-head">
      <div>
        <h1>通知管理</h1>
        <p>发送公告与站内信，并查看历史发送记录。</p>
      </div>
      <el-button type="primary" @click="composeOpen = true">发送通知</el-button>
    </header>

    <el-card shadow="never">
      <div class="filters">
        <el-select v-model="filters.type" clearable placeholder="全部类型" style="width: 160px">
          <el-option label="公告" value="announcement" />
          <el-option label="站内信" value="internal_message" />
          <el-option label="课程发布" value="course_published" />
        </el-select>
        <el-date-picker
          v-model="filters.from"
          type="date"
          value-format="YYYY-MM-DD"
          placeholder="开始日期"
        />
        <el-date-picker
          v-model="filters.to"
          type="date"
          value-format="YYYY-MM-DD"
          placeholder="结束日期"
        />
        <el-button @click="reload">筛选</el-button>
      </div>

      <p v-if="errorMessage" class="error">{{ errorMessage }}</p>
      <el-table v-loading="loading" :data="items" empty-text="暂无发送记录">
        <el-table-column prop="type" label="类型" width="110">
          <template #default="{ row }">{{ typeLabel(row.type) }}</template>
        </el-table-column>
        <el-table-column prop="title" label="标题" min-width="200" />
        <el-table-column prop="recipient_summary" label="目标范围" width="140" />
        <el-table-column label="投递进度" width="150">
          <template #default="{ row }">
            <el-tag :type="fanOutTagType(row.fan_out_status)" size="small">
              {{ fanOutLabel(row.fan_out_status) }}
            </el-tag>
            <span v-if="row.fan_out_done_count != null" class="fan-out-count">
              {{ row.fan_out_done_count }}/{{ row.recipient_count }}
            </span>
          </template>
        </el-table-column>
        <el-table-column prop="sender_login" label="发送人" width="120" />
        <el-table-column prop="created_at" label="发送时间" width="180" />
        <el-table-column label="操作" width="120" align="center">
          <template #default="{ row }">
            <el-button
              v-if="isRetryable(row)"
              size="small"
              type="primary"
              link
              :loading="retryingId === row.id"
              @click.stop="retryFanOut(row)"
            >
              重试投递
            </el-button>
            <el-button size="small" link @click.stop="openDetail(row)">详情</el-button>
          </template>
        </el-table-column>
      </el-table>

      <AdminListPager
        v-model:page="filters.page"
        v-model:page-size="filters.limit"
        :total="total"
        @change="reload"
      />
    </el-card>

    <NotificationComposeDialog v-model="composeOpen" @sent="reload" />

    <el-dialog v-model="detailOpen" :title="detailTitle" width="640px">
      <p class="meta">{{ detailMeta }}</p>
      <pre class="body">{{ detailBody }}</pre>
    </el-dialog>
  </div>
</template>

<style scoped>
.page {
  display: grid;
  gap: 16px;
}
.page-head {
  display: flex;
  justify-content: space-between;
  align-items: start;
  gap: 16px;
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
  margin-bottom: 16px;
}
.pager {
  display: flex;
  justify-content: flex-end;
  margin-top: 16px;
}
.error {
  color: var(--el-color-danger);
}
.meta {
  color: var(--el-text-color-secondary);
  margin-top: 0;
}
.body {
  white-space: pre-wrap;
  font-family: inherit;
  margin: 0;
}
.fan-out-count {
  margin-left: 6px;
  color: var(--el-text-color-secondary);
  font-size: 12px;
}
</style>
