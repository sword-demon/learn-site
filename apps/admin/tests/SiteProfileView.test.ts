// @vitest-environment happy-dom

import { flushPromises, mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { installElementPlus } from '@/plugins/element-plus';

const siteApi = vi.hoisted(() => ({
  fetchSiteProfile: vi.fn(),
  updateSiteProfile: vi.fn(),
}));
const message = vi.hoisted(() => ({ success: vi.fn() }));

vi.mock('@/api/site', () => siteApi);
vi.mock('@/components/course/ContentEditor.vue', () => ({
  default: {
    name: 'ContentEditor',
    props: ['modelValue', 'placeholder', 'height'],
    emits: ['update:modelValue'],
    template:
      '<textarea name="body_html" :value="modelValue" @input="$emit(\'update:modelValue\', $event.target.value)" />',
  },
}));
vi.mock('element-plus', async (importOriginal) => ({
  ...(await importOriginal<typeof import('element-plus')>()),
  ElMessage: message,
}));

import SiteProfileView from '@/views/site/SiteProfileView.vue';

describe('SiteProfileView', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    siteApi.fetchSiteProfile.mockResolvedValue({
      title: '旧站点名',
      subtitle: '旧简介',
      body_html: '<p>旧内容</p>',
      contact_email: '',
      updated_at: '2026-08-28 09:00:00',
    });
    siteApi.updateSiteProfile.mockResolvedValue({
      title: '林间课室',
      subtitle: '持续学习',
      body_html: '<p>已清理内容</p>',
      contact_email: 'hello@example.test',
      updated_at: '2026-08-28 10:00:00',
    });
  });

  it('loads and saves the profile through the typed site API', async () => {
    const wrapper = mount(SiteProfileView, { global: { plugins: [installElementPlus] } });
    await flushPromises();

    await wrapper.get('input[name="title"]').setValue('林间课室');
    await wrapper.get('input[name="subtitle"]').setValue('持续学习');
    await wrapper.get('textarea[name="body_html"]').setValue('<p>待清理内容</p>');
    await wrapper.get('input[name="contact_email"]').setValue('hello@example.test');
    await wrapper.get('form').trigger('submit');
    await flushPromises();

    expect(siteApi.fetchSiteProfile).toHaveBeenCalledOnce();
    expect(wrapper.findComponent({ name: 'ContentEditor' }).exists()).toBe(true);
    expect(siteApi.updateSiteProfile).toHaveBeenCalledWith({
      title: '林间课室',
      subtitle: '持续学习',
      body_html: '<p>待清理内容</p>',
      contact_email: 'hello@example.test',
    });
    expect((wrapper.get('textarea[name="body_html"]').element as HTMLTextAreaElement).value).toBe(
      '<p>已清理内容</p>',
    );
    expect(wrapper.text()).toContain('2026-08-28 10:00:00');
  });
});
