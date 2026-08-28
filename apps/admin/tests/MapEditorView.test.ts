// @vitest-environment happy-dom

import { flushPromises, mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import type { AdminMapDetailDTO, CourseDTO, DepartmentDTO } from '@learn-site/contracts';
import { installElementPlus } from '@/plugins/element-plus';

const learningMapApi = vi.hoisted(() => ({
  addCourseToStage: vi.fn(),
  addStage: vi.fn(),
  createMap: vi.fn(),
  deleteMap: vi.fn(),
  deleteStage: vi.fn(),
  getMap: vi.fn(),
  listMaps: vi.fn(),
  publishMap: vi.fn(),
  removeCourseFromStage: vi.fn(),
  unpublishMap: vi.fn(),
  updateMap: vi.fn(),
  updateStage: vi.fn(),
  uploadMapCover: vi.fn(),
}));
const orgApi = vi.hoisted(() => ({ listDepartments: vi.fn() }));
const catalogApi = vi.hoisted(() => ({ listCourses: vi.fn(), uploadCourseCover: vi.fn() }));
const authApi = vi.hoisted(() => ({ hasPermission: vi.fn() }));
const routerApi = vi.hoisted(() => ({
  replace: vi.fn(),
  route: { query: { id: '7' } },
}));

vi.mock('@/api/learningMaps', () => learningMapApi);
vi.mock('@/api/org', () => orgApi);
vi.mock('@/api/catalog', () => catalogApi);
vi.mock('@/api/http', () => authApi);
vi.mock('vue-router', () => ({
  useRoute: () => routerApi.route,
  useRouter: () => ({ replace: routerApi.replace }),
}));

import MapEditorView from '@/views/maps/MapEditorView.vue';

const department: DepartmentDTO = {
  id: 3,
  parent_id: 0,
  name: '研发中心',
  path: '/3/',
  depth: 1,
  sort: 1,
  status: 'enabled',
  created_at: '2026-08-25 10:00:00',
  updated_at: '2026-08-25 10:00:00',
};

const course: CourseDTO = {
  id: 11,
  department_id: department.id,
  category_id: 5,
  title: 'TypeScript 深入实践',
  cover_url: null,
  teacher_name: '王老师',
  summary: '掌握 TypeScript 类型系统',
  intro_rich_text: '<p>课程介绍</p>',
  status: 'published',
  price_mode: 'free',
  list_price: 0,
  sale_price: 0,
  sale_start_at: null,
  sale_end_at: null,
  created_by_staff_id: 2,
  created_at: '2026-08-25 10:00:00',
  updated_at: '2026-08-25 10:00:00',
};

const otherCourse: CourseDTO = {
  ...course,
  id: 12,
  title: 'Vue 工程化实践',
  summary: '构建可维护的 Vue 应用',
};

const detail: AdminMapDetailDTO = {
  id: 7,
  department_id: department.id,
  title: '前端工程师成长路线',
  summary: '从基础到工程化',
  cover_url: 'https://example.test/old-cover.png',
  objective: '建立完整的前端工程能力',
  audience: '具有 JavaScript 基础的开发者',
  status: 'draft',
  created_at: '2026-08-25 10:00:00',
  updated_at: '2026-08-25 10:00:00',
  publish_issues: [],
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
          course_id: course.id,
          sort_order: 1,
          available: true,
          viewer_authorized: false,
          completed: false,
          course: {
            id: course.id,
            title: course.title,
            teacher_name: course.teacher_name,
            cover_url: course.cover_url,
            status: course.status,
          },
        },
      ],
    },
  ],
};

function mapList(item: AdminMapDetailDTO = detail) {
  const summary = {
    id: item.id,
    department_id: item.department_id,
    title: item.title,
    summary: item.summary,
    cover_url: item.cover_url,
    objective: item.objective,
    audience: item.audience,
    status: item.status,
    created_at: item.created_at,
    updated_at: item.updated_at,
  };
  return { items: [summary], total: 1, page: 1, limit: 50 };
}

async function mountEditor() {
  const wrapper = mount(MapEditorView, { global: { plugins: [installElementPlus] } });
  await flushPromises();
  return wrapper;
}

async function chooseOption(
  wrapper: Awaited<ReturnType<typeof mountEditor>>,
  field: string,
  label: string,
): Promise<void> {
  const select = wrapper.get(`[data-field="${field}"]`);
  await select.get('.el-select__wrapper').trigger('click');
  const option = select.findAll('.el-select-dropdown__item').find((item) => item.text() === label);
  expect(option).toBeDefined();
  await option?.trigger('click');
}

