// @vitest-environment happy-dom

import { flushPromises, mount } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

const notificationsApi = vi.hoisted(() => ({
  listNotifications: vi.fn(),
  markNotificationRead: vi.fn(),
  fetchUnreadCount: vi.fn(),
}));
const learnerApi = vi.hoisted(() => ({
  fetchLearnerProfile: vi.fn(),
}));
const pushApi = vi.hoisted(() => ({
  createPushConnection: vi.fn(),
}));

vi.mock('@/api/notifications', () => notificationsApi);
vi.mock('@/api/learner', () => learnerApi);
vi.mock('@/utils/push', () => pushApi);

import MessagesView from '@/views/me/MessagesView.vue';
import { clearTokens } from '@/api/http';
import { useLoginFamilyStore } from '@/api/login';
import { useNotificationStore } from '@/stores/notifications';

const tokenPair = {
  access_token: 'access-token',
  access_expires_in: 900,
  refresh_token: 'refresh-token',
  refresh_expires_in: 604800,
};

describe('MessagesView', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    notificationsApi.fetchUnreadCount.mockResolvedValue({ count: 2 });
    learnerApi.fetchLearnerProfile.mockResolvedValue({ account_id: 42 });
    pushApi.createPushConnection.mockResolvedValue({
      subscribe: vi.fn(() => ({ on: vi.fn() })),
      disconnect: vi.fn(),
    });
    notificationsApi.listNotifications.mockResolvedValue({
      items: [
        {
          id: 1,
          kind: 'question_update',
          title: '问题有新回复',
          body: '管理员回复了你的问题',
          resource_type: 'question',
          resource_id: 12,
          resource_available: true,
          payload: { course_id: 5 },
          read: false,
          created_at: '2026-08-28 10:00:00',
        },
        {
          id: 2,
          kind: 'progress_reset',
          title: '学习进度已重置',
          body: null,
          resource_type: 'course',
          resource_id: 5,
          resource_available: true,
          payload: null,
          read: true,
          created_at: '2026-08-28 09:30:00',
        },
        {
          id: 4,
          kind: 'announcement',
          title: '系统维护通知',
          body: '今晚维护',
          dispatch_id: 12,
          resource_type: null,
          resource_id: null,
          resource_available: false,
          payload: null,
          read: false,
          created_at: '2026-08-28 08:00:00',
        },
      ],
      total: 3,
      page: 1,
      limit: 20,
    });
    notificationsApi.markNotificationRead.mockResolvedValue({ read: true });
  });

  afterEach(() => {
    useNotificationStore().disconnect();
    clearTokens();
  });

  it('renders messages and marks an unread item as read', async () => {
    const pinia = createPinia();
    setActivePinia(pinia);
    useLoginFamilyStore().signIn(tokenPair);
    const wrapper = mount(MessagesView, {
      global: {
        plugins: [pinia],
        stubs: { RouterLink: { props: ['to'], template: '<a><slot /></a>' } },
      },
    });
    await flushPromises();

    expect(wrapper.text()).toContain('问题有新回复');
    expect(wrapper.text()).toContain('学习进度已重置');
    expect(wrapper.text()).toContain('公告');
    expect(wrapper.text()).toContain('系统维护通知');
    notificationsApi.fetchUnreadCount.mockClear();
    await wrapper.get('button[data-read-id="1"]').trigger('click');
    await flushPromises();
    expect(notificationsApi.markNotificationRead).toHaveBeenCalledWith(1);
    expect(notificationsApi.fetchUnreadCount).toHaveBeenCalledTimes(1);
    expect(wrapper.find('button[data-read-id="1"]').exists()).toBe(false);
  });
});
