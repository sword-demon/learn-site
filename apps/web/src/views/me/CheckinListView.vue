<script setup lang="ts">
import { inject, onMounted, onUnmounted, ref } from 'vue';
import type { LearnerCheckinDTO } from '@learn-site/contracts';
import MarkdownRenderer from '@/components/MarkdownRenderer.vue';
import { listCheckins } from '@/api/checkins';
import { hasRichHtml } from '@/utils/richHtml';

defineOptions({ name: 'CheckinListView' });

type CheckinPrompt = {
  dialogVisible: { value: boolean };
  afterSuccess: (hook: () => void) => () => void;
};

const checkinPrompt = inject<CheckinPrompt>('dailyCheckinPrompt');

const items = ref<LearnerCheckinDTO[]>([]);
const total = ref(0);
const page = ref(1);
const limit = ref(20);
const loading = ref(false);
const errorMessage = ref('');

async function reload(): Promise<void> {
  loading.value = true;
  errorMessage.value = '';
  try {
    const result = await listCheckins(page.value, limit.value);
    items.value = result.items;
    total.value = result.total;
  } catch (err) {
    errorMessage.value = (err as Error).message || '加载失败';
  } finally {
    loading.value = false;
  }
}

function openCheckin(): void {
  if (checkinPrompt) {
    checkinPrompt.dialogVisible.value = true;
  }
}

let unsubscribeSuccess: (() => void) | undefined;

onMounted(() => {
  void reload();
  unsubscribeSuccess = checkinPrompt?.afterSuccess(() => {
    void reload();
  });
});

onUnmounted(() => unsubscribeSuccess?.());
</script>

<template>
  <main class="page">
    <header class="page-head">
      <div>
        <h1>每日签到</h1>
        <p>回顾你的学习计划，坚持每日打卡。</p>
      </div>
      <el-button type="primary" @click="openCheckin">去签到</el-button>
    </header>

    <el-alert v-if="errorMessage" type="error" :title="errorMessage" show-icon :closable="false" />

    <el-skeleton v-if="loading && items.length === 0" :rows="4" animated />

    <el-empty v-else-if="!loading && items.length === 0" description="还没有签到记录">
      <el-button type="primary" @click="openCheckin">立即签到</el-button>
    </el-empty>

    <section v-else class="list">
      <article v-for="item in items" :key="item.id" class="card">
        <header class="card-head">
          <time>{{ item.checkin_date }}</time>
          <span>{{ item.checked_in_at }}</span>
        </header>
        <MarkdownRenderer v-if="hasRichHtml(item.plan_html)" :html="item.plan_html" />
        <p v-else class="empty-plan">（无计划内容）</p>
      </article>
    </section>

    <footer v-if="total > limit" class="pager">
      <el-pagination
        v-model:current-page="page"
        :page-size="limit"
        layout="prev, pager, next"
        :total="total"
        @current-change="reload"
      />
    </footer>
  </main>
</template>

<style scoped>
.page {
  max-width: 760px;
  margin: 0 auto;
  padding: 24px 16px 48px;
}

.page-head {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 16px;
  margin-bottom: 20px;
}

.page-head h1 {
  margin: 0 0 6px;
  font-size: 1.6rem;
  color: var(--pine-deep);
}

.page-head p {
  margin: 0;
  color: var(--ink-soft);
}

.list {
  display: grid;
  gap: 14px;
}

.card {
  border: 1px solid var(--line, #d9e5df);
  border-radius: 12px;
  background: var(--paper, #fff);
  padding: 16px;
}

.card-head {
  display: flex;
  justify-content: space-between;
  gap: 12px;
  margin-bottom: 10px;
  color: var(--ink-soft);
  font-size: 0.9rem;
}

.empty-plan {
  margin: 0;
  color: var(--ink-soft);
}
</style>
