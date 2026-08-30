// @vitest-environment happy-dom
import { describe, expect, it } from 'vitest';
import { mount } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import { createRouter, createMemoryHistory } from 'vue-router';
import PageHeader from '@/components/PageHeader.vue';

const router = createRouter({
  history: createMemoryHistory(),
  routes: [
    { path: '/', component: { template: '<div />' } },
    { path: '/maps', component: { template: '<div />' } },
    { path: '/me', component: { template: '<div />' } },
    { path: '/login', component: { template: '<div />' } },
  ],
});

describe('PageHeader', () => {
  it('renders logo, search placeholder, anonymous user entry', () => {
    setActivePinia(createPinia());
    const w = mount(PageHeader, { global: { plugins: [router] } });
    expect(w.text()).toContain('拾阶学社');
    expect(w.text()).toContain('搜索');
    expect(w.text()).toContain('登录');
  });
});
