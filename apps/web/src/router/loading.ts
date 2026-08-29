import { ref } from 'vue';

const loading = ref(false);

let hideTimer: ReturnType<typeof setTimeout> | null = null;
let shownAt = 0;

const MIN_VISIBLE_MS = 180;

export function useRouteLoading() {
  return { loading };
}

export function startRouteLoading(): void {
  if (hideTimer) {
    clearTimeout(hideTimer);
    hideTimer = null;
  }
  shownAt = Date.now();
  loading.value = true;
}

export function finishRouteLoading(): void {
  const delay = Math.max(0, MIN_VISIBLE_MS - (Date.now() - shownAt));

  hideTimer = setTimeout(() => {
    loading.value = false;
    hideTimer = null;
  }, delay);
}
