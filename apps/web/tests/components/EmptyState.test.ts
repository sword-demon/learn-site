// @vitest-environment happy-dom
import { describe, expect, it } from 'vitest';
import { mount } from '@vue/test-utils';
import { createRouter, createMemoryHistory } from 'vue-router';
import EmptyState from '@/components/EmptyState.vue';

const router = createRouter({
  history: createMemoryHistory(),
  routes: [{ path: '/maps', component: { template: '<div />' } }],
});

describe('EmptyState', () => {
  it('renders illustration placeholder, headline, sub, CTA', async () => {
    const w = mount(EmptyState, {
      props: { headline: '暂无数据', sub: '探索更多', ctaText: '去看看', ctaHref: '/maps' },
      global: { plugins: [router] },
    });
    expect(w.text()).toContain('暂无数据');
    expect(w.text()).toContain('探索更多');
    expect(w.text()).toContain('去看看');
  });
});
