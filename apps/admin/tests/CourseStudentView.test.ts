// @vitest-environment happy-dom

import { flushPromises, mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { installElementPlus } from '@/plugins/element-plus';

const courseStudentsApi = vi.hoisted(() => ({
  listCourseStudents: vi.fn(),
  revokeCourseStudent: vi.fn(),
  resetCourseStudentProgress: vi.fn(),
}));
const authApi = vi.hoisted(() => ({ hasPermission: vi.fn() }));

vi.mock('@/api/courseStudents', () => courseStudentsApi);
vi.mock('@/api/http', () => authApi);
vi.mock('vue-router', () => ({ useRoute: () => ({ params: { id: '12' } }) }));

import CourseStudentView from '@/views/students/CourseStudentView.vue';

const student = {
  account_id: 8,
  login: '13912345678',
  nickname: '小王',
  account_status: 'active' as const,
  source: 'free' as const,
  entitlement_status: 'active' as const,
  progress_percent: 40,
  learning_status: 'in_progress' as const,
  last_learning_at: '2026-08-28 11:00:00',
  completed_at: null,
  enrolled_at: '2026-08-28 10:00:00',
  revoked_at: null,
  revoked_reason: null,
  last_login_at: null,
};

describe('CourseStudentView', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    authApi.hasPermission.mockReturnValue(true);
    courseStudentsApi.listCourseStudents.mockResolvedValue({
      items: [student],
      total: 1,
      page: 1,
      limit: 20,
    });
  });

  it('filters by source and learning state and renders operational facts', async () => {
    const wrapper = mount(CourseStudentView, { global: { plugins: [installElementPlus] } });
    await flushPromises();

    for (const [field, label] of [
      ['source', '免费加入'],
      ['learning_status', '学习中'],
    ] as const) {
      const select = wrapper.get(`[data-field="${field}"]`);
      await select.get('.el-select__wrapper').trigger('click');
      const option = select
        .findAll('.el-select-dropdown__item')
        .find((item) => item.text() === label);
      expect(option).toBeDefined();
      await option?.trigger('click');
    }
    await wrapper.get('form.filters').trigger('submit');
    await flushPromises();

    expect(courseStudentsApi.listCourseStudents).toHaveBeenLastCalledWith(12, {
      source: 'free',
      learning_status: 'in_progress',
      page: 1,
      limit: 20,
    });
    expect(wrapper.text()).toContain('免费授权');
    expect(wrapper.text()).toContain('学习中');
    expect(wrapper.text()).toContain('2026-08-28 11:00:00');
  });

  it('hides reset and revoke commands without their respective permissions', async () => {
    authApi.hasPermission.mockImplementation((code: string) => code === 'course_student.view');
    const wrapper = mount(CourseStudentView, { global: { plugins: [installElementPlus] } });
    await flushPromises();

    expect(wrapper.text()).not.toContain('重置进度');
    expect(wrapper.text()).not.toContain('撤销授权');
  });

  it('requires and submits an explicit revoke reason', async () => {
    vi.stubGlobal('prompt', vi.fn().mockReturnValue('误加入课程'));
    courseStudentsApi.revokeCourseStudent.mockResolvedValue({ revoked: true });
    const wrapper = mount(CourseStudentView, { global: { plugins: [installElementPlus] } });
    await flushPromises();

    await wrapper.get('button[data-action="revoke"]').trigger('click');
    await flushPromises();

    expect(courseStudentsApi.revokeCourseStudent).toHaveBeenCalledWith(12, 8, '误加入课程');
    expect(courseStudentsApi.listCourseStudents).toHaveBeenCalledTimes(2);
  });

  it('does not call the API when the revoke reason is blank', async () => {
    vi.stubGlobal('prompt', vi.fn().mockReturnValue('   '));
    const wrapper = mount(CourseStudentView, { global: { plugins: [installElementPlus] } });
    await flushPromises();

    await wrapper.get('button[data-action="revoke"]').trigger('click');

    expect(courseStudentsApi.revokeCourseStudent).not.toHaveBeenCalled();
    expect(wrapper.text()).toContain('请填写撤销原因');
  });
});
