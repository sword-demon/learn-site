import NProgress from 'nprogress';

let hideTimer: ReturnType<typeof setTimeout> | null = null;
let shownAt = 0;

const MIN_VISIBLE_MS = 180;

export function startRouteLoading(): void {
  if (hideTimer) {
    clearTimeout(hideTimer);
    hideTimer = null;
  }
  shownAt = Date.now();
  NProgress.start();
}

export function finishRouteLoading(): void {
  const delay = Math.max(0, MIN_VISIBLE_MS - (Date.now() - shownAt));

  hideTimer = setTimeout(() => {
    NProgress.done();
    hideTimer = null;
  }, delay);
}
