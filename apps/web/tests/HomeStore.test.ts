import { createPinia, setActivePinia } from 'pinia';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import type { HomePayload } from '@learn-site/contracts';

const learnerApi = vi.hoisted(() => ({
  fetchHome: vi.fn(),
}));

vi.mock('@/api/learner', () => learnerApi);

import { useHomeStore } from '@/stores/home';

const homePayload: HomePayload = {
  categories: [{ id: 1, name: '编程', children: [] }],
  recent_courses: [],
  site_intro: {
    title: '把每一次学习，收进自己的课程档案',
    subtitle: '从一门课开始。',
    body_html: '',
    contact_email: 'courses@example.test',
    updated_at: '2026-08-28 10:00:00',
  },
};

describe('home store', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    setActivePinia(createPinia());
    learnerApi.fetchHome.mockResolvedValue(homePayload);
  });

  it('coalesces concurrent loads so shared home consumers issue one request', async () => {
    const store = useHomeStore();

    await Promise.all([store.load(), store.load()]);

    expect(learnerApi.fetchHome).toHaveBeenCalledTimes(1);
    expect(store.categories).toEqual(homePayload.categories);
    expect(store.intro).toEqual(homePayload.site_intro);
    expect(store.loading).toBe(false);
    expect(store.error).toBe(false);
  });
});
