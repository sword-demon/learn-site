import { defineStore } from 'pinia';
import { ref } from 'vue';
import type {
  BannerPublicDTO,
  CategoryNode,
  CourseListItemDTO,
  RecommendedMapDTO,
  SiteIntro,
} from '@learn-site/contracts';
import { fetchHome } from '@/api/learner';

export const useHomeStore = defineStore('home', () => {
  const categories = ref<CategoryNode[]>([]);
  const recentCourses = ref<CourseListItemDTO[]>([]);
  const banners = ref<BannerPublicDTO[]>([]);
  const recommendedMaps = ref<RecommendedMapDTO[]>([]);
  const intro = ref<SiteIntro | null>(null);
  const loading = ref(false);
  const loaded = ref(false);
  const error = ref(false);
  let inflight: Promise<void> | null = null;

  function load(options?: { force?: boolean }): Promise<void> {
    if (loaded.value && !options?.force) return Promise.resolve();
    if (inflight && !options?.force) return inflight;

    if (options?.force) {
      loaded.value = false;
    }

    loading.value = true;
    error.value = false;
    inflight = (async () => {
      try {
        const home = await fetchHome();
        categories.value = home.categories;
        recentCourses.value = home.recent_courses;
        banners.value = home.banners;
        recommendedMaps.value = home.recommended_maps ?? [];
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

  return {
    categories,
    recentCourses,
    banners,
    recommendedMaps,
    intro,
    loading,
    error,
    load,
  };
});
