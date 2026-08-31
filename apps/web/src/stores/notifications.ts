import { defineStore } from 'pinia';
import { ref, watch } from 'vue';
import { storeToRefs } from 'pinia';
import { useLoginFamilyStore } from '@/api/login';
import { fetchLearnerProfile } from '@/api/learner';
import { fetchUnreadCount } from '@/api/notifications';
import { getAccessToken, hasTokens } from '@/api/http';
import { createPushConnection } from '@/utils/push';

const POLL_INTERVAL_MS = 30_000;

function resolvePushUrl(appKey: string): string | null {
  if (typeof window === 'undefined') return null;
  const protocol = window.location.protocol === 'https:' ? 'wss:' : 'ws:';
  const sameOrigin = `${protocol}//${window.location.host}/app/${appKey}`;
  const configured = import.meta.env.VITE_PUSH_URL;
  if (import.meta.env.DEV && configured) {
    return configured;
  }
  return sameOrigin;
}

export const useNotificationStore = defineStore('notifications', () => {
  const unreadCount = ref(0);
  const inboxVersion = ref(0);
  let connection: Awaited<ReturnType<typeof createPushConnection>> | null = null;
  let pollTimer: ReturnType<typeof setInterval> | null = null;
  let visibilityBound = false;
  let sessionWatchBound = false;

  function bumpInbox(): void {
    inboxVersion.value += 1;
  }

  async function refreshUnreadCount(): Promise<void> {
    const session = useLoginFamilyStore();
    if (!session.loggedIn) {
      unreadCount.value = 0;
      return;
    }
    try {
      unreadCount.value = (await fetchUnreadCount()).count;
    } catch {
      unreadCount.value = 0;
    }
  }

  function stopPolling(): void {
    if (pollTimer !== null) {
      clearInterval(pollTimer);
      pollTimer = null;
    }
  }

  function startPolling(): void {
    stopPolling();
    pollTimer = setInterval(() => {
      void refreshUnreadCount();
    }, POLL_INTERVAL_MS);
  }

  function bindVisibilityRefresh(): void {
    if (visibilityBound || typeof document === 'undefined') return;
    visibilityBound = true;
    document.addEventListener('visibilitychange', () => {
      const session = useLoginFamilyStore();
      if (document.visibilityState === 'visible' && session.loggedIn) {
        void refreshUnreadCount();
      }
    });
  }

  function disconnect(): void {
    connection?.disconnect();
    connection = null;
    stopPolling();
  }

  async function connect(): Promise<void> {
    disconnect();
    await refreshUnreadCount();

    const session = useLoginFamilyStore();
    if (!session.loggedIn || !hasTokens()) {
      unreadCount.value = 0;
      return;
    }

    const appKey = import.meta.env.VITE_PUSH_APP_KEY;
    const pushUrl = appKey ? resolvePushUrl(appKey) : null;
    if (!pushUrl || !appKey) {
      startPolling();
      return;
    }

    try {
      const profile = await fetchLearnerProfile();
      const access = getAccessToken();
      if (!access) {
        startPolling();
        return;
      }
      connection = await createPushConnection({
        url: pushUrl,
        appKey,
        auth: '/plugin/webman/push/auth',
        getAuthHeader: () => {
          const token = getAccessToken();
          return token ? { Authorization: `Bearer ${token}` } : null;
        },
        getAuthData: () => {
          const token = getAccessToken();
          return token ? { access_token: token } : {};
        },
      });
      if (!connection) {
        startPolling();
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
        bumpInbox();
      });
      startPolling();
    } catch {
      startPolling();
    }
  }

  function ensureSessionWatch(): void {
    if (sessionWatchBound) return;
    sessionWatchBound = true;
    const session = useLoginFamilyStore();
    const { loggedIn } = storeToRefs(session);
    watch(
      loggedIn,
      (value) => {
        if (value) {
          void connect();
        } else {
          disconnect();
          unreadCount.value = 0;
        }
      },
      { immediate: true },
    );
    bindVisibilityRefresh();
  }

  function init(): void {
    ensureSessionWatch();
  }

  return {
    unreadCount,
    inboxVersion,
    init,
    refreshUnreadCount,
    bumpInbox,
    connect,
    disconnect,
  };
});
