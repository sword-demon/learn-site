// @vitest-environment happy-dom

import { flushPromises, mount } from '@vue/test-utils';
import { createMemoryHistory, createRouter } from 'vue-router';
import { describe, expect, it, vi } from 'vitest';
import type { BannerPublicDTO } from '@learn-site/contracts';
import HomeBannerCarousel from '@/components/HomeBannerCarousel.vue';

const banners: BannerPublicDTO[] = [
  {
    id: 2,
    image_url: '/api/media/banners/2026/08/bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb.webp',
    link_url: null,
    sort_order: 20,
  },
  {
    id: 1,
    image_url: '/api/media/banners/2026/08/aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa.webp',
    link_url: '/courses/1',
    sort_order: 10,
  },
];

async function routerForTest() {
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/', component: { template: '<main />' } },
      { path: '/courses/1', component: { template: '<main />' } },
    ],
  });
  await router.push('/');
  await router.isReady();
  return router;
}

const stubs = {
  ElCarousel: { template: '<div class="carousel"><slot /></div>' },
  ElCarouselItem: { template: '<div class="carousel-item"><slot /></div>' },
  ElImage: { props: ['src', 'alt'], template: '<img :src="src" :alt="alt" />' },
};

describe('HomeBannerCarousel', () => {
  it('renders banners in sort order', async () => {
    const router = await routerForTest();
    const wrapper = mount(HomeBannerCarousel, {
      props: { banners },
      global: { plugins: [router], stubs },
    });
    await flushPromises();

    expect(
      wrapper.findAll('[data-banner-id]').map((node) => node.attributes('data-banner-id')),
    ).toEqual(['1', '2']);
    expect(wrapper.find('.banner-image').attributes('src')).toBe(
      '/api/media/banners/2026/08/aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa.webp',
    );
  });

  it('shows site intro headline on the first slide only', async () => {
    const router = await routerForTest();
    const wrapper = mount(HomeBannerCarousel, {
      props: { banners, headline: '静水流深，拾阶而上' },
      global: { plugins: [router], stubs },
    });

    expect(wrapper.find('.banner-copy h1').text()).toBe('静水流深，拾阶而上');
    expect(wrapper.findAll('.banner-copy')).toHaveLength(1);
  });

  it('navigates internally and opens external links safely', async () => {
    const router = await routerForTest();
    const external = {
      ...banners[0]!,
      id: 3,
      link_url: 'https://example.com/promo',
      sort_order: 0,
    };
    const internal = { ...banners[1]!, id: 4, link_url: '/courses/1', sort_order: 1 };
    const wrapper = mount(HomeBannerCarousel, {
      props: { banners: [external, internal] },
      global: { plugins: [router], stubs },
    });
    const open = vi.spyOn(window, 'open').mockImplementation(() => null);

    await wrapper.get('[data-banner-id="4"] button').trigger('click');
    await flushPromises();
    expect(router.currentRoute.value.fullPath).toBe('/courses/1');

    await wrapper.get('[data-banner-id="3"] button').trigger('click');
    expect(open).toHaveBeenCalledWith('https://example.com/promo', '_blank', 'noopener,noreferrer');
    open.mockRestore();
  });

  it('does not render a carousel for an empty banner list or add navigation to no-link banners', async () => {
    const router = await routerForTest();
    const empty = mount(HomeBannerCarousel, {
      props: { banners: [] },
      global: { plugins: [router], stubs },
    });
    expect(empty.find('[data-testid="home-banner-carousel"]').exists()).toBe(false);

    const wrapper = mount(HomeBannerCarousel, {
      props: { banners: [banners[0]!] },
      global: { plugins: [router], stubs },
    });
    expect(wrapper.find('[data-banner-id="2"] button').exists()).toBe(false);
  });
});
