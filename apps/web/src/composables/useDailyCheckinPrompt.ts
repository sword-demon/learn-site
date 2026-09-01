import { onMounted, ref, watch } from 'vue';
import { storeToRefs } from 'pinia';
import { fetchTodayCheckinStatus } from '@/api/checkins';
import { useLoginFamilyStore } from '@/api/login';

const DISMISS_PREFIX = 'checkin_dismissed_';

export function useDailyCheckinPrompt() {
  const session = useLoginFamilyStore();
  const { loggedIn } = storeToRefs(session);
  const dialogVisible = ref(false);
  const checkedInToday = ref(false);
  const serverDate = ref('');
  const successHooks = new Set<() => void>();

  async function refreshStatus(options: { forceOpen?: boolean } = {}): Promise<void> {
    if (!loggedIn.value) {
      dialogVisible.value = false;
      checkedInToday.value = false;
      return;
    }
    try {
      const status = await fetchTodayCheckinStatus();
      serverDate.value = status.server_date;
      checkedInToday.value = status.checked_in;
      if (status.checked_in) {
        dialogVisible.value = false;
        return;
      }
      const dismissed = sessionStorage.getItem(`${DISMISS_PREFIX}${status.server_date}`) === '1';
      dialogVisible.value = options.forceOpen ? true : !dismissed;
    } catch {
      dialogVisible.value = false;
    }
  }

  function dismissForSession(): void {
    if (serverDate.value) {
      sessionStorage.setItem(`${DISMISS_PREFIX}${serverDate.value}`, '1');
    }
    dialogVisible.value = false;
  }

  function onCheckinSuccess(): void {
    checkedInToday.value = true;
    dialogVisible.value = false;
    successHooks.forEach((hook) => hook());
  }

  function afterSuccess(hook: () => void): () => void {
    successHooks.add(hook);
    return () => successHooks.delete(hook);
  }

  onMounted(() => {
    void refreshStatus();
  });

  watch(
    loggedIn,
    (isLoggedIn) => {
      if (!isLoggedIn) {
        if (serverDate.value) {
          sessionStorage.removeItem(`${DISMISS_PREFIX}${serverDate.value}`);
        }
        dialogVisible.value = false;
        checkedInToday.value = false;
        serverDate.value = '';
        return;
      }
      void refreshStatus();
    },
    { flush: 'sync' },
  );

  return {
    dialogVisible,
    checkedInToday,
    refreshStatus,
    dismissForSession,
    onCheckinSuccess,
    afterSuccess,
  };
}
