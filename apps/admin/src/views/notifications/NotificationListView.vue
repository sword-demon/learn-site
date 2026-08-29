<script setup lang="ts">
import { onMounted, ref } from 'vue';
import type { AdminNotificationListItemDTO } from '@learn-site/contracts';
import { getNotification, listNotifications } from '@/api/notifications';
import NotificationComposeDialog from '@/views/notifications/NotificationComposeDialog.vue';

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

const filters = ref({
  type: '' as '' | 'announcement' | 'internal_message',
  from: '',
  to: '',
  page: 1,
  limit: 20,
});

function typeLabel(type: AdminNotificationListItemDTO['type']): string {
  return type === 'announcement' ? '公告' : '站内信';
}

async function reload(): Promise<void> {
  loading.value = true;
  errorMessage.value = '';
  try {
    const params: {
      page: number;
      limit: number;
      type?: 'announcement' | 'internal_message';
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
      <el-table v-loading="loading" :data="items" empty-text="暂无发送记录" @row-click="openDetail">
        <el-table-column prop="type" label="类型" width="100">
          <template #default="{ row }">{{ typeLabel(row.type) }}</template>
        </el-table-column>
        <el-table-column prop="title" label="标题" min-width="220" />
        <el-table-column prop="recipient_summary" label="目标范围" width="140" />
        <el-table-column prop="sender_login" label="发送人" width="120" />
        <el-table-column prop="created_at" label="发送时间" width="180" />
      </el-table>

      <div class="pager">
        <el-pagination
          v-model:current-page="filters.page"
          :page-size="filters.limit"
          :total="total"
          layout="total, prev, pager, next"
          @current-change="reload"
        />
      </div>
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
</style>
