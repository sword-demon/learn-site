// @vitest-environment happy-dom

import { flushPromises, mount } from '@vue/test-utils';
import { ElMessage, ElMessageBox } from 'element-plus';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { installElementPlus } from '@/plugins/element-plus';
import type { ContentTodoDetail } from '@contracts/contentTodo';

const api = vi.hoisted(() => ({
  fetchContentTodo: vi.fn(),
  triageContentTodo: vi.fn(),
  respondContentTodo: vi.fn(),
  generateContentTodoCandidate: vi.fn(),
  editContentTodoCandidate: vi.fn(),
  approveContentTodoCandidate: vi.fn(),
  rejectContentTodoCandidate: vi.fn(),
  closeContentTodo: vi.fn(),
}));
const permission = vi.hoisted(() => ({ allowed: true }));
const router = vi.hoisted(() => ({ push: vi.fn() }));

vi.mock('@/api/contentTodo', () => api);
vi.mock('@/api/http', () => ({ hasPermission: () => permission.allowed }));
vi.mock('vue-router', () => ({ useRouter: () => router }));

import ContentTodoDrawer from '@/views/ops-inbox/ContentTodoDrawer.vue';

const detail: ContentTodoDetail = {
  id: 21,
  source_type: 'question_pending',
  source_key: '77',
  source_course_id: 12,
  workflow_status: 'awaiting_approval',
  label: 'missing_example',
  target: { course_id: 12, chapter_id: 4, lesson_id: 8 },
  first_response_at: null,
  first_response_kind: null,
  first_response_confirmed: false,
  result_type: null,
  close_reason_code: null,
  close_reason_note: null,
  resolved_at: null,
  resolved_by_staff_id: null,
  version: 2,
  title: '为什么没有例子？',
  course_title: '内容待办课程',
  age_seconds: 3600,
  age_label: '1 小时',
  created_at: '2026-09-10T15:00:00+08:00',
  updated_at: '2026-09-10T16:00:00+08:00',
  source: {
    source_type: 'question_pending',
    source_key: '77',
    course_id: 12,
    course_title: '内容待办课程',
    chapter_id: 4,
    lesson_id: 8,
    learner_id: 101,
    title: '为什么没有例子？',
    body: '请补充一个例子。',
    created_at: '2026-09-10T15:00:00+08:00',
  },
  candidates: [
    {
      id: 3,
      version: 1,
      target_course_id: 12,
      target_chapter_id: 4,
      target_lesson_id: 8,
      target_kind: 'lesson_markdown',
      body: '补充一个例子。',
      body_format: 'markdown',
      base_content_fingerprint: 'a'.repeat(64),
      generator: 'local',
      status: 'draft',
      generated_by_staff_id: 9,
      generated_at: '2026-09-10T16:00:00+08:00',
      approved_by_staff_id: null,
      approved_at: null,
      rejection_reason: null,
    },
  ],
  audit: [],
};

describe('ContentTodoDrawer', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    permission.allowed = true;
    api.fetchContentTodo.mockResolvedValue(detail);
    api.triageContentTodo.mockResolvedValue(detail);
    api.approveContentTodoCandidate.mockResolvedValue({
      ...detail,
      workflow_status: 'resolved',
      result_type: 'content_updated',
    });
  });

  it('loads a todo and saves triage', async () => {
    const wrapper = mount(ContentTodoDrawer, {
      props: { visible: true, todoId: 21 },
      global: { plugins: [installElementPlus] },
    });
    await flushPromises();

    expect(wrapper.text()).toContain('公开问答');
    expect(wrapper.text()).toContain('请补充一个例子。');
    const save = wrapper.findAll('button').find((button) => button.text() === '保存分诊');
    expect(save).toBeDefined();
    await save!.trigger('click');
    await flushPromises();
    expect(api.triageContentTodo).toHaveBeenCalledWith(21, expect.objectContaining({
      label: 'missing_example',
      expected_version: 2,
      target_course_id: 12,
    }));
    wrapper.unmount();
  });

  it('confirms approval before writing content', async () => {
    vi.spyOn(ElMessageBox, 'confirm').mockResolvedValue('confirm' as never);
    const wrapper = mount(ContentTodoDrawer, {
      props: { visible: true, todoId: 21 },
      global: { plugins: [installElementPlus] },
    });
    await flushPromises();
    const approve = wrapper.findAll('button').find((button) => button.text().includes('批准并写回'));
    expect(approve).toBeDefined();
    await approve!.trigger('click');
    await flushPromises();
    expect(api.approveContentTodoCandidate).toHaveBeenCalledWith(21, 3, {
      expected_version: 2,
      notify_mode: 'submitter',
    });
    wrapper.unmount();
  });

  it('shows a conflict message when the target content changed', async () => {
    vi.spyOn(ElMessageBox, 'confirm').mockResolvedValue('confirm' as never);
    api.approveContentTodoCandidate.mockRejectedValue({
      response: { data: { error: { code: 'CONFLICT', message: 'CONTENT_TODO_CONTENT_CHANGED' } } },
    });
    const wrapper = mount(ContentTodoDrawer, {
      props: { visible: true, todoId: 21 },
      global: { plugins: [installElementPlus] },
    });
    await flushPromises();
    const approve = wrapper.findAll('button').find((button) => button.text().includes('批准并写回'));
    const error = vi.spyOn(ElMessage, 'error').mockImplementation(() => ({}) as never);
    await approve!.trigger('click');
    await flushPromises();
    expect(error).toHaveBeenCalledWith('目标内容已变化, 请重新生成候选');
    wrapper.unmount();
  });

  it('opens the course editor with chapter and lesson query', async () => {
    const wrapper = mount(ContentTodoDrawer, {
      props: { visible: true, todoId: 21 },
      global: { plugins: [installElementPlus] },
    });
    await flushPromises();
    const link = wrapper.findAll('button').find((button) => button.text() === '打开课程编辑器');
    expect(link).toBeDefined();
    await link!.trigger('click');
    expect(router.push).toHaveBeenCalledWith({
      name: 'course-edit',
      params: { id: '12' },
      query: { chapter_id: '4', lesson_id: '8' },
    });
    wrapper.unmount();
  });

  it('disables write actions without content_todo.manage', async () => {
    permission.allowed = false;
    const wrapper = mount(ContentTodoDrawer, {
      props: { visible: true, todoId: 21 },
      global: { plugins: [installElementPlus] },
    });
    await flushPromises();
    const save = wrapper.findAll('button').find((button) => button.text() === '保存分诊');
    expect((save!.element as HTMLButtonElement).disabled).toBe(true);
    wrapper.unmount();
  });
});
