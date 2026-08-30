import { defineStore } from 'pinia';
import { ref } from 'vue';
import type { CategoryNode, CourseListItemDTO, SiteIntro } from '@learn-site/contracts';
import { fetchHome } from '@/api/learner';

export const useHomeStore = defineStore('home', () => {
  const categories = ref<CategoryNode[]>([]);
  const recentCourses = ref<CourseListItemDTO[]>([]);
  const intro = ref<SiteIntro | null>(null);
  const loading = ref(false);
  const loaded = ref(false);
  const error = ref(false);
  let inflight: Promise<void> | null = null;

  function load(): Promise<void> {
    if (loaded.value) return Promise.resolve();
    if (inflight) return inflight;

    loading.value = true;
    error.value = false;
    inflight = (async () => {
      try {
        const home = await fetchHome();
        categories.value = home.categories;
        recentCourses.value = home.recent_courses;
        intro.value = home.site_intro;
        loaded.value = true;
      } catch {
        error.value = true;
      } finally {
        loading.value = false;
        inflight = null;
      }
    })();

    return inflight;
  }

  return { categories, recentCourses, intro, loading, error, load };
});
