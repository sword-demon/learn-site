<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { fetchAudit } from '@/api/distribution';
import type { DistributionAuditDTO } from '@learn-site/contracts';

defineOptions({ name: 'DistributionAuditView' });

const items = ref<DistributionAuditDTO[]>([]);
const total = ref(0);
const page = ref(1);
const loading = ref(false);

async function reload(): Promise<void> {
  loading.value = true;
  try {
    const data = await fetchAudit({ page: page.value, limit: 20 });
    items.value = data.items;
    total.value = data.total;
  } finally {
    loading.value = false;
  }
}

onMounted(() => void reload());
</script>

<template>
  <main class="page">
    <h1>分销审计</h1>
    <el-table v-loading="loading" :data="items">
      <el-table-column prop="action" label="动作" />
      <el-table-column prop="actor_type" label="操作者" />
      <el-table-column prop="reason" label="原因" />
      <el-table-column prop="created_at" label="时间" />
    </el-table>
    <el-pagination
      v-model:current-page="page"
      :total="total"
      :page-size="20"
      layout="prev, pager, next"
      @current-change="reload"
    />
  </main>
</template>

<style scoped>
.page {
  padding: 24px;
}
</style>
