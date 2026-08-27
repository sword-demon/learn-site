<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
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

const progressLabel = (enrollment: LearnerMapListDTO['items'][number]['enrollment']): string => {
  if (!enrollment) return '尚未加入';
  return `${enrollment.completed_courses}/${enrollment.total_courses} (${enrollment.progress_percent}%)`;
};

function coverAlt(title: string): string {
  return `${title}封面`;
}

const mapCount = computed(() => data.value?.items.length ?? 0);

onMounted(load);
</script>

<template>
  <main class="page maps-page">
    <header class="maps-intro">
      <div>
        <p class="eyebrow"><span class="eyebrow-rule" />学习路径 · MAPS</p>
        <h1 class="display">学习地图</h1>
        <p class="lede">把零散课程排成一条可走完的路，从基础开始，按阶段推进。</p>
      </div>
      <p v-if="!loading && !loadError" class="map-count">
        <strong>{{ mapCount }}</strong> 条路径
      </p>
    </header>

    <p v-if="loading" class="notice">地图加载中…</p>
    <div v-else-if="loadError" class="notice error">
      <p>地图暂时读不到，请稍后再试。</p>
      <button type="button" class="btn btn-ghost" data-action="retry" @click="load">
        重新加载
      </button>
    </div>
    <ol v-else-if="data && data.items.length" class="map-list">
      <li v-for="(map, index) in data.items" :key="map.id" class="map-card">
        <router-link :to="`/maps/${map.id}`" class="map-link">
          <div class="cover-wrap">
            <span class="map-index latin">{{ String(index + 1).padStart(2, '0') }}</span>
            <img
              v-if="map.cover_url"
              class="cover"
              :src="map.cover_url"
              :alt="coverAlt(map.title)"
            />
            <span v-else class="cover-fallback display">路径</span>
          </div>
          <div class="map-body">
            <p class="map-kicker">学习地图 · 分阶段</p>
            <h2>{{ map.title }}</h2>
            <p v-if="map.summary" class="summary">{{ map.summary }}</p>
            <dl class="details">
              <template v-if="map.objective">
                <dt>目标</dt>
                <dd>{{ map.objective }}</dd>
              </template>
              <template v-if="map.audience">
                <dt>适合</dt>
                <dd>{{ map.audience }}</dd>
              </template>
            </dl>
            <p class="meta">
              <span>{{ progressLabel(map.enrollment) }}</span>
              <span class="arrow" aria-hidden="true">→</span>
            </p>
          </div>
        </router-link>
      </li>
    </ol>
    <p v-else class="empty">暂无已发布的学习地图。</p>
  </main>
</template>

<style scoped>
.maps-page {
  display: grid;
  gap: 30px;
}

.maps-intro {
  display: flex;
  align-items: end;
  justify-content: space-between;
  gap: 24px;
  padding: 18px 0 26px;
  border-bottom: 1px solid var(--line);
}

.maps-intro .eyebrow {
  margin-bottom: 17px;
}

.maps-intro .display {
  margin: 0 0 10px;
  color: var(--pine-deep);
  font-size: 2.8rem;
  line-height: 1.12;
}

.map-count {
  flex-shrink: 0;
  margin: 0 0 4px;
  color: var(--muted);
  font-size: 0.8rem;
}

.map-count strong {
  color: var(--accent);
  font-family: var(--font-mono);
  font-size: 1.5rem;
}

.map-list {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
  gap: 20px;
  margin: 0;
  padding: 0;
  list-style: none;
}

.map-card {
  overflow: hidden;
  border: 1px solid var(--line);
  border-radius: 7px;
  background: var(--surface);
  box-shadow: 0 10px 26px rgba(31, 60, 48, 0.07);
  transition:
    transform 0.24s ease,
    box-shadow 0.24s ease;
}

.map-card:hover {
  transform: translateY(-4px);
  box-shadow: 0 18px 34px rgba(31, 60, 48, 0.13);
}

.map-link {
  display: block;
  color: inherit;
  text-decoration: none;
}

.cover-wrap {
  position: relative;
  aspect-ratio: 1.75;
  overflow: hidden;
  background: var(--paper-deep);
}

.cover {
  display: block;
  width: 100%;
  height: 100%;
  object-fit: cover;
  transition: transform 0.4s ease;
}

.map-card:hover .cover {
  transform: scale(1.035);
}

.map-index {
  position: absolute;
  top: 13px;
  left: 14px;
  z-index: 1;
  color: #fffefa;
  font-size: 0.72rem;
  font-weight: 700;
  letter-spacing: 0.13em;
  text-shadow: 0 1px 3px rgba(0, 0, 0, 0.45);
}

.cover-fallback {
  display: flex;
  width: 100%;
  height: 100%;
  align-items: center;
  justify-content: center;
  color: var(--pine);
  font-size: 2rem;
}

.map-body {
  display: grid;
  gap: 8px;
  padding: 16px 17px 17px;
}

.map-kicker {
  margin: 0;
  color: var(--accent);
  font-family: var(--font-mono);
  font-size: 0.68rem;
  font-weight: 700;
  letter-spacing: 0.07em;
}

.map-body h2 {
  margin: 0;
  color: var(--pine-deep);
  font-family: var(--font-display);
  font-size: 1.35rem;
  line-height: 1.3;
}

.summary {
  display: -webkit-box;
  min-height: 2.7em;
  margin: 0;
  overflow: hidden;
  color: var(--muted);
  font-size: 0.82rem;
  line-height: 1.6;
  -webkit-box-orient: vertical;
  -webkit-line-clamp: 2;
}

.details {
  display: grid;
  grid-template-columns: auto 1fr;
  gap: 4px 9px;
  margin: 4px 0;
  color: var(--muted);
  font-size: 0.78rem;
}

.details dd {
  min-width: 0;
  margin: 0;
  overflow: hidden;
  color: var(--ink);
  text-overflow: ellipsis;
  white-space: nowrap;
}

.meta {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  margin: 5px 0 0;
  padding-top: 11px;
  border-top: 1px solid var(--line);
  color: var(--muted);
  font-size: 0.78rem;
}

.arrow {
  color: var(--accent);
  font-size: 1.1rem;
}

@media (max-width: 560px) {
  .maps-intro .display {
    font-size: 2.25rem;
  }

  .maps-intro {
    display: grid;
    align-items: start;
    gap: 8px;
  }
}
</style>
