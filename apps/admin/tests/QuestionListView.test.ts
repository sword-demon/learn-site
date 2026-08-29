// @vitest-environment happy-dom

import { flushPromises, mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import type { AdminInboxDTO, QuestionSummaryDTO } from '@learn-site/contracts';
import { installElementPlus } from '@/plugins/element-plus';

const qaApi = vi.hoisted(() => ({
  answerQuestion: vi.fn(),
  closeQuestion: vi.fn(),
  fetchFilterOptions: vi.fn(),
  fetchInbox: vi.fn(),
  fetchThread: vi.fn(),
}));

vi.mock('@/api/qa', () => qaApi);

import QuestionListView from '@/views/qa/QuestionListView.vue';

const course = {
  id: 12,
  title: 'TypeScript 深入实践',
};

const lesson = {
  id: 34,
  title: '条件类型',
};

const question: QuestionSummaryDTO = {
  id: 56,
  course_id: course.id,
  chapter_id: 20,
  lesson_id: lesson.id,
  learner_id: 78,
  title: 'infer 为什么只能在条件类型中使用？',
  status: 'pending',
  answered_at: '',
  created_at: '2026-08-25 10:30:00',
  updated_at: '2026-08-25 10:30:00',
};

const questionMessage = {
  id: 90,
  kind: 'questioner' as const,
  author_learner_id: 78,
  author_staff_id: null,
  body: 'infer 的作用是什么？',
  created_at: '2026-08-25 10:30:00',
};

const thread = {
  question,
  messages: [questionMessage],
};

function inbox(items: QuestionSummaryDTO[]): AdminInboxDTO {
  return { items, total: items.length, page: 1, limit: 20, status: 'pending' };
}

function mountQuestionList() {
  return mount(QuestionListView, { global: { plugins: [installElementPlus] } });
}

async function chooseOption(
  wrapper: ReturnType<typeof mountQuestionList>,
  field: string,
  label: string,
): Promise<void> {
  const select = wrapper.get(`[data-field="${field}"]`);
  await select.get('.el-select__wrapper').trigger('click');
  const option = Array.from(
    document.body.querySelectorAll<HTMLElement>('.el-select-dropdown__item'),
  )
    .reverse()
    .find((item) => item.textContent?.trim() === label);
  expect(option).toBeDefined();
  option?.dispatchEvent(new MouseEvent('click', { bubbles: true }));
  await flushPromises();
}

describe('QuestionListView', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    qaApi.fetchFilterOptions.mockImplementation(async (courseId?: number) => ({
      courses: [course],
      lessons: courseId === course.id ? [lesson] : [],
    }));
    qaApi.fetchThread.mockResolvedValue(thread);
    qaApi.fetchInbox.mockImplementation(async (params: Record<string, unknown>) =>
      params.status === 'pending' ? inbox([question]) : inbox([]),
    );
  });

  it('keeps filter poppers out of scrolling containers', () => {
    const wrapper = mountQuestionList();
    const select = wrapper
      .findAllComponents({ name: 'ElSelect' })
      .find((component) => component.attributes('data-field') === 'status');

    expect(select).toBeDefined();
    expect(select?.props('teleported')).toBe(true);
    expect(select?.props('placement')).toBe('bottom-start');
  });

  it('loads the pending inbox by default', async () => {
    const wrapper = mountQuestionList();
    await flushPromises();

    expect(wrapper.text()).toContain(question.title);
    expect(wrapper.get('[data-field="status"]').text()).toContain('待回复');
  });

  it('shows the empty state when an inbox payload has no items array', async () => {
    qaApi.fetchInbox.mockResolvedValueOnce({
      total: 0,
      page: 1,
      limit: 20,
      status: 'pending',
    } as unknown as AdminInboxDTO);
    const wrapper = mountQuestionList();
    await flushPromises();

    expect(wrapper.text()).toContain('当前筛选下暂无问答。');
    expect(wrapper.text()).not.toContain('Cannot read properties of undefined');
  });

  it('loads lessons for the selected course and applies both filters', async () => {
    qaApi.fetchInbox.mockImplementation(async (params: Record<string, unknown>) =>
      params.course_id === course.id && params.lesson_id === lesson.id
        ? inbox([question])
        : inbox([]),
    );
    const wrapper = mountQuestionList();
    await flushPromises();

    await chooseOption(wrapper, 'course_id', course.title);
    await flushPromises();
    await chooseOption(wrapper, 'lesson_id', lesson.title);
    await flushPromises();

    expect(wrapper.text()).toContain(question.title);
  });

  it('shows a detail loading error before any thread is active', async () => {
    qaApi.fetchThread.mockRejectedValueOnce(new Error('THREAD_UNAVAILABLE'));
    const wrapper = mountQuestionList();
    await flushPromises();

    await wrapper.get('.thread-button').trigger('click');
    await flushPromises();

    expect(wrapper.text()).toContain('THREAD_UNAVAILABLE');
  });

  it('renders the updated thread after submitting a reply', async () => {
    qaApi.answerQuestion.mockResolvedValue({
      question: { ...question, status: 'answered' },
      messages: [
        questionMessage,
        {
          id: 91,
          kind: 'admin',
          author_learner_id: null,
          author_staff_id: 8,
          body: 'infer 用于从匹配的类型中提取类型变量。',
          created_at: '2026-08-25 10:40:00',
        },
      ],
    });
    const wrapper = mountQuestionList();
    await flushPromises();

    await wrapper.get('.thread-button').trigger('click');
    await flushPromises();
    await wrapper.get('textarea').setValue('  infer 用于提取类型变量。  ');
    await wrapper.get('form.reply').trigger('submit');
    await flushPromises();

    expect(qaApi.answerQuestion).toHaveBeenCalledWith(question.id, {
      body: 'infer 用于提取类型变量。',
    });
    expect(wrapper.text()).toContain('infer 用于从匹配的类型中提取类型变量。');
    expect(wrapper.get<HTMLTextAreaElement>('textarea').element.value).toBe('');
  });

  it('keeps a close failure visible beside the active thread', async () => {
    vi.stubGlobal('confirm', () => true);
    qaApi.closeQuestion.mockRejectedValueOnce(new Error('CLOSE_DENIED'));
    const wrapper = mountQuestionList();
    await flushPromises();

    await wrapper.get('.thread-button').trigger('click');
    await flushPromises();
    await wrapper.get('.btn-danger').trigger('click');
    await flushPromises();

    expect(wrapper.text()).toContain('关闭失败（CLOSE_DENIED）');
    vi.unstubAllGlobals();
  });
});
