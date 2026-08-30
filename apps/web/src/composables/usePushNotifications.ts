import { onMounted, onUnmounted, watch } from 'vue';
import { storeToRefs } from 'pinia';
import { useNotificationStore } from '@/stores/notifications';

export function usePushNotifications(onNotification?: () => void) {
  const store = useNotificationStore();
  const { unreadCount } = storeToRefs(store);

  store.init();

  if (onNotification) {
    onMounted(() => {
      const stop = watch(
        () => store.inboxVersion,
        () => {
          onNotification();
        },
      );
      onUnmounted(() => stop());
    });
  }

  return { unreadCount, refreshUnreadCount: store.refreshUnreadCount };
}
