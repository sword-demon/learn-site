// @vitest-environment happy-dom
import { flushPromises, mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { installElementPlus } from '@/plugins/element-plus';
import type { PublishChecklistDTO } from '@learn-site/contracts';
const api = vi.hoisted(() => ({ fetchPublishChecklist: vi.fn(), publishCourse: vi.fn() }));
vi.mock('@/api/catalog', () => api);
import Dialog from '@/views/catalog/CoursePublishChecklistDialog.vue';

const dto: PublishChecklistDTO = {
  course_id: 1,
  course_title: '核验课程',
  course_status: 'draft',
  generated_at: '',
  content_fingerprint: 'abc',
  hard_error_count: 1,
  warning_count: 0,
  can_publish: false,
  catalog: {
    category_id: 1,
    category_name: '分类',
    category_enabled: true,
    intro_present: false,
    price_mode: 'free',
    price_valid: true,
    effective_chapter_count: 0,
    effective_lesson_count: 0,
    trial_lesson_count: 0,
    chapters: [],
  },
  findings: [
    {
      code: 'INTRO_REQUIRED',
      severity: 'hard',
      message: '课程简介不能为空',
      scope: 'course',
      chapter_id: null,
      lesson_id: null,
    },
  ],
  impact: {
    maps: { published_count: 0, draft_count: 0, items: [] },
    entitlements: { active_count: 2 },
    progress: {
      enrollment_count: 2,
      current_denominator: 1,
      next_denominator: 1,
      will_recalculate: false,
      completed_preserved: true,
    },
    notification: { will_dispatch: true, recipient_count: 7, recipient_unavailable: false },
  },
};
describe('CoursePublishChecklistDialog', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    api.fetchPublishChecklist.mockResolvedValue(dto);
  });
  it('shows hard findings without publishing on open or cancel', async () => {
    const wrapper = mount(Dialog, {
      props: { modelValue: true, courseId: 1 },
      global: { plugins: [installElementPlus], stubs: { teleport: true } },
    });
    await flushPromises();
    expect(wrapper.text()).toContain('课程简介不能为空');
    expect(wrapper.text()).toContain('已有访问权');
    expect(wrapper.text()).toContain('在册学员 7');
    await wrapper
      .findAll('button')
      .find((b) => b.text() === '取消')
      ?.trigger('click');
    expect(api.publishCourse).not.toHaveBeenCalled();
    expect(wrapper.emitted('update:modelValue')).toEqual([[false]]);
    wrapper.unmount();
  });
  it('requires warning acknowledgment and refreshes stale checklist on rejection', async () => {
    api.fetchPublishChecklist.mockResolvedValue({
      ...dto,
      hard_error_count: 0,
      warning_count: 1,
      can_publish: true,
      findings: [],
    });
    api.publishCourse.mockRejectedValue({ response: { data: { error: { checklist: dto } } } });
    const wrapper = mount(Dialog, {
      props: { modelValue: true, courseId: 1 },
      global: { plugins: [installElementPlus], stubs: { teleport: true } },
    });
    await flushPromises();
    const primary = wrapper.findAll('button').find((b) => b.text() === '确认发布')!;
    expect(primary.attributes('disabled')).toBeDefined();
    await wrapper.find('input[type="checkbox"]').setValue(true);
    expect(primary.attributes('disabled')).toBeUndefined();
    await primary.trigger('click');
    await flushPromises();
    expect(api.publishCourse).toHaveBeenCalledWith(1, { acknowledge_warnings: true });
    expect(wrapper.text()).toContain('课程简介不能为空');
    expect(
      wrapper
        .findAll('button')
        .find((b) => b.text() === '确认发布')!
        .attributes('disabled'),
    ).toBeDefined();
    expect(wrapper.emitted('published')).toBeUndefined();
    wrapper.unmount();
  });
  it('publishes a green checklist only after loading and closes on success', async () => {
    api.fetchPublishChecklist.mockResolvedValue({
      ...dto,
      hard_error_count: 0,
      can_publish: true,
      findings: [],
    });
    api.publishCourse.mockResolvedValue({});
    const wrapper = mount(Dialog, {
      props: { modelValue: true, courseId: 1 },
      global: { plugins: [installElementPlus], stubs: { teleport: true } },
    });
    await flushPromises();
    await wrapper
      .findAll('button')
      .find((b) => b.text() === '确认发布')!
      .trigger('click');
    await flushPromises();
    expect(api.publishCourse).toHaveBeenCalledWith(1, { acknowledge_warnings: false });
    expect(wrapper.emitted('published')).toHaveLength(1);
    expect(wrapper.emitted('update:modelValue')).toEqual([[false]]);
    wrapper.unmount();
  });
});
