// @vitest-environment happy-dom

import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import type { PublicCourseDetailDTO } from '@learn-site/contracts'

const learnerApi = vi.hoisted(() => ({
  fetchCourseDetail: vi.fn(),
  startCourse: vi.fn(),
}))
const authApi = vi.hoisted(() => ({ hasTokens: vi.fn() }))
const routerApi = vi.hoisted(() => ({
  push: vi.fn(),
  route: { params: { id: '9' }, fullPath: '/courses/9', query: {} },
}))

vi.mock('@/api/learner', () => learnerApi)
vi.mock('@/api/http', () => authApi)
vi.mock('vue-router', () => ({
  useRoute: () => routerApi.route,
  useRouter: () => ({ push: routerApi.push }),
}))

import CourseDetailView from '@/views/catalog/CourseDetailView.vue'

const detail: PublicCourseDetailDTO = {
  course: {
    id: 9,
    category_id: 1,
    category_name: '工程实践',
    title: 'Vue 组件设计',
    cover_url: null,
    teacher_name: '林老师',
    summary: '从状态到组件边界',
    intro_html: '<p>课程介绍</p><pre><code>func main() {}</code></pre>',
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
    learner_count: 12,
    created_at: '2026-08-28 10:00:00',
  },
  chapters: [{
    id: 3,
    course_id: 9,
    title: '基础',
    sort: 0,
    lessons: [{
      id: 7,
      title: '状态建模',
      sort: 0,
      content_type: 'markdown',
      duration_seconds: 0,
      is_preview: false,
      locked: true,
    }],
  }],
}

describe('CourseDetailView', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    authApi.hasTokens.mockReturnValue(true)
    learnerApi.fetchCourseDetail.mockResolvedValue(detail)
    learnerApi.startCourse.mockResolvedValue({
      course_id: 9,
      entitled: true,
      source: 'free',
      price_mode: 'free',
      first_lesson: { id: 7, title: '状态建模', content_type: 'markdown', is_preview: false },
    })
  })

  it('renders course facts, chapter summaries and the free start action', async () => {
    const wrapper = mount(CourseDetailView, {
      global: {
        stubs: {
          RouterLink: { template: '<a><slot /></a>' },
          AccessGate: { template: '<div><slot /></div>' },
          ReviewTree: true,
          ShareBar: true,
        },
      },
    })
    await flushPromises()

    expect(wrapper.text()).toContain('Vue 组件设计')
    expect(wrapper.text()).toContain('课程介绍')
    expect(wrapper.text()).toContain('func main() {}')
    expect(wrapper.text()).toContain('状态建模')
    expect(wrapper.text()).toContain('开始学习')
    expect(learnerApi.fetchCourseDetail).toHaveBeenCalledWith(9)
  })

  it('redirects a visitor to learner login before starting a free course', async () => {
    authApi.hasTokens.mockReturnValue(false)
    const wrapper = mount(CourseDetailView, {
      global: {
        stubs: {
          RouterLink: { template: '<a><slot /></a>' },
          AccessGate: { template: '<div><slot /></div>' },
          ReviewTree: true,
          ShareBar: true,
        },
      },
    })
    await flushPromises()

    await wrapper.get('.buy-panel .btn-primary').trigger('click')

    expect(routerApi.push).toHaveBeenCalledWith('/login?redirect=%2Fcourses%2F9')
    expect(learnerApi.startCourse).not.toHaveBeenCalled()
  })

  it('does not render stale course content after the public entry disappears', async () => {
    learnerApi.fetchCourseDetail.mockRejectedValueOnce({
      response: { data: { error: { code: 'NOT_FOUND', message: 'COURSE_NOT_FOUND' } } },
    })

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

    expect(wrapper.text()).toContain('课程暂时读不到，请稍后再试。')
    expect(wrapper.text()).not.toContain('Vue 组件设计')
    expect(learnerApi.fetchCourseDetail).toHaveBeenCalledWith(9)
  })
})
