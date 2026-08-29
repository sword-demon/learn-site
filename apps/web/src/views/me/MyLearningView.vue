<template>
  <main class="page my-learning account-page">
    <header class="account-head">
      <div>
        <p class="eyebrow"><span class="eyebrow-rule" />个人书架 · 学习进度</p>
        <h1 class="display">我的学习</h1>
        <p class="lede">最近打开的课程在前，接着上次停下的地方继续。</p>
      </div>
      <p class="account-count">
        <strong>{{ items.length }}</strong> 门
      </p>
    </header>

    <p v-if="loading" class="notice">正在加载学习记录…</p>
    <p v-else-if="loadError" class="notice error">学习记录暂时读不到，请稍后再试。</p>
    <p v-else-if="items.length === 0" class="empty">
      还没有学习记录。去 <router-link to="/">首页</router-link> 看看课程吧。
    </p>
    <ul v-else class="course-list">
      <li v-for="item in items" :key="item.course_id" class="course-row">
        <router-link :to="`/courses/${item.course_id}`" class="cover">
          <img v-if="item.course.cover_url" :src="item.course.cover_url" :alt="item.course.title" />
          <span v-else class="cover-placeholder display">{{ item.course.title.slice(0, 2) }}</span>
        </router-link>
        <div class="info">
          <p class="course-kicker">课程档案 · 最近学习</p>
          <h2 class="title">
            <router-link :to="`/courses/${item.course_id}`">{{ item.course.title }}</router-link>
          </h2>
          <p class="teacher">讲师 · {{ item.course.teacher_name || '未知' }}</p>
          <div
            class="progress"
            role="progressbar"
            :aria-valuenow="item.progress_percent"
            aria-valuemin="0"
            aria-valuemax="100"
            :aria-label="`${item.course.title}学习进度`"
          >
            <div class="bar" :style="{ width: `${item.progress_percent}%` }" />
          </div>
          <p class="progress-meta">
            <span class="percent">{{ item.progress_percent }}%</span>
            <span v-if="item.entitlement_status === 'revoked'" class="tag revoked">访问已撤销</span>
            <span v-else-if="item.completed_at" class="tag done">已完成</span>
            <span v-else-if="item.last_lesson_id" class="tag resume">从上次继续</span>
            <span v-else class="tag fresh">尚未开始</span>
          </p>
          <p v-if="item.entitlement_status === 'revoked'" class="revoke-note">
            {{ item.revoked_reason ? `撤销原因：${item.revoked_reason}` : '课程访问权已被撤销。' }}
          </p>
        </div>
        <div class="actions">
          <button
            v-if="item.entitlement_status === 'revoked' && item.can_rejoin"
            type="button"
            class="btn btn-primary"
            data-action="rejoin"
            :disabled="rejoiningCourseId === item.course_id"
            @click="rejoin(item)"
          >
            {{ rejoiningCourseId === item.course_id ? '重新加入中…' : '再次加入' }}
          </button>
          <span v-else-if="item.entitlement_status === 'revoked'" class="access-unavailable">
            {{ item.course.status === 'published' ? '当前无法重新加入' : '课程已下架' }}
          </span>
          <router-link
            v-else-if="item.last_lesson_id"
            :to="`/learn/${item.course_id}/${item.last_lesson_id}`"
            class="btn btn-primary"
          >
            继续学习 <span aria-hidden="true">→</span>
          </router-link>
          <router-link v-else :to="`/courses/${item.course_id}`" class="btn btn-ghost">
            进入课程 <span aria-hidden="true">→</span>
          </router-link>
        </div>
        <p v-if="rejoinErrorCourseId === item.course_id" class="rejoin-error" role="alert">
          重新加入失败，请稍后再试。
        </p>
      </li>
    </ul>
  </main>
</template>

<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { useRouter } from 'vue-router';
import type { MyLearningItemDTO } from '@learn-site/contracts';
import { fetchMyLearning, startCourse } from '@/api/learner';

defineOptions({ name: 'MyLearningView' });

const items = ref<MyLearningItemDTO[]>([]);
const loading = ref(true);
const loadError = ref(false);
const rejoiningCourseId = ref<number | null>(null);
const rejoinErrorCourseId = ref<number | null>(null);
const router = useRouter();

