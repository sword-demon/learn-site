// @vitest-environment happy-dom

import { flushPromises, mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';

const learnerApi = vi.hoisted(() => ({
  deleteCourseReview: vi.fn(),
  fetchCourseReviews: vi.fn(),
  fetchReviewThread: vi.fn(),
  postCourseReview: vi.fn(),
  postReviewReply: vi.fn(),
  updateCourseReview: vi.fn(),
}));

vi.mock('@/api/learner', () => learnerApi);

import ReviewTree from '@/views/catalog/ReviewTree.vue';

const review = {
  id: 11,
  course_id: 9,
  learner_id: 5,
  viewer_owned: false,
  author_name: '同学甲',
  rating: 5,
  body: '内容扎实',
  visibility: 'public',
  hidden_reason: null,
  hidden_by_staff_id: null,
  hidden_at: null,
  edited: false,
  created_at: '2026-08-30 10:00:00',
  updated_at: '2026-08-30 10:00:00',
};

describe('ReviewTree', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    learnerApi.fetchCourseReviews.mockImplementation(
      (_courseId: number, options: { page: number; limit: number }) =>
        Promise.resolve({
          items: [review],
          viewer_review: null,
          total: 21,
          page: options.page,
          limit: options.limit,
        }),
    );
    learnerApi.postCourseReview.mockResolvedValue({ review, replies: [] });
  });

  it('submits the el-rate value and loads a selected pagination page once', async () => {
    const wrapper = mount(ReviewTree, { props: { courseId: 9, authorized: true } });
    await flushPromises();

    const composeButton = wrapper
      .findAll('button')
      .find((button) => button.text().includes('我要评价'));
    expect(composeButton).toBeDefined();
    await composeButton?.trigger('click');

    const rate = wrapper.findComponent({ name: 'ElRate' });
    expect(rate.exists()).toBe(true);
    await rate.setValue(4);
    const body = wrapper.findComponent({ name: 'ElInput' });
    await body.setValue('  很有收获  ');
    await wrapper.get('form.rv-form').trigger('submit');
    await flushPromises();

    expect(learnerApi.postCourseReview).toHaveBeenCalledWith(9, {
      rating: 4,
      body: '很有收获',
    });

    const pagination = wrapper.findComponent({ name: 'ElPagination' });
    expect(pagination.exists()).toBe(true);
    pagination.vm.$emit('current-change', 2);
    await flushPromises();

    expect(learnerApi.fetchCourseReviews).toHaveBeenCalledWith(9, { page: 2, limit: 10 });
    expect(
      learnerApi.fetchCourseReviews.mock.calls.filter(([, options]) => options.page === 2),
    ).toHaveLength(1);
  });
});
