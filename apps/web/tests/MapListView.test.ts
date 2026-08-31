// @vitest-environment happy-dom

import { flushPromises, mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import type { LearnerMapListDTO } from '@learn-site/contracts';

const learnerApi = vi.hoisted(() => ({
  fetchLearningMaps: vi.fn(),
}));

vi.mock('@/api/learner', () => learnerApi);

import MapListView from '@/views/maps/MapListView.vue';

const RouterLinkStub = {
  props: { to: { type: [String, Object], required: true } },
  template: '<a :href="String(to)"><slot /></a>',
};

function enrolledMap(
  overrides: Partial<LearnerMapListDTO['items'][number]> = {},
): LearnerMapListDTO['items'][number] {
  return {
    id: 7,
    department_id: 3,
    title: '前端工程师成长路线',
    summary: '从基础到工程化',
    cover_url: 'https://example.test/map-cover.png',
    objective: '建立完整的前端工程能力',
    audience: '具有 JavaScript 基础的开发者',
    status: 'published',
    created_at: '2026-08-25 10:00:00',
    updated_at: '2026-08-25 10:00:00',
    enrollment: {
      enrolled_at: '2026-08-25 11:00:00',
      completed_courses: 2,
      total_courses: 5,
      progress_percent: 40,
      completed_at: null,
    },
    ...overrides,
  };
}

function unjoinedMap(
  overrides: Partial<LearnerMapListDTO['items'][number]> = {},
): LearnerMapListDTO['items'][number] {
  return {
    ...enrolledMap(overrides),
    id: 9,
    title: 'Go 工程进阶',
    summary: '理解 Go 在生产环境的核心模式',
    cover_url: null,
    objective: null,
    audience: null,
    enrollment: null,
  };
}

describe('MapListView', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    learnerApi.fetchLearningMaps.mockResolvedValue({
      items: [enrolledMap()],
      total: 1,
      page: 1,
      limit: 50,
    });
  });

  it('renders the 3-column grid with cover, progress, and continue CTA', async () => {
    const wrapper = mount(MapListView, { global: { stubs: { RouterLink: RouterLinkStub } } });
    await flushPromises();

    wrapper.get('[data-testid="map-grid"]');

    expect(wrapper.get('img[alt="前端工程师成长路线"]').attributes('src')).toBe(
      'https://example.test/map-cover.png',
    );
    expect(wrapper.text()).toContain('建立完整的前端工程能力');
    expect(wrapper.text()).toContain('2/5');
    expect(wrapper.text()).toContain('40%');
    const card = wrapper.get('[data-map-id="7"]');
    expect(card.text()).toContain('前端工程师成长路线');
    expect(card.findAll('a[href="/maps/7"]').length).toBeGreaterThan(0);
    expect(card.text()).toContain('继续学习');
  });

  it('offers a retry action when loading maps fails', async () => {
    learnerApi.fetchLearningMaps.mockRejectedValueOnce(new Error('network down'));
    const wrapper = mount(MapListView, { global: { stubs: { RouterLink: RouterLinkStub } } });
    await flushPromises();

    expect(wrapper.text()).toContain('地图暂时读不到');
    expect(wrapper.find('button[data-action="retry"]').exists()).toBe(true);

    learnerApi.fetchLearningMaps.mockResolvedValueOnce({
      items: [enrolledMap()],
      total: 1,
      page: 1,
      limit: 50,
    });
    await wrapper.get('button[data-action="retry"]').trigger('click');
    await flushPromises();

    expect(wrapper.text()).toContain('前端工程师成长路线');
    expect(learnerApi.fetchLearningMaps).toHaveBeenCalledTimes(2);
  });

  it('shows start CTA for unjoined maps and continue CTA for enrolled maps', async () => {
    learnerApi.fetchLearningMaps.mockResolvedValueOnce({
      items: [enrolledMap(), unjoinedMap()],
      total: 2,
      page: 1,
      limit: 50,
    });

    const wrapper = mount(MapListView, { global: { stubs: { RouterLink: RouterLinkStub } } });
    await flushPromises();

    const cards = wrapper.findAll('.map-card');
    expect(cards).toHaveLength(2);

    const enrolled = wrapper.find('[data-map-id="7"]');
    const unjoined = wrapper.find('[data-map-id="9"]');
    expect(enrolled.text()).toContain('继续学习');
    expect(enrolled.text()).not.toContain('开始学习');
    expect(unjoined.text()).toContain('开始学习');
    expect(unjoined.text()).not.toContain('继续学习');
  });

  it('shows the empty state when no maps are published', async () => {
    learnerApi.fetchLearningMaps.mockResolvedValueOnce({
      items: [],
      total: 0,
      page: 1,
      limit: 50,
    });

    const wrapper = mount(MapListView, { global: { stubs: { RouterLink: RouterLinkStub } } });
    await flushPromises();

    expect(wrapper.text()).toContain('还没有可用的学习地图');
    expect(wrapper.find('[data-testid="map-grid"]').exists()).toBe(false);
  });
});
