import { onMounted, ref, watch } from 'vue';

const STORAGE_KEY = 'learn-portal-theme';

const isNight = ref(false);

function applyTheme(night: boolean): void {
  document.documentElement.dataset.theme = night ? 'night' : 'day';
}

function readStoredTheme(): boolean {
  try {
    return localStorage.getItem(STORAGE_KEY) === 'night';
  } catch {
    return false;
  }
}

function persistTheme(night: boolean): void {
  try {
    localStorage.setItem(STORAGE_KEY, night ? 'night' : 'day');
  } catch {
    /* ignore quota / private mode */
  }
}

export function useTheme() {
  onMounted(() => {
    isNight.value = readStoredTheme();
    applyTheme(isNight.value);
  });

  watch(isNight, (night) => {
    applyTheme(night);
    persistTheme(night);
  });

  function toggleNight(): void {
    isNight.value = !isNight.value;
  }

  return { isNight, toggleNight };
}
