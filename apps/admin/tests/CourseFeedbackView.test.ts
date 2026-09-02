// @vitest-environment happy-dom

import { flushPromises, mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { installElementPlus } from '@/plugins/element-plus';

const feedbackApi = vi.hoisted(() => ({
  getFeedback: vi.fn(),
  listFeedback: vi.fn(),
  updateFeedbackStatus: vi.fn(),
}));
const routerApi = vi.hoisted(() => ({
  route: { name: 'course-feedback', params: { id: '42' } },
}));

vi.mock('@/api/courseFeedback', () => feedbackApi);
vi.mock('vue-router', () => ({ useRoute: () => routerApi.route }));

import CourseFeedbackView from '@/views/catalog/CourseFeedbackView.vue';

const listItem = {
  id: 7,
  course_id: 42,
  learner: { account_id: 101, nickname: '小明' },
  body_excerpt: '希望增加课后练习与完整案例',
  status: 'pending' as const,
  created_at: '2026-09-02T11:00:00+08:00',
  processed_at: null,
};

const detail = {
  ...listItem,
  body_html: '<p><strong>希望增加练习</strong></p><ul><li>课后题</li></ul>',
  processed_by_staff_id: null,
};

type MountedView = ReturnType<typeof mount>;

function mountView(): MountedView {
  return mount(CourseFeedbackView, {
    attachTo: document.body,
    global: { plugins: [installElementPlus] },
  });
}

async function openDetail(wrapper: MountedView): Promise<void> {
  await wrapper.get('[data-action="detail"]').trigger('click');
  await flushPromises();
}

async function chooseStatus(wrapper: MountedView, label: string): Promise<void> {
  const select = wrapper.get('[data-field="status"]');
  await select.get('.el-select__wrapper').trigger('click');
  const option = Array.from(
    document.body.querySelectorAll<HTMLElement>('.el-select-dropdown__item'),
  )
    .reverse()
    .find((candidate) => candidate.textContent?.trim() === label);
  expect(option).toBeDefined();
  option?.dispatchEvent(new MouseEvent('click', { bubbles: true }));
  await flushPromises();
}

function httpError(status: number, code: string, message: string): Error {
  return Object.assign(new Error(message), {
    response: { status, data: { error: { code, message } } },
  });
}

describe('CourseFeedbackView', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    document.body.innerHTML = '';
    routerApi.route = { name: 'course-feedback', params: { id: '42' } };
    feedbackApi.listFeedback.mockResolvedValue({
      items: [listItem],
      total: 1,
      page: 1,
      limit: 20,
    });
    feedbackApi.getFeedback.mockResolvedValue(detail);
    feedbackApi.updateFeedbackStatus.mockResolvedValue({
      ...detail,
      status: 'processed',
      processed_at: '2026-09-02T12:00:00+08:00',
      processed_by_staff_id: 9,
    });
  });

  it('lists public identity, excerpt and timestamps and filters by status', async () => {
    const wrapper = mountView();
    await flushPromises();

    expect(feedbackApi.listFeedback).toHaveBeenCalledWith(42, { page: 1, limit: 20 });
    expect(wrapper.text()).toContain('小明');
    expect(wrapper.text()).toContain('账号 #101');
    expect(wrapper.text()).toContain('希望增加课后练习与完整案例');
    expect(wrapper.text()).toContain('2026-09-02 11:00:00');
    expect(wrapper.text()).toContain('待处理');

    await chooseStatus(wrapper, '已处理');

    expect(feedbackApi.listFeedback).toHaveBeenLastCalledWith(42, {
      status: 'processed',
      page: 1,
      limit: 20,
    });
    wrapper.unmount();
  });

  it('renders server-sanitized detail HTML without executing inserted scripts', async () => {
    const scriptEffect = vi.fn();
    Object.defineProperty(window, '__feedbackScriptEffect', {
      configurable: true,
      value: scriptEffect,
    });
    feedbackApi.getFeedback.mockResolvedValue({
      ...detail,
      body_html: '<p><strong>安全正文</strong></p><script>window.__feedbackScriptEffect()</script>',
    });
    const wrapper = mountView();
    await flushPromises();

    await openDetail(wrapper);

    const body = wrapper.get('[data-role="feedback-body"]');
    expect(feedbackApi.getFeedback).toHaveBeenCalledWith(42, 7);
    expect(body.find('strong').text()).toBe('安全正文');
    expect(body.find('script').exists()).toBe(true);
    expect(scriptEffect).not.toHaveBeenCalled();
    wrapper.unmount();
    Reflect.deleteProperty(window, '__feedbackScriptEffect');
  });

  it('marks pending feedback processed and reloads the current list', async () => {
    const wrapper = mountView();
    await flushPromises();
    await openDetail(wrapper);

    await wrapper.get('[data-action="change-status"]').trigger('click');
    await flushPromises();

    expect(feedbackApi.updateFeedbackStatus).toHaveBeenCalledWith(42, 7, 'processed');
    expect(feedbackApi.listFeedback).toHaveBeenCalledTimes(2);
    expect(wrapper.text()).toContain('已处理');
    expect(wrapper.text()).toContain('打回待处理');
    wrapper.unmount();
  });

  it('shows a useful forbidden message and does not expose feedback rows', async () => {
    feedbackApi.listFeedback.mockRejectedValueOnce(httpError(403, 'FORBIDDEN', 'FORBIDDEN'));
    const wrapper = mountView();
    await flushPromises();

    expect(wrapper.text()).toContain('无权访问该课程的意见反馈。');
    expect(wrapper.text()).not.toContain(listItem.body_excerpt);
    expect(feedbackApi.getFeedback).not.toHaveBeenCalled();
    wrapper.unmount();
  });

  it('shows a useful missing-feedback message without stale detail content', async () => {
    feedbackApi.getFeedback.mockRejectedValueOnce(
      httpError(404, 'NOT_FOUND', 'FEEDBACK_NOT_FOUND'),
    );
    const wrapper = mountView();
    await flushPromises();

    await openDetail(wrapper);

    expect(wrapper.text()).toContain('反馈不存在或已被删除。');
    expect(wrapper.find('[data-role="feedback-body"]').exists()).toBe(false);
    expect(wrapper.text()).not.toContain('希望增加练习');
    wrapper.unmount();
  });
});
