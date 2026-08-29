import { onUnmounted, ref, watch } from 'vue';
import { storeToRefs } from 'pinia';
import { useLoginFamilyStore } from '@/api/login';
import { fetchLearnerProfile } from '@/api/learner';
import { fetchUnreadCount } from '@/api/notifications';
import { getAccessToken } from '@/api/http';
import { createPushConnection } from '@/utils/push';

export function usePushNotifications(onNotification?: () => void) {
  const session = useLoginFamilyStore();
  const { loggedIn } = storeToRefs(session);
  const unreadCount = ref(0);
  let connection: Awaited<ReturnType<typeof createPushConnection>> | null = null;

  async function refreshUnreadCount(): Promise<void> {
    if (!loggedIn.value) {
      unreadCount.value = 0;
      return;
    }
    try {
      unreadCount.value = (await fetchUnreadCount()).count;
    } catch {
      unreadCount.value = 0;
    }
  }

  async function connect(): Promise<void> {
    await refreshUnreadCount();
    if (!loggedIn.value) {
      return;
    }
    const pushUrl = import.meta.env.VITE_PUSH_URL;
    const appKey = import.meta.env.VITE_PUSH_APP_KEY;
    if (!pushUrl || !appKey) {
      return;
    }
    try {
      const profile = await fetchLearnerProfile();
      const access = getAccessToken();
      connection = await createPushConnection({
        url: pushUrl,
        appKey,
        auth: '/plugin/webman/push/auth',
        ...(access !== null ? { authHeader: { Authorization: `Bearer ${access}` } } : {}),
      });
      if (!connection) {
        return;
      }
      const channel = connection.subscribe(`private-learner-${profile.account_id}`);
      channel.on('notification', (payload: unknown) => {
        const data = payload as { unread_count?: number };
        if (typeof data.unread_count === 'number') {
          unreadCount.value = data.unread_count;
        } else {
          void refreshUnreadCount();
        }
        onNotification?.();
      });
    } catch {
      /* push is best-effort */
    }
  }

  function disconnect(): void {
    connection?.disconnect();
    connection = null;
  }

  watch(
    loggedIn,
    (value) => {
      disconnect();
      if (value) {
        void connect();
      } else {
        unreadCount.value = 0;
      }
    },
    { immediate: true },
  );

  onUnmounted(() => {
    disconnect();
  });

  return { unreadCount, refreshUnreadCount };
}
