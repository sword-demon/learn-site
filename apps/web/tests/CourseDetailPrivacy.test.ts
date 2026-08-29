// @vitest-environment happy-dom

import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const learnerApi = vi.hoisted(() => ({ fetchCourseDetail: vi.fn(), startCourse: vi.fn() }))

vi.mock('@/api/learner', () => learnerApi)
vi.mock('vue-router', () => ({
  useRoute: () => ({ params: { id: '9' }, fullPath: '/courses/9', query: {} }),
  useRouter: () => ({ push: vi.fn() }),
}))

import CourseDetailView from '@/views/catalog/CourseDetailView.vue'

describe('CourseDetailView learner privacy', () => {
  beforeEach(() => {
    learnerApi.fetchCourseDetail.mockResolvedValue({
      course: {
        id: 9,
        category_id: 1,
        category_name: '工程实践',
        title: '隐私课程',
        cover_url: null,
        teacher_name: '林老师',
        summary: '只公开聚合人数',
        intro_html: '',
        price_mode: 'free',
        list_price: 0,
        sale_price: 0,
        sale_start_at: null,
        sale_end_at: null,
        viewer_authorized: false,
        viewer_entitlement_status: null,
        viewer_entitlement_source: null,
        viewer_revoked_reason: null,
        viewer_can_rejoin: false,
        learner_count: 23,
        created_at: '2026-08-28 10:00:00',
      },
      chapters: [],
    })
  })

  it('renders only the aggregate count without private learner facts', async () => {
    const wrapper = mount(CourseDetailView, {
      global: {
        stubs: {
          RouterLink: { template: '<a><slot /></a>' },
          AccessGate: true,
          ReviewTree: true,
          ShareBar: true,
        },
      },
    })
    await flushPromises()

    expect(wrapper.text()).toContain('23 位学员')
    expect(wrapper.text()).not.toMatch(/1[3-9]\d{9}/)
    expect(wrapper.text()).not.toContain('学习进度')
    expect(wrapper.text()).not.toContain('购买金额')
  })
})
