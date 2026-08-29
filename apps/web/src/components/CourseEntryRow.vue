<template>
  <article class="entry" @click="goCourse">
    <div class="cover" :style="coverStyle">
      <img v-if="course.cover_url" :src="course.cover_url" :alt="course.title" />
      <b v-else class="cover-glyph">{{ glyph }}</b>
      <span v-if="!course.cover_url" class="cover-meta">{{ coverMeta }}</span>
    </div>
    <div>
      <h3>《{{ course.title }}》</h3>
      <p class="digest">{{ course.summary || '讲师还没有写简介。' }}</p>
      <div class="meta">
        <b>{{ course.teacher_name }}</b>
        <span>{{ course.learner_count }} 人在学</span>
        <span v-if="course.price_mode === 'free'" class="tag tag-free">免费</span>
        <span v-else-if="onSale" class="tag tag-sale">限时 ¥{{ formatPrice(course.sale_price) }}</span>
        <span v-if="course.preview_available" class="tag tag-trial">可试看</span>
      </div>
    </div>
    <div class="entry-side" @click.stop>
      <div class="price-line">
        <span v-if="course.price_mode === 'free'" class="tag tag-free">免费</span>
        <template v-else>
          <span class="price-now" style="font-size: 17px">¥ {{ formatPrice(displayPrice) }}</span>
          <span v-if="onSale" class="price-std">¥ {{ formatPrice(course.list_price) }}</span>
        </template>
      </div>
      <button
        v-if="showFavorite"
        type="button"
        class="favbtn"
        :class="{ on: favorited }"
        :title="favorited ? '取消收藏' : '收藏'"
        :disabled="favoriteBusy"
        @click="toggleFavorite"
      >
        {{ favorited ? '♥' : '♡' }}
      </button>
    </div>
  </article>
</template>

<script setup lang="ts">
import { computed, ref } from 'vue';
import { useRouter } from 'vue-router';
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

const router = useRouter();
const session = useLoginFamilyStore();
const favorited = ref(props.initialFavorited);
const favoriteBusy = ref(false);

const HUES = ['#34566b', '#4c7a5a', '#a8842c', '#6b4a5e', '#3d6b6b', '#5a6470'];

const hue = computed(() => HUES[props.course.id % HUES.length]);
const glyph = computed(() => props.course.title.slice(0, 1));
const coverMeta = computed(() => props.course.title.slice(0, 4).toUpperCase());
const coverStyle = computed(() => ({ '--hue': hue.value, height: '96px' }));

const displayPrice = computed(() =>
  props.course.sale_price > 0 ? props.course.sale_price : props.course.list_price,
);

const onSale = computed(
  () =>
    props.course.price_mode !== 'free' && props.course.sale_price < props.course.list_price,
);

function formatPrice(n: number): string {
  return n % 1 === 0 ? String(n) : n.toFixed(2);
}

function goCourse(): void {
  void router.push(`/courses/${props.course.id}`);
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
