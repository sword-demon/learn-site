<template>
  <main class="page my-learning">
    <div class="list-head">
      <h2>我的学习</h2>
      <span v-if="!loading && !loadError" class="cnt">{{ items.length }} 门进行中</span>
    </div>

    <p v-if="loading" class="notice">正在加载学习记录…</p>
    <p v-else-if="loadError" class="notice error">学习记录暂时读不到，请稍后再试。</p>
    <div v-else-if="items.length === 0" class="empty">
      <span class="serif">还没有开始任何课程</span>
      回到<router-link to="/">首页分类</router-link>，从一门免费的课开始吧
    </div>
    <div v-else class="entry-list">
      <article v-for="item in items" :key="item.course_id" class="rec">
        <router-link :to="`/courses/${item.course_id}`" class="cover" :style="coverStyle(item)">
          <img
            v-if="item.course.cover_url"
            :src="item.course.cover_url"
            :alt="item.course.title"
          />
          <b v-else>{{ item.course.title.slice(0, 1) }}</b>
        </router-link>
        <div>
          <h3>
            <router-link :to="`/courses/${item.course_id}`">《{{ item.course.title }}》</router-link>
          </h3>
          <div class="mini-progress" style="max-width: 300px">
            <i :style="{ width: `${item.progress_percent}%` }" />
          </div>
          <div class="lmeta">
            {{ item.progress_percent }}% · 讲师 {{ item.course.teacher_name || '未知' }}
            <span v-if="item.entitlement_status === 'revoked'"> · 访问已撤销</span>
            <span v-else-if="item.completed_at"> · 已完成</span>
          </div>
          <p v-if="item.entitlement_status === 'revoked'" class="small" style="color: var(--seal)">
            {{ item.revoked_reason ? `撤销原因：${item.revoked_reason}` : '课程访问权已被撤销。' }}
          </p>
        </div>
        <div>
          <button
            v-if="item.entitlement_status === 'revoked' && item.can_rejoin"
            type="button"
            class="btn btn-primary btn-sm"
            data-action="rejoin"
            :disabled="rejoiningCourseId === item.course_id"
            @click="rejoin(item)"
          >
            {{ rejoiningCourseId === item.course_id ? '重新加入中…' : '再次加入' }}
          </button>
          <span
            v-else-if="item.entitlement_status === 'revoked'"
            class="small muted"
          >
            {{ item.course.status === 'published' ? '当前无法重新加入' : '课程已下架' }}
          </span>
          <router-link
            v-else-if="item.last_lesson_id"
            :to="`/learn/${item.course_id}/${item.last_lesson_id}`"
            class="btn btn-primary btn-sm"
          >
            继续学习
          </router-link>
          <router-link v-else :to="`/courses/${item.course_id}`" class="btn btn-ghost btn-sm">
            进入课程
          </router-link>
          <p v-if="rejoinErrorCourseId === item.course_id" class="small" style="color: var(--seal)">
            重新加入失败，请稍后再试。
          </p>
        </div>
      </article>
    </div>
  </main>
</template>

<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { useRouter } from 'vue-router';
import type { MyLearningItemDTO } from '@learn-site/contracts';
import { fetchMyLearning, startCourse } from '@/api/learner';

defineOptions({ name: 'MyLearningView' });

const HUES = ['#34566b', '#4c7a5a', '#a8842c', '#6b4a5e', '#3d6b6b', '#5a6470'];

const items = ref<MyLearningItemDTO[]>([]);
const loading = ref(true);
const loadError = ref(false);
const rejoiningCourseId = ref<number | null>(null);
const rejoinErrorCourseId = ref<number | null>(null);
const router = useRouter();

function coverStyle(item: MyLearningItemDTO) {
  return { '--hue': HUES[item.course_id % HUES.length] };
}

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
