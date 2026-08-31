// @vitest-environment happy-dom

import { flushPromises, mount } from '@vue/test-utils';
import { h } from 'vue';
import { ElMessageBox } from 'element-plus';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { installElementPlus } from '@/plugins/element-plus';

const bannersApi = vi.hoisted(() => ({
  listBanners: vi.fn(),
  createBanner: vi.fn(),
  updateBanner: vi.fn(),
  deleteBanner: vi.fn(),
}));

const coversApi = vi.hoisted(() => ({
  uploadBannerImage: vi.fn(),
}));

vi.mock('@/api/banners', () => bannersApi);
vi.mock('@/api/covers', () => coversApi);

import BannerListView from '@/views/banners/BannerListView.vue';

const row = {
  id: 1,
  image_url: '/api/media/banners/2026/08/aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa.webp',
  image_key: 'banners/2026/08/aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa.webp',
  link_url: '/courses/1',
  sort_order: 10,
  is_enabled: true,
  created_at: '2026-08-30T10:00:00+08:00',
  updated_at: '2026-08-30T10:00:00+08:00',
};

const bannerUpload = {
  key: 'banners/2026/08/bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb.webp',
  url: '/api/media/banners/2026/08/bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb.webp',
  mime_type: 'image/webp' as const,
  size_bytes: 128,
};

const UploadStub = {
  props: ['httpRequest'],
  setup(props: {
    httpRequest?: (request: {
      file: File;
      onSuccess: (result: unknown) => void;
      onError: (error: unknown) => void;
    }) => Promise<void>;
  }) {
    const file = new File(['banner'], 'banner.webp', { type: 'image/webp' });
    return () =>
      h(
        'button',
        {
          'data-role': 'choose-banner',
          onClick: () =>
            props.httpRequest?.({
              file,
              onSuccess: () => undefined,
              onError: () => undefined,
            }),
        },
        '选择图片',
      );
  },
};

describe('BannerListView', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    bannersApi.listBanners.mockResolvedValue({ items: [row], total: 1, page: 1, limit: 20 });
    bannersApi.createBanner.mockResolvedValue(row);
    bannersApi.updateBanner.mockResolvedValue({ ...row, is_enabled: false });
    bannersApi.deleteBanner.mockResolvedValue(undefined);
    coversApi.uploadBannerImage.mockResolvedValue(bannerUpload);
  });

  it('renders banner rows and opens the create dialog', async () => {
    const wrapper = mount(BannerListView, { global: { plugins: [installElementPlus] } });
    await flushPromises();

    expect(wrapper.text()).toContain('轮播图管理');
    expect(wrapper.text()).toContain('/courses/1');
    expect(wrapper.text()).toContain('启用');

    await wrapper.get('[data-action="create"]').trigger('click');
    expect(wrapper.text()).toContain('新增轮播图');
    expect(wrapper.find('[data-dialog="banner"]').exists()).toBe(true);
  });

  it('opens edit, toggles enabled state, and confirms deletion', async () => {
    vi.spyOn(ElMessageBox, 'confirm').mockResolvedValue(undefined as never);
    const wrapper = mount(BannerListView, { global: { plugins: [installElementPlus] } });
    await flushPromises();

    await wrapper.get('[data-action="edit"]').trigger('click');
    expect(wrapper.text()).toContain('编辑轮播图');

    await wrapper.get('[data-action="toggle"]').trigger('click');
    await flushPromises();
    expect(bannersApi.updateBanner).toHaveBeenCalledWith(1, {
      expected_updated_at: row.updated_at,
      is_enabled: false,
    });

    await wrapper.get('[data-action="delete"]').trigger('click');
    await flushPromises();
    expect(ElMessageBox.confirm).toHaveBeenCalledOnce();
    expect(bannersApi.deleteBanner).toHaveBeenCalledWith(1);
  });

  it('uploads a banner image and creates a record with derived image_key', async () => {
    const wrapper = mount(BannerListView, {
      global: {
        plugins: [installElementPlus],
        stubs: {
          'el-upload': UploadStub,
          'el-image': { props: ['src'], template: '<img data-role="banner-preview" :src="src">' },
        },
      },
    });
    await flushPromises();

    await wrapper.get('[data-action="create"]').trigger('click');
    await wrapper.get('[data-role="choose-banner"]').trigger('click');
    await flushPromises();

    expect(coversApi.uploadBannerImage).toHaveBeenCalledOnce();

    await wrapper.get('[data-action="save"]').trigger('click');
    await flushPromises();

    expect(bannersApi.createBanner).toHaveBeenCalledWith({
      image_url: bannerUpload.url,
      image_key: bannerUpload.key,
      link_url: null,
      sort_order: 0,
      is_enabled: true,
    });
  });
});
