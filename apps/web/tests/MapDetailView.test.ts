// @vitest-environment happy-dom

import { flushPromises, mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import type { LearnerMapDetailDTO } from '@learn-site/contracts';

const learnerApi = vi.hoisted(() => ({
  fetchLearningMap: vi.fn(),
  startLearningMap: vi.fn(),
}));
const httpApi = vi.hoisted(() => ({
  hasTokens: vi.fn(),
}));
const routerApi = vi.hoisted(() => ({
  push: vi.fn(),
  route: { params: { id: '7' }, fullPath: '/maps/7' },
}));

vi.mock('@/api/learner', () => learnerApi);
vi.mock('@/api/http', () => httpApi);
vi.mock('vue-router', () => ({
  useRoute: () => routerApi.route,
  useRouter: () => ({ push: routerApi.push }),
}));

import MapDetailView from '@/views/maps/MapDetailView.vue';

const RouterLinkStub = {
  props: { to: { type: [String, Object], required: true } },
  template: '<a :href="String(to)"><slot /></a>',
};

const detail: LearnerMapDetailDTO = {
  id: 7,
  department_id: 3,
  title: '前端工程师成长路线',
  summary: '从基础到工程化',
  cover_url: 'https://example.test/map-cover.png',
  objective: '建立完整的前端工程能力',
  audience: '具有 JavaScript 基础的开发者',
  status: 'published',
  created_at: '2026-08-25 10:00:00',
  updated_at: '2026-08-25 10:00:00',
  enrollment: {
    enrolled_at: '2026-08-25 11:00:00',
    completed_courses: 1,
    total_courses: 2,
    progress_percent: 50,
    completed_at: null,
  },
  next_step: {
    map_stage_course_id: 18,
    stage_id: 14,
    course_id: 12,
  },
  stages: [
    {
      id: 13,
      map_id: 7,
      title: '类型基础',
      summary: '先掌握核心类型',
      sort_order: 1,
      courses: [
        {
          map_stage_course_id: 17,
          course_id: 11,
          sort_order: 1,
          available: true,
          viewer_authorized: true,
          completed: true,
          course: {
            id: 11,
            title: 'TypeScript 深入实践',
            teacher_name: '王老师',
            cover_url: null,
            status: 'published',
          },
        },
      ],
    },
    {
      id: 14,
      map_id: 7,
      title: '工程实践',
      summary: '构建可维护应用',
      sort_order: 2,
      courses: [
        {
          map_stage_course_id: 18,
          course_id: 12,
          sort_order: 1,
          available: true,
          viewer_authorized: false,
          completed: false,
          course: {
            id: 12,
            title: 'Vue 工程化实践',
            teacher_name: '李老师',
            cover_url: null,
            status: 'published',
          },
        },
        {
          map_stage_course_id: 19,
          course_id: 13,
          sort_order: 2,
          available: false,
          viewer_authorized: true,
          completed: false,
          course: {
            id: 13,
            title: '已下架课程',
            teacher_name: '赵老师',
            cover_url: null,
            status: 'unpublished',
          },
        },
      ],
    },
  ],
};

describe('MapDetailView', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    routerApi.route.params.id = '7';
    routerApi.route.fullPath = '/maps/7';
    httpApi.hasTokens.mockReturnValue(true);
    learnerApi.fetchLearningMap.mockResolvedValue(detail);
    learnerApi.startLearningMap.mockResolvedValue(detail);
  });

  it('renders map metadata and step access/completion states', async () => {
    const wrapper = mount(MapDetailView, { global: { stubs: { RouterLink: RouterLinkStub } } });
    await flushPromises();

    expect(learnerApi.fetchLearningMap).toHaveBeenCalledTimes(1);
    expect(wrapper.text()).toContain('建立完整的前端工程能力');
    expect(wrapper.text()).toContain('具有 JavaScript 基础的开发者');
    expect(wrapper.text()).toContain('已完成');
    expect(wrapper.text()).toContain('未获得访问权');
    expect(wrapper.text()).toContain('已下架');
    expect(wrapper.get('[data-action="next-step"]').attributes('href')).toBe('/courses/12');
    expect(wrapper.text()).toContain('TypeScript 深入实践');
  });

  it('redirects a visitor to learner login before starting a map', async () => {
    httpApi.hasTokens.mockReturnValue(false);
    learnerApi.fetchLearningMap.mockResolvedValueOnce({
      ...detail,
      enrollment: null,
      next_step: null,
    });
    const wrapper = mount(MapDetailView, { global: { stubs: { RouterLink: RouterLinkStub } } });
    await flushPromises();

    await wrapper.get('button[data-action="start-map"]').trigger('click');

    expect(routerApi.push).toHaveBeenCalledWith('/login?redirect=%2Fmaps%2F7');
    expect(learnerApi.startLearningMap).not.toHaveBeenCalled();
  });

  it('starts an authenticated map and renders the returned enrollment', async () => {
    const started = {
      ...detail,
      enrollment: { ...detail.enrollment!, completed_courses: 0, progress_percent: 0 },
    };
    learnerApi.fetchLearningMap.mockResolvedValueOnce({
      ...detail,
      enrollment: null,
      next_step: null,
    });
    learnerApi.startLearningMap.mockResolvedValueOnce(started);
    const wrapper = mount(MapDetailView, { global: { stubs: { RouterLink: RouterLinkStub } } });
    await flushPromises();

    await wrapper.get('button[data-action="start-map"]').trigger('click');
    await flushPromises();

    expect(learnerApi.startLearningMap).toHaveBeenCalledWith(7);
    expect(wrapper.text()).toContain('0/2 门 · 0%');
  });
});
