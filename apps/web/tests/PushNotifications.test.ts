// @vitest-environment happy-dom

import { createPinia, disposePinia, setActivePinia, type Pinia } from 'pinia';
import { flushPromises, mount } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { ref } from 'vue';

let loggedIn = ref(false);
const notificationsApi = vi.hoisted(() => ({
  fetchUnreadCount: vi.fn(),
}));
const learnerApi = vi.hoisted(() => ({
  fetchLearnerProfile: vi.fn(),
}));
const pushApi = vi.hoisted(() => ({
  createPushConnection: vi.fn(),
}));
const httpApi = vi.hoisted(() => ({
  getAccessToken: vi.fn(),
  hasTokens: vi.fn(),
}));

vi.mock('@/api/login', () => ({
  useLoginFamilyStore: () => ({ loggedIn }),
}));
vi.mock('@/api/notifications', () => notificationsApi);
vi.mock('@/api/learner', () => learnerApi);
vi.mock('@/utils/push', () => pushApi);
vi.mock('@/api/http', () => httpApi);

import { usePushNotifications } from '@/composables/usePushNotifications';
import { useNotificationStore } from '@/stores/notifications';

const TestHost = {
  template: '<div />',
  setup() {
    const { unreadCount } = usePushNotifications();
    return { unreadCount };
  },
};

describe('usePushNotifications', () => {
  let pinia: Pinia;

  beforeEach(() => {
    vi.clearAllMocks();
    vi.useFakeTimers();
    pinia = createPinia();
    setActivePinia(pinia);
    loggedIn = ref(false);
    notificationsApi.fetchUnreadCount.mockResolvedValue({ count: 2 });
    learnerApi.fetchLearnerProfile.mockResolvedValue({ account_id: 42 });
    httpApi.getAccessToken.mockReturnValue('token-abc');
    httpApi.hasTokens.mockReturnValue(true);
    pushApi.createPushConnection.mockResolvedValue({
      subscribe: vi.fn(() => ({
        on: vi.fn(),
      })),
      disconnect: vi.fn(),
    });
  });

  afterEach(() => {
    useNotificationStore().disconnect();
    disposePinia(pinia);
    vi.clearAllTimers();
    vi.useRealTimers();
  });

  it('loads unread count when logged in', async () => {
    loggedIn.value = true;
    const wrapper = mount(TestHost);
    await flushPromises();

    expect(notificationsApi.fetchUnreadCount).toHaveBeenCalled();
    expect(wrapper.vm.unreadCount).toBe(2);
    expect(pushApi.createPushConnection).toHaveBeenCalledWith(
      expect.objectContaining({
        auth: '/plugin/webman/push/auth',
        getAuthHeader: expect.any(Function),
        getAuthData: expect.any(Function),
      }),
    );
  });

  it('skips push when there is no access token', async () => {
    httpApi.hasTokens.mockReturnValue(true);
    httpApi.getAccessToken.mockReturnValue(null);
    loggedIn.value = true;
    mount(TestHost);
    await flushPromises();

    expect(pushApi.createPushConnection).not.toHaveBeenCalled();
    expect(notificationsApi.fetchUnreadCount).toHaveBeenCalled();
  });

  it('clears unread count on logout', async () => {
    loggedIn.value = true;
    const wrapper = mount(TestHost);
    await flushPromises();
    expect(wrapper.vm.unreadCount).toBe(2);

    loggedIn.value = false;
    await flushPromises();
    expect(wrapper.vm.unreadCount).toBe(0);
  });

  it('polls unread count while logged in', async () => {
    loggedIn.value = true;
    mount(TestHost);
    await flushPromises();
    expect(notificationsApi.fetchUnreadCount).toHaveBeenCalledTimes(1);

    notificationsApi.fetchUnreadCount.mockResolvedValueOnce({ count: 5 });
    await vi.advanceTimersByTimeAsync(30_000);
    expect(notificationsApi.fetchUnreadCount).toHaveBeenCalledTimes(2);

    const store = useNotificationStore();
    expect(store.unreadCount).toBe(5);
  });
});
