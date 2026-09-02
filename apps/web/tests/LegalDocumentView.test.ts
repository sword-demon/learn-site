// @vitest-environment happy-dom

import { mount } from '@vue/test-utils';
import { createRouter, createWebHistory } from 'vue-router';
import { describe, expect, it } from 'vitest';
import LegalDocumentView from '@/views/legal/LegalDocumentView.vue';

const RouterLinkStub = {
  props: { to: { type: [String, Object], required: true } },
  template: '<a :href="String(to)"><slot /></a>',
};

async function mountLegal(routePath: string) {
  const router = createRouter({
    history: createWebHistory(),
    routes: [
      {
        path: '/terms',
        name: 'terms',
        meta: { legalKey: 'terms' },
        component: LegalDocumentView,
      },
      {
        path: '/refund',
        name: 'refund',
        meta: { legalKey: 'refund' },
        component: LegalDocumentView,
      },
      { path: '/', component: { template: '<main>首页</main>' } },
    ],
  });
  await router.push(routePath);
  await router.isReady();

  return mount(LegalDocumentView, {
    global: {
      plugins: [router],
      stubs: { RouterLink: RouterLinkStub },
    },
  });
}

describe('LegalDocumentView', () => {
  it('renders terms page with key sections', async () => {
    const wrapper = await mountLegal('/terms');

    expect(wrapper.get('[data-view="legal-document"]').attributes('data-view')).toBe(
      'legal-document',
    );
    expect(wrapper.text()).toContain('用户协议');
    expect(wrapper.text()).toContain('协议范围与接受');
    expect(wrapper.text()).toContain('付费课程与订单');
    expect(wrapper.get('a[href="/refund"]').text()).toContain('退款说明');
  });

  it('renders refund page with no-refund policy', async () => {
    const wrapper = await mountLegal('/refund');

    expect(wrapper.get('[data-view="legal-document"]').attributes('data-view')).toBe(
      'legal-document',
    );
    expect(wrapper.text()).toContain('退款说明');
    expect(wrapper.text()).toContain('购买成功后不支持退款');
    expect(wrapper.text()).toContain('优惠券');
    expect(wrapper.get('a[href="/terms"]').text()).toContain('用户协议');
  });
});
