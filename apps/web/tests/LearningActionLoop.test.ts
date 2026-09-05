// @vitest-environment happy-dom

import { reactive } from 'vue';
import { flushPromises, mount } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

const learnerApi = vi.hoisted(() => ({
  fetchHome: vi.fn(),
  fetchCategoryCourses: vi.fn(),
  fetchCourseDetail: vi.fn(),
  fetchLesson: vi.fn(),
  fetchMediaObjectUrl: vi.fn(),
  fetchMyLearning: vi.fn(),
  startCourse: vi.fn(),
  fetchFavorites: vi.fn(),
  removeFavorite: vi.fn(),
  fetchOrders: vi.fn(),
  fetchLearnerProfile: vi.fn(),
  updateLearnerProfile: vi.fn(),
}));
const actionApi = vi.hoisted(() => ({ fetchNextAction: vi.fn() }));
const progressApi = vi.hoisted(() => ({
  reportDocumentOpen: vi.fn(),
  completeDocument: vi.fn(),
  heartbeat: vi.fn(),
}));
const notificationsApi = vi.hoisted(() => ({
  listNotifications: vi.fn(),
  markNotificationRead: vi.fn(),
  fetchUnreadCount: vi.fn(),
}));
const checkinsApi = vi.hoisted(() => ({ listCheckins: vi.fn() }));
const pushApi = vi.hoisted(() => ({ createPushConnection: vi.fn() }));
const routeApi = reactive({
  path: '/',
  query: {} as Record<string, string>,
  params: { courseId: '9', lessonId: '7' },
});
const routerApi = { push: vi.fn(), replace: vi.fn() };

vi.mock('@/api/learner', () => learnerApi);
vi.mock('@/api/learningAction', () => actionApi);
vi.mock('@/composables/useLearningProgress', () => ({ useLearningProgress: () => progressApi }));
vi.mock('@/api/notifications', () => notificationsApi);
vi.mock('@/api/checkins', () => checkinsApi);
vi.mock('@/utils/push', () => pushApi);
vi.mock('vue-router', () => ({
  useRoute: () => routeApi,
  useRouter: () => routerApi,
}));

import HomeView from '@/views/home/HomeView.vue';
import LessonView from '@/views/learn/LessonView.vue';
import StudentCenterView from '@/views/me/StudentCenterView.vue';
import { clearTokens } from '@/api/http';
import { useLoginFamilyStore } from '@/api/login';
import { useNotificationStore } from '@/stores/notifications';

const RouterLinkStub = {
  props: { to: { type: [String, Object], required: true } },
  template: '<a :href="String(to)"><slot /></a>',
};

const initialAction = {
  type: 'continue_lesson' as const,
  priority: 3,
  rule_code: 'continue_authorized_lesson',
  reason_code: 'CONTINUE_LAST_LESSON',
  title: '继续学习：第一节',
  reason: '继续上次未完成的课节',
  target: { resource_type: 'lesson' as const, resource_id: 7, path: '/learn/9/7' },
  availability: 'available' as const,
  availability_reason: null,
  generated_at: '2026-09-04T10:00:00+08:00',
};

const nextAction = {
  ...initialAction,
  title: '继续学习：第二节',
  reason: '完成第一节后继续下一节',
  target: { resource_type: 'lesson' as const, resource_id: 8, path: '/learn/9/8' },
};

const tokenPair = {
  access_token: 'access-token',
  access_expires_in: 900,
  refresh_token: 'refresh-token',
  refresh_expires_in: 604800,
};

