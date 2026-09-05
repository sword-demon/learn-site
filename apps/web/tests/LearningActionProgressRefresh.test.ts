// @vitest-environment happy-dom

import { flushPromises, mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';

const learnerApi = vi.hoisted(() => ({
  fetchCourseDetail: vi.fn(),
  fetchLesson: vi.fn(),
}));
const progressApi = vi.hoisted(() => ({
  reportDocumentOpen: vi.fn(),
  completeDocument: vi.fn(),
  heartbeat: vi.fn(),
}));
const actionApi = vi.hoisted(() => ({ fetchNextAction: vi.fn() }));
const routeApi = vi.hoisted(() => ({ params: { courseId: '9', lessonId: '7' } }));
const routerApi = vi.hoisted(() => ({ push: vi.fn() }));

vi.mock('@/api/learner', () => learnerApi);
vi.mock('@/composables/useLearningProgress', () => ({ useLearningProgress: () => progressApi }));
vi.mock('@/api/learningAction', () => actionApi);
vi.mock('vue-router', () => ({
  useRoute: () => routeApi,
  useRouter: () => routerApi,
}));

import LessonView from '@/views/learn/LessonView.vue';

describe('LessonView learning action refresh', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    learnerApi.fetchCourseDetail.mockResolvedValue({
      course: {
        id: 9,
        title: '行动课程',
        price_mode: 'free',
        viewer_authorized: true,
        category_id: 1,
        category_name: '分类',
        cover_url: null,
        teacher_name: '老师',
        summary: '',
        intro_html: '',
        list_price: 0,
        sale_price: 0,
        sale_start_at: null,
        sale_end_at: null,
        viewer_entitlement_status: 'active',
        viewer_entitlement_source: 'free',
        viewer_revoked_reason: null,
        viewer_can_rejoin: false,
        learner_count: 1,
        created_at: '2026-09-04 10:00:00',
      },
      chapters: [
        {
          id: 3,
          course_id: 9,
          title: '第一章',
          sort: 0,
          lessons: [
            {
              id: 7,
              title: '当前课节',
              sort: 0,
              content_type: 'markdown',
              duration_seconds: 0,
              is_preview: false,
              locked: false,
            },
          ],
        },
      ],
    });
    learnerApi.fetchLesson.mockResolvedValue({ kind: 'markdown', html: '<p>正文</p>' });
    progressApi.reportDocumentOpen.mockResolvedValue({ completed: false });
    progressApi.completeDocument.mockResolvedValue({ completed: true });
    actionApi.fetchNextAction.mockResolvedValue({
      state: 'ready',
      action: {
        type: 'browse_courses',
        priority: 7,
        rule_code: 'fallback_browse',
        reason_code: 'NO_ACTIONABLE_CANDIDATE',
        title: '浏览课程',
        reason: '暂时没有待继续的学习任务',
        target: { resource_type: 'course_list', resource_id: null, path: '/' },
        availability: 'available',
        availability_reason: null,
        generated_at: '2026-09-04T10:00:00+08:00',
      },
      fallback: null,
      evaluated_at: '2026-09-04T10:00:00+08:00',
      degraded_dependencies: [],
    });
  });

  it('reads the authoritative next action after completion succeeds', async () => {
    const wrapper = mount(LessonView, {
      global: {
        stubs: {
          RouterLink: { template: '<a><slot /></a>' },
          MarkdownRenderer: { props: ['html'], template: '<div v-html="html" />' },
          QuestionPanel: true,
        },
      },
    });
    await flushPromises();

    await wrapper.get('[data-action="complete-lesson"]').trigger('click');
    await flushPromises();

    expect(actionApi.fetchNextAction).toHaveBeenCalledOnce();
    expect(wrapper.get('[data-testid="learning-action-next"]').text()).toContain('浏览课程');
  });
});
