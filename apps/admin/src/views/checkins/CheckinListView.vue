<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { ElMessage, ElMessageBox } from 'element-plus';
import type { AdminCheckinListItemDTO } from '@learn-site/contracts';
import { deleteCheckin, getCheckin, listCheckins } from '@/api/checkins';

defineOptions({ name: 'CheckinListView' });

const items = ref<AdminCheckinListItemDTO[]>([]);
const total = ref(0);
const loading = ref(false);
const errorMessage = ref('');
const detailOpen = ref(false);
const detailHtml = ref('');
const detailMeta = ref('');

const filters = ref({
  learner_id: '',
  date_from: '',
  date_to: '',
  page: 1,
  limit: 20,
});

async function reload(): Promise<void> {
  loading.value = true;
  errorMessage.value = '';
  try {
    const params: {
      page: number;
      limit: number;
      learner_id?: number;
      date_from?: string;
      date_to?: string;
    } = { page: filters.value.page, limit: filters.value.limit };
    if (filters.value.learner_id.trim()) {
      params.learner_id = Number(filters.value.learner_id);
    }
    if (filters.value.date_from) params.date_from = filters.value.date_from;
    if (filters.value.date_to) params.date_to = filters.value.date_to;
    const result = await listCheckins(params);
    items.value = result.items;
    total.value = result.total;
  } catch (err) {
    errorMessage.value = (err as Error).message || '加载失败';
  } finally {
    loading.value = false;
  }
}

async function openDetail(row: AdminCheckinListItemDTO): Promise<void> {
  try {
    const detail = await getCheckin(row.id);
    detailHtml.value = detail.plan_html;
    detailMeta.value = `${detail.learner_display_name ?? '学员'} · ${detail.learner_phone_masked} · ${detail.checkin_date}`;
    detailOpen.value = true;
  } catch (err) {
    errorMessage.value = (err as Error).message || '加载详情失败';
  }
}

async function confirmDelete(row: AdminCheckinListItemDTO): Promise<void> {
  try {
    await ElMessageBox.confirm('删除后该学员可在对应日期重新签到，确定删除？', '删除签到记录', {
      type: 'warning',
      confirmButtonText: '删除',
      cancelButtonText: '取消',
    });
    await deleteCheckin(row.id);
    ElMessage.success('已删除');
    await reload();
  } catch (err) {
    if ((err as string) === 'cancel') return;
    ElMessage.error((err as Error).message || '删除失败');
  }
}

function formatDateTime(value: string): string {
  if (!value) return '—';
  return value.replace('T', ' ').replace(/\+\d{2}:\d{2}$/, '').slice(0, 19);
}

onMounted(() => {
  void reload();
});
</script>

<template>
  <div class="page">
    <header class="page-head">
      <div>
        <h1>签到管理</h1>
        <p>查看学员每日签到记录，必要时可删除不当内容。</p>
      </div>
    </header>

    <el-form :inline="true" class="filters" @submit.prevent="reload">
      <el-form-item label="学员 ID">
        <el-input v-model="filters.learner_id" placeholder="可选" clearable />
      </el-form-item>
      <el-form-item label="开始日期">
        <el-date-picker
          v-model="filters.date_from"
          type="date"
          value-format="YYYY-MM-DD"
          clearable
        />
      </el-form-item>
      <el-form-item label="结束日期">
        <el-date-picker v-model="filters.date_to" type="date" value-format="YYYY-MM-DD" clearable />
      </el-form-item>
      <el-form-item>
        <el-button type="primary" @click="reload">查询</el-button>
      </el-form-item>
    </el-form>

    <el-alert v-if="errorMessage" type="error" :title="errorMessage" show-icon :closable="false" />

    <el-table v-loading="loading" :data="items" stripe>
      <el-table-column prop="checkin_date" label="签到日期" width="120" />
      <el-table-column prop="learner_display_name" label="学员" width="120" />
      <el-table-column prop="learner_phone_masked" label="手机号" width="140" />
      <el-table-column prop="plan_summary" label="计划摘要" min-width="220" show-overflow-tooltip />
      <el-table-column prop="checked_in_at" label="签到时间" width="180">
        <template #default="{ row }">{{ formatDateTime(row.checked_in_at) }}</template>
      </el-table-column>
      <el-table-column label="操作" width="160" fixed="right">
        <template #default="{ row }">
          <el-button link type="primary" @click="openDetail(row)">详情</el-button>
          <el-button link type="danger" @click="confirmDelete(row)">删除</el-button>
        </template>
      </el-table-column>
    </el-table>

    <el-pagination
      v-model:current-page="filters.page"
      class="pager"
      :page-size="filters.limit"
      layout="total, prev, pager, next"
      :total="total"
      @current-change="reload"
    />

    <el-drawer v-model="detailOpen" title="签到详情" size="40%">
      <p class="detail-meta">{{ detailMeta }}</p>
      <!-- eslint-disable-next-line vue/no-v-html -- server-side HtmlSanitizer before persist -->
      <div class="detail-html" v-html="detailHtml" />
    </el-drawer>
  </div>
</template>

<style scoped>
.page {
  padding: 8px 4px 24px;
}

.page-head h1 {
  margin: 0 0 6px;
}

.page-head p {
  margin: 0;
  color: var(--el-text-color-secondary);
}

.filters {
  margin: 16px 0;
}

.pager {
  margin-top: 16px;
  justify-content: flex-end;
}

.detail-meta {
  margin: 0 0 12px;
  color: var(--el-text-color-secondary);
}

.detail-html {
  line-height: 1.7;
}
</style>
