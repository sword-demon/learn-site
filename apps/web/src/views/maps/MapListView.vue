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
  return ` · 进度 ${enrollment.progress_percent}%`;
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

    <p v-if="loading" class="notice">地图加载中…</p>
    <div v-else-if="loadError" class="notice error">
      <p>地图暂时读不到，请稍后再试。</p>
      <button type="button" class="btn btn-ghost" data-action="retry" @click="load">重新加载</button>
    </div>
    <div v-else-if="data && data.items.length" class="panel">
      <router-link
        v-for="map in data.items"
        :key="map.id"
        :to="`/maps/${map.id}`"
        class="map-row"
      >
        <div class="cover" :style="coverStyle(map)">
          <img v-if="map.cover_url" :src="map.cover_url" :alt="`${map.title}封面`" />
          <b v-else style="font-size: 30px">{{ coverGlyph(map.title) }}</b>
        </div>
        <div>
          <h3>{{ map.title }}</h3>
          <p class="goal">{{ map.objective || map.summary || '按阶段推进学习' }}</p>
          <div class="meta small muted">
            {{ map.enrollment ? '已加入' : '未加入' }}{{ progressLabel(map.enrollment) }}
          </div>
        </div>
        <div>
          <span v-if="map.enrollment" class="btn btn-primary btn-sm">继续 →</span>
          <span v-else class="btn btn-ghost btn-sm">查看路径</span>
        </div>
      </router-link>
    </div>
    <p v-else class="empty">暂无已发布的学习地图。</p>
  </main>
</template>
