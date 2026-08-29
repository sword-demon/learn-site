<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { fetchFavorites, removeFavorite } from '@/api/learner';
import type { FavoriteCourseDTO, FavoriteListDTO } from '@learn-site/contracts';

defineOptions({ name: 'FavoritesView' });

const HUES = ['#34566b', '#4c7a5a', '#a8842c', '#6b4a5e', '#3d6b6b', '#5a6470'];

const list = ref<FavoriteListDTO | null>(null);
const loading = ref(false);
const errorMsg = ref<string | null>(null);
const submittingId = ref<number | null>(null);

function coverStyle(course: FavoriteCourseDTO) {
  return { '--hue': HUES[course.course_id % HUES.length] };
}

function formatPrice(n: number): string {
  return n % 1 === 0 ? String(n) : n.toFixed(2);
}

async function reload(): Promise<void> {
  loading.value = true;
  errorMsg.value = null;
  try {
    list.value = await fetchFavorites(1, 50);
  } catch (err) {
    errorMsg.value = (err as Error).message || 'load_failed';
  } finally {
    loading.value = false;
  }
}

async function unfavorite(courseId: number): Promise<void> {
  if (submittingId.value !== null) return;
  submittingId.value = courseId;
  try {
    await removeFavorite(courseId);
    if (list.value) {
      list.value = {
        ...list.value,
        items: list.value.items.filter((item) => item.course_id !== courseId),
        total: Math.max(0, list.value.total - 1),
      };
    }
  } catch (err) {
    errorMsg.value = (err as Error).message || 'unfavorite_failed';
  } finally {
    submittingId.value = null;
  }
}

onMounted(() => void reload());
</script>

<template>
  <main class="page favorites-page">
    <div class="list-head">
      <h2>我的收藏</h2>
      <span v-if="!loading && !errorMsg" class="cnt">{{ list?.total ?? 0 }} 门</span>
    </div>

    <p v-if="loading" class="notice">收藏加载中…</p>
    <p v-else-if="errorMsg" class="notice error">{{ errorMsg }}</p>
    <div v-else-if="list && list.items.length" class="entry-list">
      <article v-for="course in list.items" :key="course.course_id" class="rec">
        <router-link :to="`/courses/${course.course_id}`" class="cover" :style="coverStyle(course)">
          <img v-if="course.cover_url" :src="course.cover_url" :alt="course.title" />
          <b v-else>{{ course.title.slice(0, 1) }}</b>
        </router-link>
        <div>
          <h3>
            <router-link :to="`/courses/${course.course_id}`">《{{ course.title }}》</router-link>
          </h3>
          <div class="lmeta">
            {{ course.teacher_name }}
            <span v-if="course.status !== 'published'"> · 暂不可用</span>
          </div>
        </div>
        <div style="display: flex; flex-direction: column; gap: 8px; align-items: flex-end">
          <span v-if="course.price_mode === 'free'" class="tag tag-free">免费</span>
          <span v-else class="price-now" style="font-size: 17px">¥ {{ formatPrice(course.list_price) }}</span>
          <button
            type="button"
            class="btn-link"
            :disabled="submittingId === course.course_id"
            @click="unfavorite(course.course_id)"
          >
            {{ submittingId === course.course_id ? '处理中…' : '取消收藏' }}
          </button>
        </div>
      </article>
    </div>
    <div v-else class="empty">
      <span class="serif">收藏夹还是空的</span>
      在课程卡片或详情页点 ♡ 收藏想稍后学的课
    </div>
  </main>
</template>
