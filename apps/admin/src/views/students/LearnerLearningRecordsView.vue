<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
import { useRoute } from 'vue-router';
import { listLearnerLessonRecords } from '@/api/learners';
import AdminListPager from '@/components/AdminListPager.vue';
import type { LearnerLessonRecordListDTO } from '@learn-site/contracts';

defineOptions({ name: 'LearnerLearningRecordsView' });

const route = useRoute();
const learnerId = computed(() => Number(route.params.id));

const list = ref<LearnerLessonRecordListDTO | null>(null);
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

async function reload(): Promise<void> {
  loading.value = true;
  errorMsg.value = null;
  try {
    list.value = await listLearnerLessonRecords(learnerId.value, {
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
      <h1 class="display">学员学习记录</h1>
      <p class="muted">{{ learnerTitle }} · 共 {{ total }} 条课节记录</p>
    </header>

    <p v-if="errorMsg" class="error">{{ errorMsg }}</p>
    <p v-else-if="loading" class="notice">加载中…</p>

    <el-table v-else v-loading="loading" :data="list?.items ?? []" stripe class="data">
      <el-table-column prop="course_title" label="课程" min-width="160" />
      <el-table-column prop="lesson_title" label="课节" min-width="180" />
      <el-table-column label="首次打开" min-width="170">
        <template #default="{ row }">{{ row.opened_at || '—' }}</template>
      </el-table-column>
      <el-table-column label="完成状态" min-width="100">
        <template #default="{ row }">{{ row.completed ? '已完成' : '未完成' }}</template>
      </el-table-column>
      <el-table-column label="完成时间" min-width="170">
        <template #default="{ row }">{{ row.completed_at || '—' }}</template>
      </el-table-column>
      <el-table-column prop="updated_at" label="最近更新" min-width="170" />
      <template #empty><el-empty description="暂无学习记录" :image-size="88" /></template>
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
