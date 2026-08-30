<script setup lang="ts">
import type { ChapterWithLessonSummariesDTO } from '@learn-site/contracts';

defineOptions({ name: 'CourseOutline' });

defineProps<{
  chapters: ChapterWithLessonSummariesDTO[];
  activeLessonId?: number;
}>();

const emit = defineEmits<{ (event: 'select', lessonId: number): void }>();
</script>

<template>
  <ol class="chapter-list course-outline">
    <li v-for="chapter in chapters" :key="chapter.id" class="chapter">
      <div class="chapter-heading">
        <span class="chapter-number latin">{{ String(chapter.sort + 1).padStart(2, '0') }}</span>
        <div>
          <h3 class="chapter-title">{{ chapter.title }}</h3>
          <p class="chapter-meta">{{ chapter.lessons.length }} 个课节</p>
        </div>
      </div>
      <ol class="lesson-list">
        <li
          v-for="lesson in chapter.lessons"
          :key="lesson.id"
          class="lesson-row"
          :class="{
            active: lesson.id === activeLessonId,
            'is-locked': lesson.locked,
            'is-preview': lesson.is_preview,
          }"
        >
          <el-button text class="outline-link" @click="emit('select', lesson.id)">
            <span class="lesson-index latin">{{ String(lesson.sort + 1).padStart(2, '0') }}</span>
            <span class="lesson-copy">
              <span class="lesson-name">{{ lesson.title }}</span>
              <span class="lesson-meta">
                <span class="kind">{{ lesson.content_type }}</span>
                <el-tag v-if="lesson.is_preview" type="warning" size="small" effect="plain"
                  >试看</el-tag
                >
              </span>
            </span>
          </el-button>
        </li>
      </ol>
    </li>
  </ol>
</template>
