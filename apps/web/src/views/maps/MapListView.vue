<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { fetchLearningMaps } from '@/api/learner';
import type { LearnerMapListDTO } from '@learn-site/contracts';

defineOptions({ name: 'MapListView' });

const MAP_HUES = ['#34566b', '#4c7a5a', '#a8842c', '#6b4a5e', '#3d6b6b'];

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

function progressLabel(enrollment: LearnerMapListDTO['items'][number]['enrollment']): string {
  if (!enrollment) return '';
  return `${enrollment.completed_courses}/${enrollment.total_courses} (${enrollment.progress_percent}%)`;
}

function coverStyle(map: LearnerMapListDTO['items'][number]) {
  return { '--hue': MAP_HUES[map.id % MAP_HUES.length] };
}

function coverGlyph(title: string): string {
  return title.slice(0, 1);
}

const mapCount = computed(() => data.value?.items.length ?? 0);

onMounted(load);
</script>

<template>
  <main class="page maps-page">
    <div class="list-head">
      <h2>学习地图</h2>
      <span v-if="!loading && !loadError" class="cnt">{{ mapCount }} 条已发布路径</span>
    </div>

    <el-skeleton v-if="loading" animated :rows="5" />
    <div v-else-if="loadError" class="notice error">
      <el-alert title="地图暂时读不到，请稍后再试。" type="error" :closable="false" show-icon />
      <el-button data-action="retry" @click="load">重新加载</el-button>
    </div>
    <div v-else-if="data && data.items.length" class="panel">
      <router-link v-for="map in data.items" :key="map.id" :to="`/maps/${map.id}`" class="map-row">
        <div class="cover" :style="coverStyle(map)">
          <img v-if="map.cover_url" :src="map.cover_url" :alt="`${map.title}封面`" />
          <b v-else style="font-size: 30px">{{ coverGlyph(map.title) }}</b>
        </div>
        <div>
          <h3>{{ map.title }}</h3>
          <p class="goal">{{ map.objective || map.summary || '按阶段推进学习' }}</p>
          <p v-if="map.audience" class="meta small muted">适合：{{ map.audience }}</p>
          <div v-if="map.enrollment" class="map-progress">
            <el-progress :percentage="map.enrollment.progress_percent" :show-text="false" />
            <span class="small muted">{{ progressLabel(map.enrollment) }}</span>
          </div>
          <el-tag v-else type="info" size="small" effect="plain">未加入</el-tag>
        </div>
        <div>
          <el-tag v-if="map.enrollment" type="success" effect="dark">继续</el-tag>
          <el-tag v-else type="info" effect="plain">查看路径</el-tag>
        </div>
      </router-link>
    </div>
    <el-empty v-else description="暂无已发布的学习地图。" />
  </main>
</template>

<style scoped>
.map-progress {
  display: grid;
  max-width: 320px;
  grid-template-columns: minmax(120px, 1fr) auto;
  gap: 10px;
  align-items: center;
}
</style>
