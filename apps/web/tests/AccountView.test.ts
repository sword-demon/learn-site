// @vitest-environment happy-dom

import { flushPromises, mount } from '@vue/test-utils';
import { createPinia } from 'pinia';
import { beforeEach, describe, expect, it, vi } from 'vitest';

const learnerApi = vi.hoisted(() => ({
  fetchLearnerProfile: vi.fn(),
  updateLearnerProfile: vi.fn(),
}));

vi.mock('@/api/learner', () => learnerApi);

import AccountView from '@/views/me/AccountView.vue';

const profile = {
  account_id: 7,
  phone: '13800138000',
  nickname: '旧称呼',
  avatar_url: null,
  show_on_course: false,
  status: 'active',
  created_at: '2026-08-30 10:00:00',
};

describe('AccountView', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    learnerApi.fetchLearnerProfile.mockResolvedValue(profile);
    learnerApi.updateLearnerProfile.mockResolvedValue({
      ...profile,
      nickname: '新称呼',
      show_on_course: true,
    });
  });

  it('uses Element Plus fields and preserves the profile update payload', async () => {
    const wrapper = mount(AccountView, {
      global: { plugins: [createPinia()] },
    });
    await flushPromises();

    const inputs = wrapper.findAllComponents({ name: 'ElInput' });
    expect(inputs).toHaveLength(2);
    expect(inputs[0]?.props('disabled')).toBe(true);
    expect(inputs[0]?.props('modelValue')).toBe('13800138000');

    await inputs[1]?.setValue(' 新称呼 ');
    await wrapper.get('[role="switch"]').trigger('click');
    expect(wrapper.get('button[type="submit"]').text()).toContain('保存资料');
    await wrapper.get('form').trigger('submit');
    await flushPromises();

    expect(learnerApi.updateLearnerProfile).toHaveBeenCalledWith({
      nickname: '新称呼',
      show_on_course: true,
    });
    expect(wrapper.text()).toContain('资料已更新');
  });
});
