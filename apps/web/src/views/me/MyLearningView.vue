<template>
  <main class="page my-learning">
    <header class="head">
      <h1 class="display">我的学习</h1>
      <p class="lede">已加入或正在学习的课程, 最近一次课节在前.</p>
    </header>

    <p v-if="loading" class="notice">正在加载学习记录…</p>
    <p v-else-if="loadError" class="notice error">学习记录暂时读不到, 请稍后再试.</p>
    <p v-else-if="items.length === 0" class="empty">
      还没有学习记录. 去
      <router-link to="/">首页</router-link>
      看看推荐的课程吧.
    </p>
    <ul v-else class="course-list">
      <li v-for="item in items" :key="item.course_id" class="course-row">
        <div class="cover">
          <img
            v-if="item.course.cover_url"
            :src="item.course.cover_url"
            :alt="item.course.title"
          />
          <div v-else class="cover-placeholder" aria-hidden="true" />
        </div>
        <div class="info">
          <h2 class="title">
            <router-link :to="`/courses/${item.course_id}`">{{ item.course.title }}</router-link>
          </h2>
          <p class="teacher">讲师 · {{ item.course.teacher_name || '未知' }}</p>
          <div class="progress" :aria-valuenow="item.progress_percent" aria-valuemin="0" aria-valuemax="100">
            <div class="bar" :style="{ width: `${item.progress_percent}%` }" />
          </div>
          <p class="progress-meta">
            <span>{{ item.progress_percent }}%</span>
            <span v-if="item.completed_at" class="tag done">已完成</span>
            <span v-else-if="item.last_lesson_id" class="tag resume">从上次继续</span>
            <span v-else class="tag fresh">尚未开始</span>
          </p>
        </div>
        <div class="actions">
          <router-link
            v-if="item.last_lesson_id"
            :to="`/learn/${item.course_id}/${item.last_lesson_id}`"
            class="btn btn-primary"
          >
            继续学习
          </router-link>
          <router-link
            v-else
            :to="`/courses/${item.course_id}`"
            class="btn btn-ghost"
          >
            进入课程
          </router-link>
        </div>
      </li>
    </ul>
  </main>
</template>

<script setup lang="ts">
import { onMounted, ref } from 'vue'
import type { MyLearningItemDTO } from '@learn-site/contracts'
import { fetchMyLearning } from '@/api/learner'

defineOptions({ name: 'MyLearningView' })

const items = ref<MyLearningItemDTO[]>([])
const loading = ref(true)
const loadError = ref(false)

onMounted(async () => {
  try {
    const result = await fetchMyLearning()
    items.value = result.items
  } catch {
    loadError.value = true
  } finally {
    loading.value = false
  }
})
</script>

<style scoped>
.my-learning { display: grid; gap: 16px; }
.head .display { margin: 0 0 4px 0; }
.lede { color: var(--color-text-muted, #5b6472); margin: 0; }
.empty, .notice { color: var(--color-text-muted, #5b6472); }
.notice.error { color: #b42318; }
.empty a { color: var(--color-primary, #2563eb); }
.course-list { list-style: none; padding: 0; margin: 0; display: grid; gap: 12px; }
.course-row {
  display: grid;
  grid-template-columns: 96px 1fr auto;
  gap: 14px;
  align-items: center;
  border: 1px solid var(--color-border, #e3e6ee);
  border-radius: 10px;
  padding: 12px 14px;
  background: #fff;
}
.cover img { width: 96px; height: 64px; object-fit: cover; border-radius: 6px; }
.cover-placeholder {
  width: 96px;
  height: 64px;
  border-radius: 6px;
  background: var(--color-bg-soft, #eef1f7);
}
.title { margin: 0 0 2px 0; font-size: 16px; }
.title a { color: inherit; text-decoration: none; }
.teacher { margin: 0 0 8px 0; color: var(--color-text-muted, #5b6472); font-size: 13px; }
.progress {
  width: 100%;
  height: 6px;
  border-radius: 3px;
  background: var(--color-bg-soft, #eef1f7);
  overflow: hidden;
}
.bar { height: 100%; background: var(--color-primary, #2563eb); transition: width 0.2s ease; }
.progress-meta {
  margin: 6px 0 0 0;
  font-size: 13px;
  color: var(--color-text-muted, #5b6472);
  display: flex;
  gap: 8px;
  align-items: center;
}
.tag {
  display: inline-block;
  padding: 1px 6px;
  border-radius: 3px;
  font-size: 12px;
}
.tag.done { background: #e7f6ec; color: #137a3c; }
.tag.resume { background: #e6efff; color: #1e3a8a; }
.tag.fresh { background: var(--color-bg-soft, #eef1f7); color: #5b6472; }
.actions { display: flex; gap: 8px; }
.btn {
  display: inline-flex;
  align-items: center;
  padding: 6px 12px;
  border-radius: 6px;
  border: 1px solid transparent;
  font: inherit;
  text-decoration: none;
  cursor: pointer;
}
.btn-primary { background: var(--color-primary, #2563eb); color: #fff; }
.btn-ghost { background: transparent; border-color: var(--color-border, #d0d4dc); color: inherit; }
</style>