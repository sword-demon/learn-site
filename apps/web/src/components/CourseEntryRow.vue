<template>
  <article class="course-card">
    <router-link
      :to="`/courses/${course.id}`"
      class="course-card__cover"
      :aria-label="course.title"
    >
      <img v-if="course.cover_url" :src="course.cover_url" :alt="course.title" loading="lazy" />
      <el-icon v-else :size="40" aria-label="暂无封面"><Picture /></el-icon>
      <el-tag
        v-if="course.preview_available"
        class="preview-tag"
        type="info"
        effect="light"
        size="small"
        >可试看</el-tag
      >
    </router-link>
    <div class="course-card__body">
      <h3>
        <router-link :to="`/courses/${course.id}`">{{ course.title }}</router-link>
      </h3>
      <p v-if="course.summary" class="course-summary">{{ course.summary }}</p>
      <div class="course-meta">
        <span
          ><el-icon><User /></el-icon>{{ course.teacher_name || '讲师' }}</span
        ><span>{{ course.learner_count }} 人在学</span>
      </div>
      <div class="course-card__footer">
        <div class="course-price">
          <span v-if="course.price_mode === 'free'" class="course-free">免费</span>
          <template v-else
            ><strong>¥{{ formatPrice(displayPrice) }}</strong
            ><del v-if="onSale">¥{{ formatPrice(course.list_price) }}</del></template
          >
        </div>
        <el-button
          v-if="showFavorite"
          circle
          text
          :class="{ 'is-favorited': favorited }"
          :title="favorited ? '取消收藏' : '收藏'"
          :aria-label="favorited ? '取消收藏' : '收藏'"
          :icon="favorited ? StarFilled : Star"
          :loading="favoriteBusy"
          data-action="toggle-favorite"
          @click="toggleFavorite"
        />
        <router-link :to="`/courses/${course.id}`" class="course-detail-link">查看详情</router-link>
      </div>
    </div>
  </article>
</template>

<script setup lang="ts">
import { computed, ref } from 'vue';
import { Picture, Star, StarFilled, User } from '@element-plus/icons-vue';
import type { CourseListItemDTO } from '@learn-site/contracts';
import { addFavorite, removeFavorite } from '@/api/learner';
import { useLoginFamilyStore } from '@/api/login';

defineOptions({ name: 'CourseEntryRow' });

const props = withDefaults(
  defineProps<{
    course: CourseListItemDTO;
    showFavorite?: boolean;
    initialFavorited?: boolean;
  }>(),
  { showFavorite: false, initialFavorited: false },
);

const session = useLoginFamilyStore();
const favorited = ref(props.initialFavorited);
const favoriteBusy = ref(false);

const displayPrice = computed(() =>
  props.course.sale_price > 0 ? props.course.sale_price : props.course.list_price,
);

const onSale = computed(
  () => props.course.price_mode !== 'free' && props.course.sale_price < props.course.list_price,
);

function formatPrice(n: number): string {
  return n % 1 === 0 ? String(n) : n.toFixed(2);
}

async function toggleFavorite(): Promise<void> {
  if (!session.loggedIn || favoriteBusy.value) return;
  favoriteBusy.value = true;
  try {
    const result = favorited.value
      ? await removeFavorite(props.course.id)
      : await addFavorite(props.course.id);
    favorited.value = result.favorited;
  } catch {
    /* ignore */
  } finally {
    favoriteBusy.value = false;
  }
}
</script>

<style scoped>
.course-card {
  display: flex;
  flex-direction: column;
  min-width: 0;
  border: 1px solid var(--line);
  border-radius: 8px;
  background: var(--card);
  overflow: hidden;
  transition: border-color 0.15s;
}
.course-card:hover {
  border-color: var(--seal);
}
.course-card__cover {
  position: relative;
  aspect-ratio: 1.79;
  width: 100%;
  display: flex;
  align-items: center;
  justify-content: center;
  background: var(--paper-2);
  color: var(--ink-3);
  overflow: hidden;
}
.course-card__cover img {
  width: 100%;
  height: 100%;
  object-fit: cover;
}
.preview-tag {
  position: absolute;
  top: 12px;
  left: 12px;
}
.course-card__body {
  padding: 20px;
  display: flex;
  flex: 1;
  flex-direction: column;
}
.course-card h3 {
  font-size: 18px;
  line-height: 1.5;
  margin: 0 0 10px;
}
.course-card h3 a {
  color: var(--ink);
}
.course-card h3 a:hover {
  color: var(--seal);
}
.course-summary {
  color: var(--ink-2);
  font-size: 14px;
  line-height: 1.65;
  margin: 0 0 16px;
  display: -webkit-box;
  -webkit-line-clamp: 2;
  -webkit-box-orient: vertical;
  overflow: hidden;
}
.course-meta {
  display: flex;
  flex-wrap: wrap;
  gap: 14px;
  margin-top: auto;
  font-size: 13px;
  color: var(--ink-2);
}
.course-meta span {
  display: flex;
  align-items: center;
  gap: 5px;
}
.course-card__footer {
  display: flex;
  align-items: center;
  gap: 12px;
  min-height: 48px;
  margin-top: 18px;
  padding-top: 14px;
  border-top: 1px solid var(--line);
}
.course-price {
  display: flex;
  align-items: baseline;
  gap: 8px;
  margin-right: auto;
}
.course-price strong,
.course-free {
  font-size: 19px;
  font-weight: 650;
}
.course-free,
.is-favorited {
  color: var(--seal);
}
.course-price del {
  font-size: 12px;
  color: var(--ink-3);
}
.course-detail-link {
  white-space: nowrap;
  font-size: 13px;
}
</style>
