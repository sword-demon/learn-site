<template>
  <!-- 首页课表贴纸：带编号与悬停抬升 -->
  <article
    class="shelf-card"
    :style="{ '--delay': `${index * 70}ms` }"
  >
    <span class="tape" aria-hidden="true" />
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
        <span v-if="course.price_mode === 'free'" class="pill free">免费</span>
        <template v-else>
          <span class="price-now">¥ {{ formatPrice(displayPrice) }}</span>
          <span v-if="onSale" class="price-was">¥ {{ formatPrice(course.list_price) }}</span>
        </template>
        <span v-if="course.preview_available" class="pill preview">试看</span>
        <span class="learners">{{ course.learner_count }} 位学员</span>
      </footer>
    </div>
  </article>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import type { CourseListItemDTO } from '@learn-site/contracts'

defineOptions({ name: 'CourseShelfCard' })

const props = defineProps<{
  course: CourseListItemDTO
  index: number
}>()

// 当前展示价：优惠窗口内优先 sale_price
const displayPrice = computed(() =>
  props.course.sale_price > 0 ? props.course.sale_price : props.course.list_price,
)

// 是否在优惠期（sale 低于标价）
const onSale = computed(
  () => props.course.price_mode !== 'free' && props.course.sale_price < props.course.list_price,
)

function formatPrice(n: number): string {
  return n.toFixed(2)
}
</script>

<style scoped>
.shelf-card {
  position: relative;
  display: grid;
  gap: 0;
  background: rgba(255, 255, 255, 0.92);
  border: 1px solid var(--line);
  border-radius: 18px 18px 22px 22px;
  box-shadow:
    0 14px 36px rgba(18, 90, 78, 0.1),
    inset 0 1px 0 rgba(255, 255, 255, 0.9);
  overflow: hidden;
  transform: rotate(var(--tilt, -0.6deg));
  transition:
    transform 0.35s cubic-bezier(0.22, 1, 0.36, 1),
    box-shadow 0.35s ease;
  animation: card-rise 0.65s cubic-bezier(0.22, 1, 0.36, 1) both;
  animation-delay: var(--delay, 0ms);
}

.shelf-card:nth-child(even) {
  --tilt: 0.5deg;
}

.shelf-card:hover {
  transform: rotate(0deg) translateY(-6px);
  box-shadow:
    0 22px 48px rgba(18, 90, 78, 0.16),
    inset 0 1px 0 rgba(255, 255, 255, 0.95);
}

.tape {
  position: absolute;
  top: 10px;
  left: 50%;
  z-index: 2;
  width: 52px;
  height: 14px;
  margin-left: -26px;
  border-radius: 2px;
  background: linear-gradient(180deg, rgba(255, 248, 220, 0.95), rgba(245, 230, 190, 0.85));
  box-shadow: 0 1px 2px rgba(22, 52, 47, 0.12);
  opacity: 0.88;
}

.index {
  position: absolute;
  top: 12px;
  right: 12px;
  z-index: 2;
  font-size: 11px;
  letter-spacing: 0.12em;
  color: var(--muted);
  background: rgba(247, 252, 251, 0.82);
  padding: 2px 7px;
  border-radius: 999px;
  border: 1px solid var(--line);
}

.cover-link {
  display: block;
  aspect-ratio: 16 / 10;
  overflow: hidden;
  background:
    radial-gradient(120% 80% at 20% 0%, rgba(18, 196, 200, 0.22), transparent 55%),
    linear-gradient(145deg, #dff5ef, #c9ebe3);
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
  color: var(--leaf);
  letter-spacing: 0.08em;
}

.body {
  padding: 14px 16px 16px;
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
  color: var(--leaf);
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
  font-family: 'JetBrains Mono', monospace;
}

.price-was {
  color: var(--muted);
  text-decoration: line-through;
  font-family: 'JetBrains Mono', monospace;
}

.pill {
  display: inline-flex;
  padding: 2px 8px;
  border-radius: 999px;
  font-size: 0.72rem;
  font-weight: 600;
  letter-spacing: 0.02em;
}

.pill.free {
  background: rgba(31, 157, 108, 0.14);
  color: var(--leaf);
  border: 1px solid rgba(31, 157, 108, 0.28);
}

.pill.preview {
  background: rgba(18, 196, 200, 0.12);
  color: #0a7a7d;
  border: 1px solid rgba(18, 196, 200, 0.3);
}

.learners {
  margin-left: auto;
  color: var(--muted);
}

@keyframes card-rise {
  from {
    opacity: 0;
    transform: translateY(18px) rotate(var(--tilt, 0deg));
  }
  to {
    opacity: 1;
    transform: translateY(0) rotate(var(--tilt, 0deg));
  }
}
</style>
