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

const mapList: LearnerMapListDTO = {
  items: [
    {
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
    },
  ],
  total: 1,
  page: 1,
  limit: 50,
};

describe('MapListView', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    learnerApi.fetchLearningMaps.mockResolvedValue(mapList);
  });

  it('renders map metadata, cover, and learner progress', async () => {
    const wrapper = mount(MapListView, { global: { stubs: { RouterLink: RouterLinkStub } } });
    await flushPromises();

    expect(wrapper.get('img[alt="前端工程师成长路线封面"]').attributes('src')).toBe(
      'https://example.test/map-cover.png',
    );
    expect(wrapper.text()).toContain('建立完整的前端工程能力');
    expect(wrapper.text()).toContain('具有 JavaScript 基础的开发者');
    expect(wrapper.text()).toContain('2/5 (40%)');
    expect(wrapper.get('a[href="/maps/7"]').text()).toContain('前端工程师成长路线');
  });

  it('offers a retry action when loading maps fails', async () => {
    learnerApi.fetchLearningMaps.mockRejectedValueOnce(new Error('network down'));
    const wrapper = mount(MapListView, { global: { stubs: { RouterLink: RouterLinkStub } } });
    await flushPromises();

    expect(wrapper.text()).toContain('地图暂时读不到');
    expect(wrapper.find('button[data-action="retry"]').exists()).toBe(true);

    learnerApi.fetchLearningMaps.mockResolvedValueOnce(mapList);
    await wrapper.get('button[data-action="retry"]').trigger('click');
    await flushPromises();

    expect(wrapper.text()).toContain('前端工程师成长路线');
    expect(learnerApi.fetchLearningMaps).toHaveBeenCalledTimes(2);
  });
});
