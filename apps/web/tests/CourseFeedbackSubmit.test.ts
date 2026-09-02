// @vitest-environment happy-dom

import { flushPromises, mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import CourseFeedbackForm from '@/components/CourseFeedbackForm.vue';

const feedbackApi = vi.hoisted(() => ({
  submitCourseFeedback: vi.fn(),
}));

vi.mock('@/api/courseFeedback', () => feedbackApi);
vi.mock('@/components/CheckinPlanEditor.vue', () => ({
  default: {
    name: 'CheckinPlanEditor',
    props: ['modelValue', 'disabled'],
    emits: ['update:modelValue'],
    template: '<div data-testid="editor-stub" />',
  },
}));

function deferred<T>() {
  let resolve!: (value: T) => void;
  const promise = new Promise<T>((resolvePromise) => {
    resolve = resolvePromise;
  });
  return { promise, resolve };
}

describe('CourseFeedbackForm', () => {
  beforeEach(() => {
    feedbackApi.submitCourseFeedback.mockReset();
  });

  it('does not submit empty or overlong feedback', async () => {
    const wrapper = mount(CourseFeedbackForm, { props: { courseId: 42 } });
    const editor = wrapper.findComponent({ name: 'CheckinPlanEditor' });

    editor.vm.$emit('update:modelValue', '<p><br></p>');
    await wrapper.get('[data-testid="submit-feedback"]').trigger('click');

    expect(feedbackApi.submitCourseFeedback).not.toHaveBeenCalled();
    expect(wrapper.text()).toContain('请填写反馈内容');

    editor.vm.$emit('update:modelValue', '字'.repeat(20_001));
    await wrapper.get('[data-testid="submit-feedback"]').trigger('click');

    expect(feedbackApi.submitCourseFeedback).not.toHaveBeenCalled();
    expect(wrapper.text()).toContain('反馈内容不能超过 20000 个字符');
  });

  it('submits feedback and shows confirmation', async () => {
    const created = {
      id: 7,
      course_id: 42,
      status: 'pending' as const,
      created_at: '2026-09-02T11:00:00+08:00',
    };
    feedbackApi.submitCourseFeedback.mockResolvedValue(created);
    const wrapper = mount(CourseFeedbackForm, { props: { courseId: 42 } });

    wrapper
      .findComponent({ name: 'CheckinPlanEditor' })
      .vm.$emit('update:modelValue', '<p>希望增加练习题</p>');
    await wrapper.get('[data-testid="submit-feedback"]').trigger('click');
    await flushPromises();

    expect(feedbackApi.submitCourseFeedback).toHaveBeenCalledWith(42, '<p>希望增加练习题</p>');
    expect(wrapper.text()).toContain('反馈已提交');
    expect(wrapper.emitted('success')).toEqual([[created]]);
    expect(wrapper.findComponent({ name: 'CheckinPlanEditor' }).props('modelValue')).toBe('');
  });

  it('guards against duplicate clicks while submission is pending', async () => {
    const pending = deferred<{
      id: number;
      course_id: number;
      status: 'pending';
      created_at: string;
    }>();
    feedbackApi.submitCourseFeedback.mockReturnValue(pending.promise);
    const wrapper = mount(CourseFeedbackForm, { props: { courseId: 42 } });
    wrapper
      .findComponent({ name: 'CheckinPlanEditor' })
      .vm.$emit('update:modelValue', '<p>课程反馈</p>');
    await wrapper.vm.$nextTick();

    const submit = wrapper.get('[data-testid="submit-feedback"]');
    void submit.trigger('click');
    void submit.trigger('click');
    await wrapper.vm.$nextTick();

    expect(feedbackApi.submitCourseFeedback).toHaveBeenCalledTimes(1);
    expect(submit.attributes('disabled')).toBeDefined();
    expect(wrapper.findComponent({ name: 'CheckinPlanEditor' }).props('disabled')).toBe(true);

    pending.resolve({
      id: 8,
      course_id: 42,
      status: 'pending',
      created_at: '2026-09-02T11:01:00+08:00',
    });
    await flushPromises();
  });

  it('shows an understandable API error and allows retrying', async () => {
    feedbackApi.submitCourseFeedback.mockRejectedValue(
      Object.assign(new Error('COURSE_ACCESS_REQUIRED'), { code: 'FORBIDDEN' }),
    );
    const wrapper = mount(CourseFeedbackForm, { props: { courseId: 42 } });
    wrapper
      .findComponent({ name: 'CheckinPlanEditor' })
      .vm.$emit('update:modelValue', '<p>课程反馈</p>');
    await wrapper.get('[data-testid="submit-feedback"]').trigger('click');
    await flushPromises();

    expect(wrapper.text()).toContain('取得课程访问权后才能提交反馈');
    expect(wrapper.get('[data-testid="submit-feedback"]').attributes('disabled')).toBeUndefined();
  });
});
