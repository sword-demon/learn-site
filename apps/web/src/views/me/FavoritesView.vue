<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { fetchFavorites, removeFavorite } from '@/api/learner';
import type { FavoriteListDTO } from '@learn-site/contracts';

defineOptions({ name: 'FavoritesView' });

const list = ref<FavoriteListDTO | null>(null);
const loading = ref(false);
const errorMsg = ref<string | null>(null);
const submittingId = ref<number | null>(null);

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
  <main class="page account-page favorites-page">
    <header class="account-head">
      <div>
        <p class="eyebrow"><span class="eyebrow-rule" />个人书架 · 收藏</p>
        <h1 class="display">我的收藏</h1>
        <p class="lede">把想学的课程先放在这里，之后再回来继续。</p>
      </div>
      <p class="account-count">
        <strong>{{ list?.total ?? 0 }}</strong> 门
      </p>
    </header>

    <p v-if="loading" class="notice">收藏加载中…</p>
    <p v-else-if="errorMsg" class="notice error">{{ errorMsg }}</p>
    <ul v-else-if="list && list.items.length" class="favorite-grid">
      <li v-for="course in list.items" :key="course.course_id" class="favorite-card">
        <router-link :to="`/courses/${course.course_id}`" class="favorite-cover">
          <img v-if="course.cover_url" :src="course.cover_url" :alt="course.title" />
          <span v-else class="cover-fallback display">{{ course.title.slice(0, 2) }}</span>
        </router-link>
        <div class="favorite-body">
          <h2 class="title">
            <router-link :to="`/courses/${course.course_id}`">{{ course.title }}</router-link>
          </h2>
          <p class="meta">
            <span>讲师 · {{ course.teacher_name }}</span><span v-if="course.price_mode === 'paid'">¥{{ course.list_price.toFixed(2) }}</span><span v-else class="tag free">免费</span>
          </p>
          <div class="favorite-footer">
            <span class="status" :data-status="course.status">{{
              course.status === 'published' ? '已发布' : '暂不可用'
            }}</span>
            <button
              type="button"
              class="text-button"
              :disabled="submittingId === course.course_id"
              @click="unfavorite(course.course_id)"
            >
              {{ submittingId === course.course_id ? '处理中…' : '取消收藏' }}
            </button>
          </div>
        </div>
      </li>
    </ul>
    <p v-else class="empty">还没有收藏课程，去首页挑一门喜欢的课程吧。</p>
  </main>
</template>

<style scoped>
.favorites-page {
  display: grid;
  gap: 28px;
}
.account-head {
  display: flex;
  align-items: end;
  justify-content: space-between;
  gap: 20px;
  padding: 18px 0 26px;
  border-bottom: 1px solid var(--line);
}
.account-head .eyebrow {
  margin-bottom: 16px;
}
.account-head .display {
  margin: 0 0 9px;
  color: var(--pine-deep);
}
.account-count {
  flex-shrink: 0;
  margin: 0 0 4px;
  color: var(--muted);
  font-size: 0.8rem;
}
.account-count strong {
  color: var(--accent);
  font: 700 1.5rem var(--font-mono);
}
.favorite-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(240px, 1fr));
  gap: 18px;
  margin: 0;
  padding: 0;
  list-style: none;
}
.favorite-card {
  overflow: hidden;
  border: 1px solid var(--line);
  border-radius: 7px;
  background: var(--surface);
  box-shadow: 0 10px 26px rgba(31, 60, 48, 0.07);
}
.favorite-cover {
  display: block;
  aspect-ratio: 16 / 10;
  overflow: hidden;
  background: var(--paper-deep);
}
.favorite-cover img {
  display: block;
  width: 100%;
  height: 100%;
  object-fit: cover;
  transition: transform 0.4s ease;
}
.favorite-card:hover .favorite-cover img {
  transform: scale(1.035);
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
.favorite-body {
  display: grid;
  gap: 8px;
  padding: 15px 16px 16px;
}
.title {
  margin: 0;
  font-size: 1.02rem;
  line-height: 1.4;
}
.title a {
  color: var(--ink);
  text-decoration: none;
}
.title a:hover {
  color: var(--accent);
}
.meta {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  margin: 0;
  color: var(--muted);
  font-size: 0.78rem;
}
.favorite-footer {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 10px;
  padding-top: 9px;
  border-top: 1px solid var(--line);
}
.status {
  color: var(--muted);
  font-size: 0.72rem;
}
.status[data-status='published'] {
  color: var(--pine);
}
.text-button {
  padding: 0;
  border: 0;
  background: transparent;
  color: var(--accent);
  font: inherit;
  font-size: 0.77rem;
  cursor: pointer;
}
.text-button:disabled {
  cursor: not-allowed;
  opacity: 0.55;
}
@media (max-width: 560px) {
  .account-head {
    align-items: start;
    flex-direction: column;
    gap: 8px;
  }
}
</style>
