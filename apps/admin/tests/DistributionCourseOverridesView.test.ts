// @vitest-environment happy-dom

import { flushPromises, mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { installElementPlus } from '@/plugins/element-plus';

const api = vi.hoisted(() => ({
  fetchCourseOverrides: vi.fn(),
  saveCourseOverride: vi.fn(),
}));

vi.mock('@/api/distribution', () => api);

import DistributionCourseOverridesView from '@/views/distribution/DistributionCourseOverridesView.vue';

describe('DistributionCourseOverridesView', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    api.fetchCourseOverrides.mockResolvedValue({
      items: [
        {
          course_id: 12,
          enabled: true,
          level1_pct: null,
          level2_pct: null,
          level3_pct: null,
          per_order_cap_cents: null,
          per_learner_course_cap_cents: null,
          updated_at: '2026-09-10T00:00:00+08:00',
          updated_by: 1,
        },
      ],
      total: 1,
      page: 1,
      limit: 20,
    });
    api.saveCourseOverride.mockResolvedValue({
      course_id: 12,
      enabled: false,
      level1_pct: null,
      level2_pct: null,
      level3_pct: null,
      per_order_cap_cents: null,
      per_learner_course_cap_cents: null,
      updated_at: '2026-09-10T00:00:00+08:00',
      updated_by: 1,
    });
  });

  it('toggles an override', async () => {
    const wrapper = mount(DistributionCourseOverridesView, {
      global: { plugins: [installElementPlus] },
    });
    await flushPromises();
    const button = wrapper.get('[data-action="toggle"]');
    await button.trigger('click');
    await flushPromises();
    expect(api.saveCourseOverride).toHaveBeenCalledWith(12, { enabled: false });
    wrapper.unmount();
  });
});
