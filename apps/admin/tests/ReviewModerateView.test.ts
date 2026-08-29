// @vitest-environment happy-dom

import { flushPromises, mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import type { ReviewDTO, ReviewReplyDTO, ReviewThreadDTO } from '@learn-site/contracts';
import { installElementPlus } from '@/plugins/element-plus';

const reviewApi = vi.hoisted(() => ({
  fetchAdminThread: vi.fn(),
  fetchModerationFilterOptions: vi.fn(),
  hideReview: vi.fn(),
  hideReviewReply: vi.fn(),
  listForModeration: vi.fn(),
  postReviewReply: vi.fn(),
  restoreReview: vi.fn(),
  restoreReviewReply: vi.fn(),
}));
const catalogApi = vi.hoisted(() => ({ listCourses: vi.fn() }));
const authApi = vi.hoisted(() => ({ hasPermission: vi.fn() }));

vi.mock('@/api/reviews', () => reviewApi);
vi.mock('@/api/catalog', () => catalogApi);
vi.mock('@/api/http', () => authApi);

import ReviewModerateView from '@/views/reviews/ReviewModerateView.vue';

const course = { id: 12, title: 'TypeScript 深入实践' };

const review: ReviewDTO = {
  id: 56,
  course_id: course.id,
  learner_id: 78,
  viewer_owned: false,
  author_name: '林同学',
  rating: 5,
  body: '课程结构清晰。',
  visibility: 'public',
  hidden_reason: null,
  hidden_by_staff_id: null,
  hidden_at: null,
  edited: true,
  created_at: '2026-08-25 10:30:00',
  updated_at: '2026-08-25 10:35:00',
};

function reply(
  id: number,
  parentId: number | null,
  overrides: Partial<ReviewReplyDTO> = {},
): ReviewReplyDTO {
  return {
    id,
    review_id: review.id,
    parent_id: parentId,
    kind: 'learner',
    author_learner_id: 78,
    author_staff_id: null,
    viewer_owned: false,
    author_name: `回复者 ${id}`,
    body: `回复正文 ${id}`,
    visibility: 'public',
    hidden_reason: null,
    hidden_by_staff_id: null,
    hidden_at: null,
    edited: false,
    created_at: '2026-08-25 10:40:00',
    updated_at: '2026-08-25 10:40:00',
    ...overrides,
  };
}

const thread: ReviewThreadDTO = {
  review,
  replies: [reply(90, null), reply(91, 90), reply(92, 91)],
};

async function mountAndOpen(): Promise<ReturnType<typeof mount>> {
  const wrapper = mount(ReviewModerateView, { global: { plugins: [installElementPlus] } });
  await flushPromises();
  await wrapper.get('.review-button').trigger('click');
  await flushPromises();
  return wrapper;
}

describe('ReviewModerateView', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    authApi.hasPermission.mockReturnValue(true);
    reviewApi.fetchModerationFilterOptions.mockResolvedValue({ courses: [course] });
    reviewApi.listForModeration.mockResolvedValue({
      items: [review],
      viewer_review: null,
      total: 1,
      page: 1,
      limit: 20,
    });
    reviewApi.fetchAdminThread.mockResolvedValue(thread);
    reviewApi.hideReviewReply.mockResolvedValue(thread);
    reviewApi.restoreReviewReply.mockResolvedValue(thread);
    catalogApi.listCourses.mockResolvedValue({ items: [course], total: 1, page: 1, limit: 100 });
  });

  it('loads scoped course options without using the course management API', async () => {
    const wrapper = mount(ReviewModerateView, { global: { plugins: [installElementPlus] } });
    await flushPromises();

    expect(reviewApi.fetchModerationFilterOptions).toHaveBeenCalledOnce();
    expect(catalogApi.listCourses).not.toHaveBeenCalled();
    expect(wrapper.text()).toContain(course.title);

    const courseSelect = wrapper
      .findAllComponents({ name: 'ElSelect' })
      .find((component) => component.attributes('data-field') === 'course_id');
    const statusSelect = wrapper
      .findAllComponents({ name: 'ElSelect' })
      .find((component) => component.attributes('data-field') === 'visibility');

    expect(courseSelect?.props('teleported')).toBe(true);
    expect(courseSelect?.props('placement')).toBe('bottom-start');
    expect(courseSelect?.classes()).toContain('filter-control--course');
    expect(statusSelect?.classes()).toContain('filter-control--status');
  });

  it('renders the real three-level tree with public author identity', async () => {
    const wrapper = await mountAndOpen();

    expect(wrapper.findAll('[data-depth]')).toHaveLength(3);
    expect(wrapper.findAll('[data-depth="1"]')).toHaveLength(1);
    expect(wrapper.findAll('[data-depth="2"]')).toHaveLength(1);
    expect(wrapper.findAll('[data-depth="3"]')).toHaveLength(1);
    expect(wrapper.text()).toContain('回复者 92');
  });

  it('keeps moderation controls hidden for a review-view-only employee', async () => {
    authApi.hasPermission.mockImplementation((code: string) => code === 'review.view');
    const wrapper = await mountAndOpen();

    expect(wrapper.text()).not.toContain('隐藏评价');
    expect(wrapper.find('[data-action="hide-reply"]').exists()).toBe(false);
    expect(wrapper.text()).toContain('提交回复');
  });

  it('requires a reason and hides one reply through the moderation API', async () => {
    const wrapper = await mountAndOpen();
    const hideButton = wrapper.find('[data-action="hide-reply"]');

    expect(hideButton.exists()).toBe(true);
    await hideButton.trigger('click');
    await wrapper.get('[data-role="reply-hide-reason"]').setValue('  包含广告链接  ');
    await wrapper.get('[data-role="reply-hide-form"]').trigger('submit');
    await flushPromises();

    expect(reviewApi.hideReviewReply).toHaveBeenCalledWith(90, { reason: '包含广告链接' });
  });

  it('restores a hidden reply from the thread', async () => {
    reviewApi.fetchAdminThread.mockResolvedValue({
      ...thread,
      replies: [reply(90, null, { visibility: 'hidden', hidden_reason: '误判广告' })],
    });
    const wrapper = await mountAndOpen();
    const restoreButton = wrapper.find('[data-action="restore-reply"]');

    expect(restoreButton.exists()).toBe(true);
    await restoreButton.trigger('click');
    await flushPromises();

    expect(reviewApi.restoreReviewReply).toHaveBeenCalledWith(90);
  });
});
