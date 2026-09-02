<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
import { useRoute } from 'vue-router';
import { listLearnerCourseProgress } from '@/api/learners';
import AdminListPager from '@/components/AdminListPager.vue';
import type { LearnerCourseProgressDTO, LearnerCourseProgressListDTO } from '@learn-site/contracts';

defineOptions({ name: 'LearnerProgressView' });

const route = useRoute();
const learnerId = computed(() => Number(route.params.id));

const list = ref<LearnerCourseProgressListDTO | null>(null);
const loading = ref(false);
const errorMsg = ref<string | null>(null);
const page = ref(1);
const limit = ref(20);

const total = computed(() => list.value?.total ?? 0);
const learnerTitle = computed(() => {
  const learner = list.value?.learner;
  if (!learner) return `学员 #${learnerId.value}`;
  const name = learner.display_name || learner.login;
  return `${name}（${learner.login}）`;
});

function sourceLabel(source: LearnerCourseProgressDTO['source']): string {
  return source === 'free' ? '免费加入' : '付费取得';
}

function entitlementLabel(status: LearnerCourseProgressDTO['entitlement_status']): string {
  return status === 'active' ? '有效' : '已撤销';
}

function learningLabel(status: LearnerCourseProgressDTO['learning_status']): string {
  if (status === 'completed') return '已完成';
  if (status === 'in_progress') return '学习中';
  return '未开始';
}

async function reload(): Promise<void> {
  loading.value = true;
  errorMsg.value = null;
  try {
    list.value = await listLearnerCourseProgress(learnerId.value, {
      page: page.value,
      limit: limit.value,
    });
  } catch (err) {
    const code = (err as { code?: string }).code;
    if (code === 'NOT_FOUND') {
      errorMsg.value = '学员不存在或无权查看';
    } else {
      errorMsg.value = (err as Error).message || '加载失败，请稍后再试';
    }
    list.value = null;
  } finally {
    loading.value = false;
  }
}

watch(learnerId, () => {
  page.value = 1;
  void reload();
});

onMounted(() => {
  void reload();
});
</script>

<template>
  <main class="page">
    <header class="head">
      <h1 class="display">学员学习进度</h1>
      <p class="muted">{{ learnerTitle }} · 共 {{ total }} 门课程</p>
    </header>

    <p v-if="errorMsg" class="error">{{ errorMsg }}</p>
    <p v-else-if="loading" class="notice">加载中…</p>

    <el-table v-else v-loading="loading" :data="list?.items ?? []" stripe class="data">
      <el-table-column prop="course_title" label="课程" min-width="180" />
      <el-table-column label="来源" min-width="100">
        <template #default="{ row }">{{ sourceLabel(row.source) }}</template>
      </el-table-column>
      <el-table-column label="授权" min-width="100">
        <template #default="{ row }">{{ entitlementLabel(row.entitlement_status) }}</template>
      </el-table-column>
      <el-table-column label="进度" min-width="80">
        <template #default="{ row }">{{ row.progress_percent }}%</template>
      </el-table-column>
      <el-table-column label="学习状态" min-width="110">
        <template #default="{ row }">{{ learningLabel(row.learning_status) }}</template>
      </el-table-column>
      <el-table-column label="最近学习" min-width="170">
        <template #default="{ row }">{{ row.last_learning_at || '—' }}</template>
      </el-table-column>
      <el-table-column label="完成时间" min-width="170">
        <template #default="{ row }">{{ row.completed_at || '—' }}</template>
      </el-table-column>
      <el-table-column prop="enrolled_at" label="加入时间" min-width="170" />
      <template #empty><el-empty description="暂无学习进度" :image-size="88" /></template>
    </el-table>

    <AdminListPager
      v-model:page="page"
      v-model:page-size="limit"
      :total="total"
      @change="reload"
    />
  </main>
</template>

<style scoped>
.page {
  display: grid;
  gap: 16px;
}
.head {
  display: flex;
  align-items: baseline;
  justify-content: space-between;
}
.display {
  margin: 0;
  font-size: 1.4rem;
}
.muted {
  color: var(--color-text-muted, #5b6472);
  margin: 0;
  font-size: 0.85rem;
}
.error {
  color: #b42318;
  margin: 0;
}
.notice {
  color: var(--color-text-muted, #5b6472);
  margin: 0;
}
.data {
  width: 100%;
  background: #fff;
}
</style>