async function rejoin(item: MyLearningItemDTO): Promise<void> {
  if (!item.can_rejoin || rejoiningCourseId.value !== null) return;
  rejoiningCourseId.value = item.course_id;
  rejoinErrorCourseId.value = null;
  try {
    const result = await startCourse(item.course_id);
    if (item.last_lesson_id) {
      await router.push(`/learn/${item.course_id}/${item.last_lesson_id}`);
    } else if (result.first_lesson) {
      await router.push(`/learn/${item.course_id}/${result.first_lesson.id}`);
    } else {
      await router.push(`/courses/${item.course_id}`);
    }
  } catch {
    rejoinErrorCourseId.value = item.course_id;
  } finally {
    rejoiningCourseId.value = null;
  }
}

onMounted(async () => {
  try {
    const result = await fetchMyLearning();
    items.value = result.items;
  } catch {
    loadError.value = true;
  } finally {
    loading.value = false;
  }
});
</script>

<style scoped>
.account-page {
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
.course-list {
  display: grid;
  gap: 12px;
  margin: 0;
  padding: 0;
  list-style: none;
}
.course-row {
  display: grid;
  grid-template-columns: 150px minmax(0, 1fr) auto;
  gap: 20px;
  align-items: center;
  padding: 14px;
  border: 1px solid var(--line);
  border-left: 3px solid var(--pine);
  background: var(--surface);
  box-shadow: 0 8px 20px rgba(31, 60, 48, 0.05);
}
.cover {
  display: block;
  aspect-ratio: 1.5;
  overflow: hidden;
  background: var(--paper-deep);
}
.cover img {
  display: block;
  width: 100%;
  height: 100%;
  object-fit: cover;
  transition: transform 0.35s ease;
}
.course-row:hover .cover img {
  transform: scale(1.04);
}
.cover-placeholder {
  display: flex;
  width: 100%;
  height: 100%;
  align-items: center;
  justify-content: center;
  color: var(--pine);
  font-size: 1.7rem;
}
.info {
  min-width: 0;
}
.course-kicker {
  margin: 0 0 5px;
  color: var(--accent);
  font-family: var(--font-mono);
  font-size: 0.68rem;
  font-weight: 700;
  letter-spacing: 0.06em;
}
.title {
  margin: 0 0 5px;
  font-size: 1.08rem;
  line-height: 1.4;
}
.title a {
  color: var(--pine-deep);
  text-decoration: none;
}
.title a:hover {
  color: var(--accent);
}
.teacher {
  margin: 0 0 13px;
  color: var(--muted);
  font-size: 0.78rem;
}
.progress {
  width: 100%;
  height: 6px;
  overflow: hidden;
  background: var(--paper-deep);
}
.bar {
  height: 100%;
  background: var(--accent);
  transition: width 0.25s ease;
}
.progress-meta {
  display: flex;
  align-items: center;
  gap: 9px;
  margin: 7px 0 0;
  color: var(--muted);
  font-size: 0.75rem;
}
.percent {
  color: var(--pine-deep);
  font-family: var(--font-mono);
  font-weight: 700;
}
.tag {
  min-height: 21px;
  padding: 2px 7px;
}
.tag.done {
  border-color: #bad4c1;
  background: #eef7f0;
  color: var(--pine-deep);
}
.tag.resume {
  border-color: #e8b7a9;
  background: var(--accent-soft);
  color: #9e3f2c;
}
.tag.fresh {
  background: var(--surface-muted);
}
.tag.revoked {
  border-color: #e8b7a9;
  background: #fff2ee;
  color: #9e3f2c;
}
.revoke-note {
  margin: 9px 0 0;
  color: #9e3f2c;
  font-size: 0.78rem;
  line-height: 1.5;
}
.actions {
  display: flex;
  justify-content: end;
}
.actions .btn {
  white-space: nowrap;
}
.access-unavailable {
  color: var(--muted);
  font-size: 0.78rem;
}
.rejoin-error {
  grid-column: 2 / -1;
  margin: -8px 0 0;
  color: #9e3f2c;
  font-size: 0.78rem;
}
@media (max-width: 700px) {
  .course-row {
    grid-template-columns: 110px minmax(0, 1fr);
  }
  .actions {
    grid-column: 2;
    justify-content: start;
  }
}
@media (max-width: 560px) {
  .account-head {
    align-items: start;
    flex-direction: column;
    gap: 8px;
  }
  .course-row {
    grid-template-columns: 1fr;
    gap: 13px;
  }
  .cover {
    width: 100%;
  }
  .actions {
    grid-column: auto;
  }
  .actions .btn {
    width: 100%;
  }
}
</style>
