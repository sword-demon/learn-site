// @vitest-environment happy-dom

import { createPinia, setActivePinia } from 'pinia';
import { flushPromises, mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { ref } from 'vue';

const loggedIn = ref(false);
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
}));

vi.mock('@/api/login', () => ({
  useLoginFamilyStore: () => ({ loggedIn }),
}));
vi.mock('@/api/notifications', () => notificationsApi);
vi.mock('@/api/learner', () => learnerApi);
vi.mock('@/utils/push', () => pushApi);
vi.mock('@/api/http', () => httpApi);

import { usePushNotifications } from '@/composables/usePushNotifications';

const TestHost = {
  template: '<div />',
  setup() {
    const { unreadCount } = usePushNotifications();
    return { unreadCount };
  },
};

describe('usePushNotifications', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    setActivePinia(createPinia());
    loggedIn.value = false;
    notificationsApi.fetchUnreadCount.mockResolvedValue({ count: 2 });
    learnerApi.fetchLearnerProfile.mockResolvedValue({ account_id: 42 });
    httpApi.getAccessToken.mockReturnValue('token-abc');
    pushApi.createPushConnection.mockResolvedValue({
      subscribe: vi.fn(() => ({
        on: vi.fn(),
      })),
      disconnect: vi.fn(),
    });
  });

  it('loads unread count when logged in', async () => {
    loggedIn.value = true;
    const wrapper = mount(TestHost);
    await flushPromises();

    expect(notificationsApi.fetchUnreadCount).toHaveBeenCalled();
    expect(wrapper.vm.unreadCount).toBe(2);
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
});
