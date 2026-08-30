// @vitest-environment happy-dom

import { flushPromises, mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import CheckinListView from '@/views/me/CheckinListView.vue';

const checkinsApi = vi.hoisted(() => ({
  listCheckins: vi.fn(),
}));

vi.mock('@/api/checkins', () => checkinsApi);
vi.mock('@/components/MarkdownRenderer.vue', () => ({
  default: { props: ['html'], template: '<div class="rich">{{ html }}</div>' },
}));

describe('CheckinListView', () => {
  it('renders empty state when no records', async () => {
    checkinsApi.listCheckins.mockResolvedValue({ items: [], total: 0, page: 1, limit: 20 });
    const wrapper = mount(CheckinListView, {
      global: {
        provide: {
          dailyCheckinPrompt: {
            dialogVisible: { value: false },
            afterSuccess: vi.fn(),
          },
        },
      },
    });
    await flushPromises();
    expect(wrapper.text()).toContain('还没有签到记录');
  });

  it('renders list items', async () => {
    checkinsApi.listCheckins.mockResolvedValue({
      items: [
        {
          id: 1,
          checkin_date: '2026-08-30',
          plan_html: '<p>今日计划</p>',
          checked_in_at: '2026-08-30T09:00:00+08:00',
        },
      ],
      total: 1,
      page: 1,
      limit: 20,
    });
    const wrapper = mount(CheckinListView, {
      global: {
        provide: {
          dailyCheckinPrompt: {
            dialogVisible: { value: false },
            afterSuccess: vi.fn(),
          },
        },
      },
    });
    await flushPromises();
    expect(wrapper.text()).toContain('2026-08-30');
    expect(wrapper.text()).toContain('今日计划');
  });

  it('unsubscribes the success hook when the page unmounts', async () => {
    checkinsApi.listCheckins.mockResolvedValue({ items: [], total: 0, page: 1, limit: 20 });
    const unsubscribe = vi.fn();
    const afterSuccess = vi.fn(() => unsubscribe);
    const wrapper = mount(CheckinListView, {
      global: {
        provide: {
          dailyCheckinPrompt: {
            dialogVisible: { value: false },
            afterSuccess,
          },
        },
      },
    });
    await flushPromises();

    wrapper.unmount();
    expect(afterSuccess).toHaveBeenCalledOnce();
    expect(unsubscribe).toHaveBeenCalledOnce();
  });
});
