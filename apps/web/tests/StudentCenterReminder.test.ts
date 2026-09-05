// @vitest-environment happy-dom

import { reactive } from 'vue';
import { flushPromises, mount } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

const learnerApi = vi.hoisted(() => ({
  fetchMyLearning: vi.fn(),
  startCourse: vi.fn(),
  fetchFavorites: vi.fn(),
  removeFavorite: vi.fn(),
  fetchOrders: vi.fn(),
  fetchLearnerProfile: vi.fn(),
  updateLearnerProfile: vi.fn(),
}));
const notificationsApi = vi.hoisted(() => ({
  listNotifications: vi.fn(),
  markNotificationRead: vi.fn(),
  fetchUnreadCount: vi.fn(),
}));
const checkinsApi = vi.hoisted(() => ({ listCheckins: vi.fn() }));
const pushApi = vi.hoisted(() => ({ createPushConnection: vi.fn() }));
const routeApi = reactive({ path: '/me/messages', query: {}, fullPath: '/me/messages' });
const routerApi = vi.hoisted(() => ({ push: vi.fn(), replace: vi.fn() }));

vi.mock('@/api/learner', () => learnerApi);
vi.mock('@/api/notifications', () => notificationsApi);
vi.mock('@/api/checkins', () => checkinsApi);
vi.mock('@/utils/push', () => pushApi);
vi.mock('vue-router', () => ({
  useRoute: () => routeApi,
  useRouter: () => routerApi,
}));

import StudentCenterView from '@/views/me/StudentCenterView.vue';
import { clearTokens } from '@/api/http';
import { useLoginFamilyStore } from '@/api/login';
import { useNotificationStore } from '@/stores/notifications';

const tokenPair = {
  access_token: 'access-token',
  access_expires_in: 900,
  refresh_token: 'refresh-token',
  refresh_expires_in: 604800,
};

describe('StudentCenterView learning reminders', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    routeApi.path = '/me/messages';
    routeApi.fullPath = '/me/messages';
    learnerApi.fetchMyLearning.mockResolvedValue({ items: [] });
    learnerApi.fetchFavorites.mockResolvedValue({ items: [], total: 0 });
    learnerApi.fetchOrders.mockResolvedValue({ items: [] });
    learnerApi.fetchLearnerProfile.mockResolvedValue({
      account_id: 1,
      phone: '13900000001',
      nickname: '学员',
      avatar_url: null,
      show_on_course: false,
      status: 'active',
      created_at: '2026-09-04 10:00:00',
    });
    checkinsApi.listCheckins.mockResolvedValue({ items: [], total: 0, page: 1, limit: 20 });
    notificationsApi.fetchUnreadCount.mockResolvedValue({ count: 1 });
    notificationsApi.listNotifications.mockResolvedValue({
      items: [
        {
          id: 8,
          kind: 'learning_reminder',
          title: '优惠券即将到期',
          body: '你的优惠券将于 2026-09-07 23:59 到期',
          resource_type: 'coupon',
          resource_id: 88,
          resource_path: '/me/coupons',
          resource_available: true,
          resource_unavailable_reason: null,
          payload: { rule_code: 'coupon_expiring' },
          read: false,
          created_at: '2026-09-04T10:00:00+08:00',
        },
      ],
      total: 1,
      page: 1,
      limit: 20,
    });
    notificationsApi.markNotificationRead.mockResolvedValue({ read: true });
    pushApi.createPushConnection.mockResolvedValue({ subscribe: vi.fn(), disconnect: vi.fn() });
  });

  afterEach(() => {
    useNotificationStore().disconnect();
    clearTokens();
  });

  it('renders the server resource and reason, then marks it read before navigation', async () => {
    const pinia = createPinia();
    setActivePinia(pinia);
    useLoginFamilyStore().signIn(tokenPair);
    const wrapper = mount(StudentCenterView, {
      global: {
        plugins: [pinia],
        stubs: { RouterLink: { template: '<a><slot /></a>' } },
      },
    });
    await flushPromises();

    expect(wrapper.text()).toContain('优惠券即将到期');
    expect(wrapper.text()).toContain('2026-09-07 23:59');
    await wrapper.get('button[data-resource-id="8"]').trigger('click');
    await flushPromises();

    expect(notificationsApi.markNotificationRead).toHaveBeenCalledWith(8);
    expect(routerApi.push).toHaveBeenCalledWith('/me/coupons');
  });
});
