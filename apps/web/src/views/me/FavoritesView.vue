<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { fetchFavorites, removeFavorite } from '@/api/learner'
import type { FavoriteListDTO } from '@learn-site/contracts'

const list = ref<FavoriteListDTO | null>(null)
const loading = ref(false)
const errorMsg = ref<string | null>(null)
const submittingId = ref<number | null>(null)

async function reload(): Promise<void> {
  loading.value = true
  errorMsg.value = null
  try {
    list.value = await fetchFavorites(1, 50)
  } catch (err) {
    errorMsg.value = (err as Error).message || 'load_failed'
  } finally {
    loading.value = false
  }
}

async function unfavorite(courseId: number): Promise<void> {
  if (submittingId.value !== null) return
  submittingId.value = courseId
  try {
    await removeFavorite(courseId)
    if (list.value) {
      list.value = {
        ...list.value,
        items: list.value.items.filter((it) => it.course_id !== courseId),
        total: Math.max(0, list.value.total - 1),
      }
    }
  } catch (err) {
    errorMsg.value = (err as Error).message || 'unfavorite_failed'
  } finally {
    submittingId.value = null
  }
}

onMounted(() => {
  void reload()
})
</script>

<template>
  <main class="page">
    <header class="head">
      <h1 class="display">我的收藏</h1>
      <p class="muted">共 {{ list?.total ?? 0 }} 门</p>
    </header>

    <p v-if="loading" class="notice">加载中…</p>
    <p v-else-if="errorMsg" class="notice error">{{ errorMsg }}</p>

    <ul v-else-if="list && list.items.length" class="list">
      <li v-for="c in list.items" :key="c.course_id" class="card">
        <router-link :to="`/courses/${c.course_id}`" class="title">{{ c.title }}</router-link>
        <p class="meta">
          <span>{{ c.teacher_name }}</span>
          <span v-if="c.price_mode === 'paid'">¥{{ c.list_price.toFixed(2) }}</span>
          <span v-else>免费</span>
          <span class="status" :data-status="c.status">{{ c.status }}</span>
        </p>
        <button
          type="button"
          class="btn"
          :disabled="submittingId === c.course_id"
          @click="unfavorite(c.course_id)"
        >
          取消收藏
        </button>
      </li>
    </ul>
    <p v-else class="empty">还没有收藏课程.</p>
  </main>
</template>

<style scoped>
.page { display: grid; gap: 16px; }
.head { display: flex; align-items: baseline; justify-content: space-between; }
.display { margin: 0; font-size: 1.4rem; }
.muted { color: var(--color-text-muted, #5b6472); margin: 0; font-size: 0.85rem; }
.notice { color: var(--color-text-muted, #5b6472); margin: 0; }
.notice.error { color: #b42318; }
.empty { color: var(--color-text-muted, #5b6472); margin: 0; }
.list { list-style: none; padding: 0; margin: 0; display: grid; gap: 12px; grid-template-columns: repeat(auto-fill, minmax(260px, 1fr)); }
.card { border: 1px solid var(--color-border, #d0d4dc); border-radius: 8px; padding: 12px; display: grid; gap: 6px; background: #fff; }
.title { font-weight: 600; color: inherit; text-decoration: none; }
.title:hover { color: var(--color-primary, #2563eb); }
.meta { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; font-size: 0.85rem; color: var(--color-text-muted, #5b6472); margin: 0; }
.status { padding: 2px 6px; border-radius: 999px; border: 1px solid var(--color-border, #d0d4dc); font-size: 0.78rem; background: var(--color-bg-soft, #fafbfd); }
.btn { padding: 6px 12px; border: 1px solid var(--color-border, #d0d4dc); border-radius: 6px; background: transparent; font: inherit; cursor: pointer; align-self: start; }
.btn:disabled { opacity: 0.5; cursor: not-allowed; }
</style>