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
  banners: [],
  recommended_maps: [],
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
    expect(store.banners).toEqual(homePayload.banners);
    expect(store.intro).toEqual(homePayload.site_intro);
    expect(store.loading).toBe(false);
    expect(store.error).toBe(false);
  });

  it('reads the latest saved public profile in a fresh visitor session', async () => {
    const first = useHomeStore();
    await first.load();

    setActivePinia(createPinia());
    learnerApi.fetchHome.mockResolvedValueOnce({
      ...homePayload,
      site_intro: {
        ...homePayload.site_intro,
        title: '林间课室',
        subtitle: '持续学习，持续记录',
        updated_at: '2026-08-28 11:00:00',
      },
    });
    const refreshed = useHomeStore();
    await refreshed.load();

    expect(refreshed.intro?.title).toBe('林间课室');
    expect(refreshed.intro?.updated_at).toBe('2026-08-28 11:00:00');
    expect(learnerApi.fetchHome).toHaveBeenCalledTimes(2);
  });
});
