<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { fetchLearningMaps } from '@/api/learner';
import type { LearnerMapListDTO } from '@learn-site/contracts';

defineOptions({ name: 'MapListView' });

const data = ref<LearnerMapListDTO | null>(null);
const loading = ref(false);
const loadError = ref<string | null>(null);

async function load(): Promise<void> {
  loading.value = true;
  loadError.value = null;
  try {
    data.value = await fetchLearningMaps(1, 50);
  } catch (err) {
    loadError.value = (err as Error).message || 'load_failed';
  } finally {
    loading.value = false;
  }
}

const statusLabel = (s: string): string => (s === 'published' ? '已发布' : '草稿');
const progressLabel = (e: LearnerMapListDTO['items'][number]['enrollment']): string => {
  if (!e) return '未加入';
  return `${e.completed_courses}/${e.total_courses} (${e.progress_percent}%)`;
};

function coverAlt(title: string): string {
  return `${title}封面`;
}

onMounted(load);
</script>

<template>
  <main class="page maps-page">
    <header class="head">
      <h1 class="display">学习地图</h1>
      <p class="lede">按阶段组织的课程合集, 加入后逐节推进.</p>
    </header>

    <p v-if="loading" class="notice">地图加载中…</p>
    <div v-else-if="loadError" class="notice error">
      <p>地图暂时读不到 ({{ loadError }}).</p>
      <button type="button" class="btn btn-ghost" data-action="retry" @click="load">重试</button>
    </div>
    <ol v-else-if="data && data.items.length" class="map-list">
      <li v-for="m in data.items" :key="m.id" class="map-card">
        <router-link :to="`/maps/${m.id}`" class="map-link">
          <img v-if="m.cover_url" class="cover" :src="m.cover_url" :alt="coverAlt(m.title)" />
          <h2>{{ m.title }}</h2>
          <p v-if="m.summary" class="summary">{{ m.summary }}</p>
          <dl class="details">
            <template v-if="m.objective">
              <dt>学习目标</dt>
              <dd>{{ m.objective }}</dd>
            </template>
            <template v-if="m.audience">
              <dt>适合人群</dt>
              <dd>{{ m.audience }}</dd>
            </template>
          </dl>
          <p class="meta">
            <span class="badge" :data-status="m.status">{{ statusLabel(m.status) }}</span>
            <span>进度: {{ progressLabel(m.enrollment) }}</span>
          </p>
        </router-link>
      </li>
    </ol>
    <p v-else class="notice">暂无已发布的学习地图.</p>
  </main>
</template>

<style scoped>
.maps-page {
  display: grid;
  gap: 16px;
}
.head .display {
  margin: 0;
}
.lede {
  color: var(--color-text-muted, #5b6472);
}
.map-list {
  list-style: none;
  padding: 0;
  margin: 0;
  display: grid;
  gap: 12px;
  grid-template-columns: repeat(auto-fill, minmax(260px, 1fr));
}
.map-card {
  border: 1px solid var(--color-border, #d0d4dc);
  border-radius: 8px;
  background: #fff;
  transition: transform 0.12s ease;
}
.map-card:hover {
  transform: translateY(-2px);
}
.map-link {
  display: grid;
  gap: 6px;
  padding: 16px;
  color: inherit;
  text-decoration: none;
}
.cover {
  width: 100%;
  aspect-ratio: 16 / 7;
  object-fit: cover;
  border-radius: 6px;
  background: var(--color-bg-soft, #f5f6fa);
}
.map-link h2 {
  margin: 0;
  font-size: 1.05rem;
}
.summary {
  color: var(--color-text-muted, #5b6472);
  margin: 0;
}
.details {
  display: grid;
  grid-template-columns: auto 1fr;
  gap: 4px 8px;
  margin: 2px 0;
  font-size: 0.88rem;
}
.details dt {
  color: var(--color-text-muted, #5b6472);
}
.details dd {
  margin: 0;
}
.meta {
  display: flex;
  gap: 12px;
  align-items: center;
  color: var(--color-text-muted, #5b6472);
  font-size: 0.85rem;
  margin: 0;
}
.badge {
  padding: 2px 8px;
  border-radius: 999px;
  background: var(--color-bg-soft, #f5f6fa);
  border: 1px solid var(--color-border, #d0d4dc);
}
.badge[data-status='published'] {
  background: #e7f7ee;
  border-color: #2bb673;
}
.badge[data-status='draft'] {
  background: #f0f1f3;
  border-color: #c5c8d0;
}
.notice {
  color: var(--color-text-muted, #5b6472);
}
.notice.error {
  color: #b42318;
}
.btn {
  padding: 6px 12px;
  border-radius: 6px;
  border: 1px solid var(--color-border, #d0d4dc);
  background: transparent;
  color: inherit;
  font: inherit;
  cursor: pointer;
}
</style>
