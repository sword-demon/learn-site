<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { fetchLearningMaps } from '@/api/learner';
import type { LearnerMapListDTO } from '@learn-site/contracts';

defineOptions({ name: 'MapListView' });

const MAP_HUES = ['#5B8FF9', '#5AD8A6', '#F6BD16', '#E86452', '#6DC8EC', '#945FB9'] as const;

const items = ref<LearnerMapListDTO['items']>([]);
const loading = ref(false);
const loadError = ref(false);

async function load(): Promise<void> {
  loading.value = true;
  loadError.value = false;
  try {
    const data = await fetchLearningMaps(1, 50);
    items.value = data.items;
  } catch {
    loadError.value = true;
    items.value = [];
  } finally {
    loading.value = false;
  }
}

function retry(): void {
  void load();
}

function coverGlyph(title: string): string {
  return title.slice(0, 2);
}

onMounted(load);
</script>

<template>
  <main class="page maps-page">
    <header class="maps-page__head">
      <h1>拾阶而上</h1>
      <p>按主题路径，了解一个领域的关键脉络。</p>
    </header>

    <el-skeleton v-if="loading" animated :rows="3" class="maps-skeleton" />
    <div v-else-if="loadError" class="maps-page__error">
      <el-alert title="地图暂时读不到，请稍后再试。" type="error" :closable="false" show-icon>
        <template #default>
          <el-button data-action="retry" @click="retry">重试</el-button>
        </template>
      </el-alert>
    </div>
    <el-empty
      v-else-if="items.length === 0"
      description="还没有可用的学习地图"
    />
    <ul v-else class="map-grid" data-testid="map-grid">
      <li v-for="m in items" :key="m.id" class="map-card" :data-map-id="m.id">
        <router-link :to="`/maps/${m.id}`" class="map-card__cover-link" :aria-label="m.title">
          <div class="map-card__cover" :data-hue="m.id % MAP_HUES.length">
            <img v-if="m.cover_url" :src="m.cover_url" :alt="m.title" />
            <span v-else class="map-card__cover-glyph" aria-hidden="true">
              {{ coverGlyph(m.title) }}
            </span>
          </div>
        </router-link>
        <div class="map-card__body">
          <h2 class="map-card__title">{{ m.title }}</h2>
          <p v-if="m.objective || m.summary" class="map-card__lede">
            {{ m.objective || m.summary }}
          </p>
          <div v-if="m.enrollment" class="map-card__progress" data-testid="map-card-progress">
            <span class="map-card__progress-label">
              {{ m.enrollment.completed_courses }}/{{ m.enrollment.total_courses }} 节
              · {{ m.enrollment.progress_percent }}%
            </span>
            <el-progress
              :percentage="m.enrollment.progress_percent"
              :show-text="false"
              :stroke-width="4"
            />
          </div>
          <router-link :to="`/maps/${m.id}`" class="map-card__cta">
            {{ m.enrollment ? '继续学习' : '开始学习' }} →
          </router-link>
        </div>
      </li>
    </ul>
  </main>
</template>

<style scoped>
.maps-page {
  padding-bottom: 48px;
}

.maps-page__head {
  margin-bottom: 24px;
}

.maps-page__head h1 {
  margin: 0 0 6px;
  font-size: 22px;
  color: var(--ink, #303133);
}

.maps-page__head p {
  margin: 0;
  color: var(--ink-2, #606266);
  font-size: 14px;
}

.maps-skeleton {
  margin-top: 16px;
}

.maps-page__error {
  margin-top: 16px;
}

.map-grid {
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  gap: 24px;
  list-style: none;
  margin: 0;
  padding: 0;
}

.map-card {
  display: flex;
  flex-direction: column;
  background: #fff;
  border: 1px solid var(--line, #ebeef5);
  border-radius: var(--r, 8px);
  overflow: hidden;
  transition: transform 0.15s, box-shadow 0.15s;
}

.map-card:hover {
  transform: translateY(-2px);
  box-shadow: 0 4px 12px rgba(0, 0, 0, 0.08);
}

.map-card__cover-link {
  display: block;
  text-decoration: none;
  color: inherit;
}

.map-card__cover {
  aspect-ratio: 16 / 9;
  display: flex;
  align-items: center;
  justify-content: center;
  background: var(--card-2, #f5f7fa);
}

.map-card__cover img {
  width: 100%;
  height: 100%;
  object-fit: cover;
}

.map-card__cover-glyph {
  font-size: 36px;
  font-weight: 600;
  color: #fff;
}

.map-card__cover[data-hue='0'] { background: #5B8FF9; }
.map-card__cover[data-hue='1'] { background: #5AD8A6; }
.map-card__cover[data-hue='2'] { background: #F6BD16; }
.map-card__cover[data-hue='3'] { background: #E86452; }
.map-card__cover[data-hue='4'] { background: #6DC8EC; }
.map-card__cover[data-hue='5'] { background: #945FB9; }

.map-card__body {
  padding: 16px;
  display: flex;
  flex-direction: column;
  gap: 10px;
  flex: 1;
}

.map-card__title {
  margin: 0;
  font-size: 16px;
  line-height: 1.4;
  color: var(--ink, #303133);
}

.map-card__lede {
  margin: 0;
  font-size: 13px;
  color: var(--ink-2, #606266);
  line-height: 1.5;
  display: -webkit-box;
  -webkit-line-clamp: 2;
  -webkit-box-orient: vertical;
  overflow: hidden;
}

.map-card__progress {
  display: flex;
  flex-direction: column;
  gap: 4px;
  margin-top: auto;
}

.map-card__progress-label {
  font-size: 12px;
  color: var(--ink-2, #909399);
}

.map-card__cta {
  margin-top: 8px;
  font-size: 14px;
  color: var(--seal, #409eff);
  text-decoration: none;
  align-self: flex-start;
}
</style>
