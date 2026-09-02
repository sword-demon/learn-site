// @vitest-environment happy-dom

import { flushPromises, mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import { installElementPlus } from '@/plugins/element-plus';
import AdminListPager from '@/components/AdminListPager.vue';

describe('AdminListPager', () => {
  it('renders numbered pagination with total and sizes', async () => {
    const wrapper = mount(AdminListPager, {
      props: {
        page: 1,
        pageSize: 20,
        total: 45,
        'onUpdate:page': (value: number) => wrapper.setProps({ page: value }),
        'onUpdate:pageSize': (value: number) => wrapper.setProps({ pageSize: value }),
      },
      global: { plugins: [installElementPlus] },
    });
    await flushPromises();

    const pager = wrapper.find('.el-pagination');
    expect(pager.exists()).toBe(true);
    expect(pager.find('.el-pager').exists()).toBe(true);
    expect(pager.text()).toContain('共 45 条');
    expect(pager.text()).toContain('1');
    expect(pager.text()).toContain('3');
  });

  it('hides when total is zero by default', async () => {
    const wrapper = mount(AdminListPager, {
      props: {
        page: 1,
        pageSize: 20,
        total: 0,
        'onUpdate:page': (value: number) => wrapper.setProps({ page: value }),
        'onUpdate:pageSize': (value: number) => wrapper.setProps({ pageSize: value }),
      },
      global: { plugins: [installElementPlus] },
    });
    await flushPromises();

    expect(wrapper.find('.el-pagination').exists()).toBe(false);
  });
});
