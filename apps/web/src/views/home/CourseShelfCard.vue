<template>
  <article class="shelf-card" :style="{ '--delay': `${index * 70}ms` }">
    <span class="index latin">{{ String(index + 1).padStart(2, '0') }}</span>
    <router-link :to="`/courses/${course.id}`" class="cover-link">
      <img
        v-if="course.cover_url"
        :src="course.cover_url"
        :alt="course.title"
        class="cover-img"
        loading="lazy"
      />
      <span v-else class="cover-fallback display">{{ course.title.slice(0, 2) }}</span>
    </router-link>
    <div class="body">
      <h3 class="title display">
        <router-link :to="`/courses/${course.id}`">{{ course.title }}</router-link>
      </h3>
      <p class="teacher">讲师 · {{ course.teacher_name }}</p>
      <p class="summary">{{ course.summary || '讲师还没有写简介。' }}</p>
      <footer class="meta">
        <el-tag v-if="course.price_mode === 'free'" type="success" size="small">免费</el-tag>
        <template v-else>
          <span class="price-now">¥ {{ formatPrice(displayPrice) }}</span>
          <span v-if="onSale" class="price-was">¥ {{ formatPrice(course.list_price) }}</span>
        </template>
        <el-tag v-if="course.preview_available" type="warning" size="small" effect="plain"
          >试看</el-tag
        >
        <span class="learners">{{ course.learner_count }} 位学员</span>
      </footer>
    </div>
  </article>
</template>

<script setup lang="ts">
import { computed } from 'vue';
import type { CourseListItemDTO } from '@learn-site/contracts';

defineOptions({ name: 'CourseShelfCard' });

const props = defineProps<{
  course: CourseListItemDTO;
  index: number;
}>();

// 当前展示价：优惠窗口内优先 sale_price
const displayPrice = computed(() =>
  props.course.sale_price > 0 ? props.course.sale_price : props.course.list_price,
);

// 是否在优惠期（sale 低于标价）
const onSale = computed(
  () => props.course.price_mode !== 'free' && props.course.sale_price < props.course.list_price,
);

function formatPrice(n: number): string {
  return n.toFixed(2);
}
</script>

<style scoped>
.shelf-card {
  position: relative;
  display: grid;
  gap: 0;
  background: var(--surface);
  border: 1px solid var(--line);
  border-radius: 7px;
  box-shadow: 0 10px 26px rgba(31, 60, 48, 0.08);
  overflow: hidden;
  transition:
    transform 0.35s cubic-bezier(0.22, 1, 0.36, 1),
    box-shadow 0.35s ease;
  animation: card-rise 0.65s cubic-bezier(0.22, 1, 0.36, 1) both;
  animation-delay: var(--delay, 0ms);
}

.shelf-card:hover {
  transform: translateY(-5px);
  box-shadow: 0 18px 34px rgba(31, 60, 48, 0.14);
}

.index {
  position: absolute;
  top: 11px;
  left: 12px;
  z-index: 2;
  font-size: 11px;
  letter-spacing: 0.12em;
  color: #fffefa;
  text-shadow: 0 1px 3px rgba(0, 0, 0, 0.42);
}

.cover-link {
  display: block;
  aspect-ratio: 16 / 10;
  overflow: hidden;
  background: var(--paper-deep);
}

.cover-img {
  width: 100%;
  height: 100%;
  object-fit: cover;
  display: block;
  transition: transform 0.5s ease;
}

.shelf-card:hover .cover-img {
  transform: scale(1.04);
}

.cover-fallback {
  display: flex;
  width: 100%;
  height: 100%;
  align-items: center;
  justify-content: center;
  font-size: 2.4rem;
  color: var(--pine);
  letter-spacing: 0.08em;
}

.body {
  padding: 15px 16px 16px;
  display: grid;
  gap: 6px;
}

.title {
  margin: 0;
  font-size: 1.05rem;
  line-height: 1.35;
}

.title a {
  color: var(--ink);
  text-decoration: none;
}

.title a:hover {
  color: var(--accent);
}

.teacher {
  margin: 0;
  font-size: 0.78rem;
  color: var(--muted);
}

.summary {
  margin: 0;
  font-size: 0.82rem;
  line-height: 1.55;
  color: var(--muted);
  display: -webkit-box;
  -webkit-line-clamp: 2;
  -webkit-box-orient: vertical;
  overflow: hidden;
  min-height: 2.5em;
}

.meta {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 8px;
  margin-top: 4px;
  font-size: 0.78rem;
}

.price-now {
  font-weight: 700;
  color: var(--ink);
  font-family: var(--font-mono);
}

.price-was {
  color: var(--muted);
  text-decoration: line-through;
  font-family: var(--font-mono);
}

.pill.free {
  color: var(--pine-deep);
  border-color: #bdd5c5;
}

.pill.preview {
  color: #9e3f2c;
  border-color: #e8b7a9;
}

.learners {
  margin-left: auto;
  color: var(--muted);
}

@keyframes card-rise {
  from {
    opacity: 0;
    transform: translateY(18px);
  }
  to {
    opacity: 1;
    transform: translateY(0);
  }
}
</style>
