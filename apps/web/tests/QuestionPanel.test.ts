// @vitest-environment happy-dom

import { flushPromises, mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';

const learnerApi = vi.hoisted(() => ({
  askLessonQuestion: vi.fn(),
  fetchLessonQuestions: vi.fn(),
  fetchQuestion: vi.fn(),
  postFollowup: vi.fn(),
}));

vi.mock('@/api/learner', () => learnerApi);

import QuestionPanel from '@/views/learn/QuestionPanel.vue';

const question = {
  id: 17,
  course_id: 9,
  chapter_id: 3,
  lesson_id: 6,
  learner_id: 5,
  title: '如何安排练习？',
  status: 'pending',
  answered_at: '',
  created_at: '2026-08-30 10:00:00',
  updated_at: '2026-08-30 10:00:00',
};
const thread = {
  question,
  messages: [
    {
      id: 21,
      kind: 'questioner',
      author_learner_id: 5,
      author_staff_id: null,
      body: '问题正文',
      created_at: '2026-08-30 10:00:00',
    },
  ],
};

describe('QuestionPanel', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    learnerApi.fetchLessonQuestions.mockResolvedValue({
      items: [question],
      total: 1,
      page: 1,
      limit: 20,
    });
    learnerApi.askLessonQuestion.mockResolvedValue(thread);
    learnerApi.fetchQuestion.mockResolvedValue(thread);
    learnerApi.postFollowup.mockResolvedValue(thread);
  });

  it('reloads once when el-segmented changes the status filter', async () => {
    const wrapper = mount(QuestionPanel, { props: { lessonId: 6, authorized: true } });
    await flushPromises();

    const segmented = wrapper.findComponent({ name: 'ElSegmented' });
    expect(segmented.exists()).toBe(true);
    await segmented.setValue('pending');
    await flushPromises();

    expect(learnerApi.fetchLessonQuestions).toHaveBeenCalledWith(6, { status: 'pending' });
    expect(
      learnerApi.fetchLessonQuestions.mock.calls.filter(([, options]) =>
        Object.prototype.hasOwnProperty.call(options, 'status'),
      ),
    ).toHaveLength(1);
  });

  it('preserves question and follow-up payloads with el-input', async () => {
    const wrapper = mount(QuestionPanel, { props: { lessonId: 6, authorized: true } });
    await flushPromises();

    const askButton = wrapper
      .findAll('button')
      .find((button) => button.text().includes('我要提问'));
    await askButton?.trigger('click');

    const composerInputs = wrapper.findAllComponents({ name: 'ElInput' });
    expect(composerInputs).toHaveLength(2);
    await composerInputs[0]?.setValue('  新问题  ');
    await composerInputs[1]?.setValue('  新问题正文  ');
    await wrapper.get('form.composer').trigger('submit');
    await flushPromises();

    expect(learnerApi.askLessonQuestion).toHaveBeenCalledWith(6, {
      title: '新问题',
      body: '新问题正文',
    });

    const followupInput = wrapper.findComponent({ name: 'ElInput' });
    await followupInput.setValue('  请再说明一下  ');
    await wrapper.get('form.followup').trigger('submit');
    await flushPromises();

    expect(learnerApi.postFollowup).toHaveBeenCalledWith(17, { body: '请再说明一下' });
  });
});