describe('MapEditorView', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    authApi.hasPermission.mockReturnValue(true);
    learningMapApi.getMap.mockResolvedValue(detail);
    learningMapApi.listMaps.mockResolvedValue(mapList());
    orgApi.listDepartments.mockResolvedValue({ items: [department] });
    catalogApi.listCourses.mockResolvedValue({ items: [course], total: 1, page: 1, limit: 100 });
  });

  it('saves complete map metadata and renders the returned values', async () => {
    const updated: AdminMapDetailDTO = {
      ...detail,
      department_id: 4,
      title: '资深前端工程师路线',
      summary: '从类型系统到架构实践',
      cover_url: 'https://example.test/new-cover.png',
      objective: '独立负责中大型前端项目',
      audience: '有两年前端经验的开发者',
    };
    orgApi.listDepartments.mockResolvedValue({
      items: [department, { ...department, id: 4, name: '产品技术部', path: '/4/' }],
    });
    learningMapApi.updateMap.mockResolvedValue(updated);
    const wrapper = await mountEditor();

    const form = wrapper.get('form[data-role="map-settings"]');
    await form.get('input[name="title"]').setValue('  资深前端工程师路线  ');
    await form.get('input[name="summary"]').setValue('  从类型系统到架构实践  ');
    const coverUpload = wrapper.findComponent({ name: 'CourseCoverUpload' });
    expect(coverUpload.exists()).toBe(true);
    await coverUpload.vm.$emit('update:modelValue', '  https://example.test/new-cover.png  ');
    await form.get('textarea[name="objective"]').setValue('  独立负责中大型前端项目  ');
    await form.get('textarea[name="audience"]').setValue('  有两年前端经验的开发者  ');
    await chooseOption(wrapper, 'department_id', '产品技术部');
    await form.trigger('submit');
    await flushPromises();

    expect(learningMapApi.updateMap).toHaveBeenCalledWith(7, {
      department_id: 4,
      title: '资深前端工程师路线',
      summary: '从类型系统到架构实践',
      cover_url: 'https://example.test/new-cover.png',
      objective: '独立负责中大型前端项目',
      audience: '有两年前端经验的开发者',
    });
    expect(wrapper.text()).toContain('资深前端工程师路线');
    expect(wrapper.text()).toContain('独立负责中大型前端项目');
  });

  it('uses the map cover uploader and preserves the existing cover preview', async () => {
    const wrapper = await mountEditor();
    const coverUpload = wrapper.findComponent({ name: 'CourseCoverUpload' });

    expect(coverUpload.exists()).toBe(true);
    expect(coverUpload.attributes('data-role')).toBe('map-cover-upload');
    expect(coverUpload.props('modelValue')).toBe(detail.cover_url);
    expect(coverUpload.props('upload')).toBe(learningMapApi.uploadMapCover);
    expect(wrapper.find('.settings-layout .settings-grid').exists()).toBe(true);
    expect(wrapper.get('.settings-layout .cover-workbench').text()).toContain('地图封面');
    expect(wrapper.get('.settings-footer').text()).toContain('保存设置');
  });

  it('renders the publish status filter as an explicit all-state select', async () => {
    const wrapper = await mountEditor();
    const statusSelect = wrapper
      .findAllComponents({ name: 'ElSelect' })
      .find((component) => component.attributes('data-field') === 'status');

    expect(statusSelect).toBeDefined();
    expect(statusSelect?.props('modelValue')).toBe('all');
    expect(statusSelect?.props('teleported')).toBe(true);
    expect(statusSelect?.props('placement')).toBe('bottom-start');
    expect(statusSelect?.props('clearable')).toBe(false);
    expect(statusSelect?.props('placeholder')).toBe('全部');
  });

  it('lists concrete publish blockers and prevents an invalid publish attempt', async () => {
    learningMapApi.getMap.mockResolvedValue({
      ...detail,
      publish_issues: [
        { code: 'STAGE_HAS_NO_COURSES', stage_id: 13, course_id: null },
        { code: 'MAP_HAS_UNPUBLISHED_COURSE', stage_id: 13, course_id: 11 },
      ],
    });
    const wrapper = await mountEditor();

    const issues = wrapper.get('[data-role="publish-issues"]');
    expect(issues.text()).toContain('阶段「类型基础」还没有课程');
    expect(issues.text()).toContain('课程「TypeScript 深入实践」尚未发布');
    expect(wrapper.get<HTMLButtonElement>('[data-action="publish"]').element.disabled).toBe(true);
    expect(learningMapApi.publishMap).not.toHaveBeenCalled();
  });

  it('keeps every write control hidden for a map-view-only employee', async () => {
    authApi.hasPermission.mockImplementation((code: string) => code === 'map.view');
    const wrapper = await mountEditor();

    expect(wrapper.text()).toContain(detail.title);
    expect(wrapper.text()).toContain(course.title);
    expect(wrapper.find('form[data-role="new-map"]').exists()).toBe(false);
    expect(wrapper.find('form[data-role="map-settings"]').exists()).toBe(false);
    expect(wrapper.find('form[data-role="new-stage"]').exists()).toBe(false);
    expect(wrapper.find('[data-action="publish"]').exists()).toBe(false);
    expect(wrapper.find('[data-action="delete-stage"]').exists()).toBe(false);
    expect(wrapper.find('[data-action="remove-course"]').exists()).toBe(false);
    expect(wrapper.find('[data-action="add-course"]').exists()).toBe(false);
  });

  it('updates a stage summary and excludes courses already used by the map', async () => {
    catalogApi.listCourses.mockResolvedValue({
      items: [course, otherCourse],
      total: 2,
      page: 1,
      limit: 100,
    });
    learningMapApi.updateStage.mockResolvedValue(detail.stages[0]);
    const wrapper = await mountEditor();
    const stage = wrapper.get('[data-stage-id="13"]');

    await stage.get('textarea[name="stage_summary"]').setValue('  核心概念与类型推导  ');
    await flushPromises();

    expect(learningMapApi.updateStage).toHaveBeenCalledWith(7, 13, {
      summary: '核心概念与类型推导',
    });

    await stage.get('[data-action="add-course"]').trigger('click');
    const courseSelect = wrapper.get('[data-field="course_id"]');
    await courseSelect.get('.el-select__wrapper').trigger('click');
    const options = courseSelect.findAll('.el-select-dropdown__item').map((option) => option.text());

    expect(options).not.toContain(course.title);
    expect(options).toContain(otherCourse.title);
  });
});