describe('LearningActionLoop', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    routeApi.path = '/';
    routeApi.query = {};
    routeApi.params = { courseId: '9', lessonId: '7' };
    learnerApi.fetchHome.mockResolvedValue({
      categories: [],
      recent_courses: [],
      banners: [],
      recommended_maps: [],
      site_intro: {
        title: '拾阶学社',
        subtitle: '',
        body_html: '',
        contact_email: '',
        updated_at: null,
      },
    });
    learnerApi.fetchCategoryCourses.mockResolvedValue({
      category: { id: 1, name: '课程', ancestors: [] },
      list: { items: [], total: 0, page: 1, limit: 100 },
    });
    learnerApi.fetchCourseDetail.mockResolvedValue({
      course: {
        id: 9,
        title: '行动课程',
        price_mode: 'free',
        viewer_authorized: true,
        category_id: 1,
        category_name: '课程',
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
              title: '第一节',
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
      action: initialAction,
      fallback: null,
      evaluated_at: initialAction.generated_at,
      degraded_dependencies: [],
    });
    learnerApi.fetchMyLearning.mockResolvedValue({ items: [] });
    learnerApi.fetchFavorites.mockResolvedValue({ items: [], total: 0 });
    learnerApi.fetchOrders.mockResolvedValue({ items: [] });
    learnerApi.fetchLearnerProfile.mockResolvedValue({
      account_id: 1,
      phone: '13900000001',
      nickname: '学员',
      avatar_url: null,
      show_on_course: false,
      status: 'active',
      created_at: '2026-09-04 10:00:00',
    });
    learnerApi.updateLearnerProfile.mockResolvedValue({});
    checkinsApi.listCheckins.mockResolvedValue({ items: [], total: 0, page: 1, limit: 20 });
    notificationsApi.fetchUnreadCount.mockResolvedValue({ count: 0 });
    notificationsApi.markNotificationRead.mockResolvedValue({ read: true });
    pushApi.createPushConnection.mockRejectedValue(new Error('push unavailable'));
  });

  afterEach(() => {
    useNotificationStore().disconnect();
    clearTokens();
  });

  it('carries the server explanation from home through completion to the next action', async () => {
    const pinia = createPinia();
    setActivePinia(pinia);
    useLoginFamilyStore().signIn(tokenPair);
    const home = mount(HomeView, {
      global: { plugins: [pinia], stubs: { RouterLink: RouterLinkStub } },
    });
    await flushPromises();

    expect(home.text()).toContain('继续上次未完成的课节');
    expect(home.find('a[href="/learn/9/7"]').exists()).toBe(true);
    home.unmount();

    actionApi.fetchNextAction.mockResolvedValueOnce({
      state: 'ready',
      action: nextAction,
      fallback: null,
      evaluated_at: nextAction.generated_at,
      degraded_dependencies: [],
    });
    routeApi.params = { courseId: '9', lessonId: '7' };
    const lesson = mount(LessonView, {
      global: {
        plugins: [pinia],
        stubs: {
          RouterLink: RouterLinkStub,
          MarkdownRenderer: { props: ['html'], template: '<div v-html="html" />' },
          QuestionPanel: true,
        },
      },
    });
    await flushPromises();
    await lesson.get('[data-action="complete-lesson"]').trigger('click');
    await flushPromises();

    expect(actionApi.fetchNextAction).toHaveBeenCalledTimes(2);
    expect(lesson.get('[data-testid="learning-action-next"]').text()).toContain(
      '完成第一节后继续下一节',
    );
    expect(lesson.find('a[href="/learn/9/8"]').exists()).toBe(true);
    lesson.unmount();
  });

  it('shows the server resource failure when realtime notification delivery is unavailable', async () => {
    routeApi.path = '/me/messages';
    notificationsApi.listNotifications.mockResolvedValue({
      items: [
        {
          id: 22,
          kind: 'learning_reminder',
          title: '收藏课程待开始',
          body: '收藏的课程已不可用',
          resource_type: 'course',
          resource_id: 7,
          resource_path: null,
          resource_available: false,
          resource_unavailable_reason: '关联课程已下架',
          payload: { rule_code: 'favorite_not_started' },
          read: false,
          created_at: '2026-09-04T10:00:00+08:00',
        },
      ],
      total: 1,
      page: 1,
      limit: 20,
    });
    const pinia = createPinia();
    setActivePinia(pinia);
    useLoginFamilyStore().signIn(tokenPair);
    const wrapper = mount(StudentCenterView, {
      global: {
        plugins: [pinia],
        stubs: {
          RouterLink: RouterLinkStub,
          MarkdownRenderer: { props: ['html'], template: '<div v-html="html" />' },
        },
      },
    });
    await flushPromises();

    expect(wrapper.text()).toContain('收藏课程待开始');
    expect(wrapper.text()).toContain('关联课程已下架');
    expect(wrapper.find('.message-resource-link').exists()).toBe(false);
    expect(pushApi.createPushConnection).toHaveBeenCalled();
    wrapper.unmount();
  });
});
