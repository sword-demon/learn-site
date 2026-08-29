// @vitest-environment happy-dom

import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';

vi.mock('@wangeditor/editor-for-vue', () => ({
  Toolbar: {
    name: 'Toolbar',
    props: ['editor', 'defaultConfig', 'mode'],
    template: '<div data-testid="wang-toolbar" />',
  },
  Editor: {
    name: 'Editor',
    props: ['modelValue', 'defaultConfig', 'mode'],
    emits: ['update:modelValue', 'onCreated', 'onFocus', 'onBlur'],
    template: '<div data-testid="wang-editor" />',
    mounted(this: { $emit: (event: string, payload?: unknown) => void }) {
      this.$emit('onCreated', {
        getHtml: () => '<p>mock</p>',
        disable: vi.fn(),
        enable: vi.fn(),
        destroy: vi.fn(),
      });
    },
  },
}));

vi.mock('@/api/catalog', () => ({
  uploadCourseCover: vi.fn(),
  uploadAsset: vi.fn(),
}));

import ContentEditor from '@/components/course/ContentEditor.vue';

describe('ContentEditor', () => {
  it('renders WangEditor toolbar and editor shell', () => {
    const wrapper = mount(ContentEditor, {
      props: {
        modelValue: '<p>课程介绍</p>',
        placeholder: '请输入课程简介',
        height: 400,
      },
    });

    expect(wrapper.find('[data-testid="wang-toolbar"]').exists()).toBe(true);
    expect(wrapper.find('[data-testid="wang-editor"]').exists()).toBe(true);
  });
});
