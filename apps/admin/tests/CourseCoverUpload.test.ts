// @vitest-environment happy-dom

import { flushPromises, mount } from '@vue/test-utils'
import { h } from 'vue'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const catalogApi = vi.hoisted(() => ({ uploadCourseCover: vi.fn() }))
vi.mock('@/api/catalog', () => catalogApi)

import CourseCoverUpload from '@/views/catalog/CourseCoverUpload.vue'

const UploadStub = {
  props: ['httpRequest'],
  setup(props: { httpRequest?: (request: { file: File; onSuccess: () => void; onError: () => void }) => Promise<void> }) {
    const file = new File(['cover'], 'cover.webp', { type: 'image/webp' })
    return () => h('button', {
      'data-role': 'choose-cover',
      onClick: () => props.httpRequest?.({ file, onSuccess: () => undefined, onError: () => undefined }),
    }, '选择图片')
  },
}

describe('CourseCoverUpload', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    catalogApi.uploadCourseCover.mockResolvedValue({
      key: 'covers/2026/08/aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa.webp',
      url: '/api/media/covers/2026/08/aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa.webp',
      mime_type: 'image/webp',
      size_bytes: 5,
    })
  })

  function mountField(cover = '') {
    return mount(CourseCoverUpload, {
      props: { modelValue: cover, upload: catalogApi.uploadCourseCover },
      global: {
        stubs: {
          'el-upload': UploadStub,
          'el-image': { props: ['src'], template: '<img data-role="cover-preview" :src="src">' },
          'el-button': { template: '<button><slot /></button>' },
        },
      },
    })
  }

  it('uploads an image and emits a preview URL', async () => {
    const wrapper = mountField()

    await wrapper.get('[data-role="choose-cover"]').trigger('click')
    await flushPromises()
    await wrapper.setProps({ modelValue: '/api/media/covers/2026/08/aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa.webp' })

    expect(catalogApi.uploadCourseCover).toHaveBeenCalledOnce()
    expect(wrapper.emitted('update:modelValue')).toEqual([[
      '/api/media/covers/2026/08/aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa.webp',
    ]])
    expect(wrapper.find('[data-role="cover-preview"]').attributes('src')).toContain('/api/media/covers/')
  })

  it('clears the current preview without touching upload state', async () => {
    const wrapper = mountField('/api/media/covers/existing.webp')

    await wrapper.get('[data-role="clear-cover"]').trigger('click')

    expect(wrapper.emitted('update:modelValue')).toEqual([['']])
    await wrapper.setProps({ modelValue: '' })
    expect(wrapper.find('[data-role="cover-preview"]').exists()).toBe(false)
  })
})
