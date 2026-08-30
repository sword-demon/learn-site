// @vitest-environment happy-dom

import { flushPromises, mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';

import SharePosterDialog from '@/components/SharePosterDialog.vue';

const poster = {
  poster_id: 4,
  token: 'poster-token',
  share_url: '/courses/9',
  render_status: 'ready' as const,
  snapshot: {
    cover_url: null,
    title: 'Vue 组件设计',
    teacher_name: '林老师',
    price_label: '免费',
  },
};

describe('SharePosterDialog', () => {
  it('constrains the open dialog through Element Plus responsive layout props', async () => {
    const wrapper = mount(SharePosterDialog, {
      attachTo: document.body,
      props: { modelValue: true, poster },
    });
    await flushPromises();

    const dialog = wrapper.getComponent({ name: 'ElDialog' });
    expect(dialog.props('width')).toBe('min(440px, calc(100vw - 32px))');
    expect(dialog.props('alignCenter')).toBe(true);
    expect(dialog.props('appendToBody')).toBe(true);
    wrapper.unmount();
  });
});
