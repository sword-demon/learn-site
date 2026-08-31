// @vitest-environment happy-dom

import { mount } from '@vue/test-utils';
import { h, nextTick, defineComponent } from 'vue';
import { ElPagination } from 'element-plus';
import { describe, expect, it } from 'vitest';
import { installElementPlus } from '@/plugins/element-plus';

const PaginationHarness = defineComponent({
  setup() {
    return () =>
      h(ElPagination, {
        layout: 'total, prev, pager, next, sizes',
        total: 1,
        pageSize: 20,
        currentPage: 1,
        pageSizes: [10, 20, 50],
        onCurrentChange: () => undefined,
        onSizeChange: () => undefined,
      });
  },
});

describe('Element Plus locale', () => {
  it('renders pagination labels in Chinese', async () => {
    const wrapper = mount(PaginationHarness, {
      global: { plugins: [installElementPlus] },
    });

    await nextTick();
    expect(wrapper.text()).toContain('共 1 条');
    expect(wrapper.text()).toContain('20条/页');
    expect(wrapper.text()).not.toContain('Total');
    expect(wrapper.text()).not.toContain('/page');
  });
});
