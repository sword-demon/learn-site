<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { ElMessage } from 'element-plus';
import { fetchCourseOverrides, saveCourseOverride } from '@/api/distribution';
import type { DistributionCourseOverrideDTO } from '@learn-site/contracts';

defineOptions({ name: 'DistributionCourseOverridesView' });

const rows = ref<DistributionCourseOverrideDTO[]>([]);
const loading = ref(false);

async function reload(): Promise<void> {
  loading.value = true;
  try {
    const data = await fetchCourseOverrides();
    rows.value = data.items;
  } finally {
    loading.value = false;
  }
}

async function toggle(row: DistributionCourseOverrideDTO): Promise<void> {
  await saveCourseOverride(row.course_id, { enabled: !row.enabled });
  ElMessage.success('已更新');
  await reload();
}

onMounted(() => void reload());
</script>

<template>
  <main class="page">
    <h1>课程分销覆盖</h1>
    <el-table :data="rows" v-loading="loading">
      <el-table-column prop="course_id" label="课程" />
      <el-table-column label="开关">
        <template #default="{ row }">
          <el-tag :type="row.enabled ? 'success' : 'info'">{{ row.enabled ? 'ON' : 'OFF' }}</el-tag>
        </template>
      </el-table-column>
      <el-table-column label="操作" width="120">
        <template #default="{ row }">
          <el-button text @click="toggle(row)">切换</el-button>
        </template>
      </el-table-column>
    </el-table>
  </main>
</template>

<style scoped>
.page { padding: 24px; }
</style>
