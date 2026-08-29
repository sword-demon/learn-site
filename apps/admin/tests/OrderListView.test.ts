// @vitest-environment happy-dom

import { flushPromises, mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import type { AdminOrderDTO } from '@learn-site/contracts';
import { installElementPlus } from '@/plugins/element-plus';

const orderApi = vi.hoisted(() => ({
  getOrder: vi.fn(),
  listOrders: vi.fn(),
}));
const catalogApi = vi.hoisted(() => ({ listCourses: vi.fn() }));
const learnersApi = vi.hoisted(() => ({ listLearners: vi.fn() }));

vi.mock('@/api/orders', () => orderApi);
vi.mock('@/api/catalog', () => catalogApi);
vi.mock('@/api/learners', () => learnersApi);

import OrderListView from '@/views/orders/OrderListView.vue';

const order: AdminOrderDTO = {
  order_id: 42,
  course_id: 12,
  course_title: 'TypeScript 深入实践',
  learner_id: 78,
  department_id: 3,
  list_price_snapshot: 199,
  sale_price_snapshot: 149,
  paid_amount: 149,
  currency: 'CNY',
  status: 'succeeded',
  provider: 'fake',
  provider_ref: 'fake-42',
  succeeded_at: '2026-08-25 10:30:00',
  created_at: '2026-08-25 10:29:00',
  failed_reason: null,
};

const course = {
  id: 12,
  department_id: 3,
  category_id: 4,
  title: 'TypeScript 深入实践',
  cover_url: null,
  teacher_name: '张老师',
  summary: '课程简介',
  intro_rich_text: '课程介绍',
  status: 'published' as const,
  price_mode: 'paid' as const,
  list_price: 199,
  sale_price: 149,
  sale_start_at: null,
  sale_end_at: null,
  created_by_staff_id: 1,
  created_at: '2026-08-25 10:00:00',
  updated_at: '2026-08-25 10:00:00',
};

const learner = {
  account_id: 78,
  login: '13900000078',
  display_name: '小王',
  department_id: null,
  department_name: '',
  status: 'active' as const,
  must_change_password: false,
  last_login_at: null,
  created_at: '2026-08-25 10:00:00',
  course_count: 1,
  completed_course_count: 0,
  successful_order_count: 1,
  total_paid_amount: 149,
};

describe('OrderListView', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    orderApi.listOrders.mockResolvedValue({
      items: [order],
      total: 1,
      page: 1,
      limit: 20,
    });
    orderApi.getOrder.mockResolvedValue(order);
    catalogApi.listCourses.mockResolvedValue({ items: [course], total: 1, page: 1, limit: 20 });
    learnersApi.listLearners.mockResolvedValue({ items: [learner], total: 1, page: 1, limit: 20 });
  });

  function mountOrders() {
    return mount(OrderListView, { global: { plugins: [installElementPlus] } });
  }

  async function chooseStatus(
    wrapper: ReturnType<typeof mountOrders>,
    label: string,
  ): Promise<void> {
    const select = wrapper.get('[data-field="status"]');
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

  it('keeps the status popper out of scrolling containers', () => {
    const wrapper = mountOrders();
    const select = wrapper
      .findAllComponents({ name: 'ElSelect' })
      .find((component) => component.attributes('data-field') === 'status');

    expect(select).toBeDefined();
    expect(select?.props('teleported')).toBe(true);
    expect(select?.props('placement')).toBe('bottom-start');
    expect(select?.props('placeholder')).toBe('全部');
  });

  it('renders the order summary and immutable payment snapshot', async () => {
    const wrapper = mountOrders();
    await flushPromises();

    expect(wrapper.text()).toContain('TypeScript 深入实践');
    expect(wrapper.text()).toContain('#78');
    expect(wrapper.text()).toContain('CNY 149.00');
    expect(wrapper.text()).toContain('已支付');
    expect(wrapper.text()).toContain('2026-08-25 10:29:00');

    await wrapper.get('.el-table__body tr').trigger('click');
    await flushPromises();

    expect(orderApi.getOrder).toHaveBeenCalledWith(42);
    const detail = wrapper.get('.right-pane').text();
    expect(detail).toContain('标价CNY 199.00');
    expect(detail).toContain('售价CNY 149.00');
    expect(detail).toContain('实付CNY 149.00');
    expect(detail).toContain('fake / fake-42');
    expect(detail).toContain('2026-08-25 10:30:00');
  });

  it('keeps a detail loading failure visible when no order is active', async () => {
    orderApi.getOrder.mockRejectedValueOnce(new Error('DETAIL_UNAVAILABLE'));
    const wrapper = mountOrders();
    await flushPromises();

    await wrapper.get('.el-table__body tr').trigger('click');
    await flushPromises();

    expect(wrapper.text()).toContain('DETAIL_UNAVAILABLE');
  });

  it('returns to the first page when applying a status filter', async () => {
    orderApi.listOrders.mockImplementation(async (params: { page: number; limit: number }) => ({
      items: [order],
      total: 40,
      page: params.page,
      limit: params.limit,
    }));
    const wrapper = mountOrders();
    await flushPromises();

    await wrapper.get('button.btn-next').trigger('click');
    await flushPromises();

    await chooseStatus(wrapper, '已支付');
    await wrapper.get('form.filters').trigger('submit');
    await flushPromises();

    expect(orderApi.listOrders).toHaveBeenLastCalledWith({
      status: 'succeeded',
      page: 1,
      limit: 20,
    });
  });

  it('loads paginated course and learner options and submits their selected IDs', async () => {
    const wrapper = mountOrders();
    await flushPromises();

    expect(catalogApi.listCourses).toHaveBeenCalledWith({ page: 1, limit: 20 });
    expect(learnersApi.listLearners).toHaveBeenCalledWith({ page: 1, limit: 20 });

    const courseSelect = wrapper
      .findAllComponents({ name: 'ElSelect' })
      .find((component) => component.attributes('data-field') === 'course_id');
    const learnerSelect = wrapper
      .findAllComponents({ name: 'ElSelect' })
      .find((component) => component.attributes('data-field') === 'learner_id');
    expect(courseSelect).toBeDefined();
    expect(learnerSelect).toBeDefined();
    expect(courseSelect?.props('name')).toBe('course_id');
    expect(learnerSelect?.props('name')).toBe('learner_id');
    expect(courseSelect?.classes()).toContain('filter-control');
    expect(learnerSelect?.classes()).toContain('filter-control');

    await wrapper.get('[data-field="course_id"] .el-select__wrapper').trigger('click');
    const courseOption = Array.from(
      document.body.querySelectorAll<HTMLElement>('.el-select-dropdown__item'),
    )
      .reverse()
      .find((item) => item.textContent?.includes('TypeScript 深入实践'));
    expect(courseOption).toBeDefined();
    courseOption?.dispatchEvent(new MouseEvent('click', { bubbles: true }));
    await flushPromises();

    await wrapper.get('[data-field="learner_id"] .el-select__wrapper').trigger('click');
    const learnerOption = Array.from(
      document.body.querySelectorAll<HTMLElement>('.el-select-dropdown__item'),
    )
      .reverse()
      .find((item) => item.textContent?.includes('小王'));
    expect(learnerOption).toBeDefined();
    learnerOption?.dispatchEvent(new MouseEvent('click', { bubbles: true }));
    await flushPromises();

    await wrapper.get('form.filters').trigger('submit');
    await flushPromises();

    expect(orderApi.listOrders).toHaveBeenLastCalledWith({
      course_id: 12,
      learner_id: 78,
      page: 1,
      limit: 20,
    });
  });

  it('searches option lists remotely and loads their next pages', async () => {
    const nextCourse = { ...course, id: 13, title: 'Vue 工程化实践' };
    const nextLearner = { ...learner, account_id: 79, display_name: '小李' };
    catalogApi.listCourses.mockImplementation(async (params: { q?: string; page?: number }) => {
      if (params.q && params.page === 2) {
        return { items: [nextCourse], total: 21, page: 2, limit: 20 };
      }
      if (params.q) return { items: [course], total: 21, page: 1, limit: 20 };
      if (params.page === 2) return { items: [nextCourse], total: 21, page: 2, limit: 20 };
      return { items: [course], total: 21, page: 1, limit: 20 };
    });
    learnersApi.listLearners.mockImplementation(
      async (params: { search?: string; page?: number }) => {
        if (params.search && params.page === 2) {
          return { items: [nextLearner], total: 21, page: 2, limit: 20 };
        }
        if (params.search) return { items: [learner], total: 21, page: 1, limit: 20 };
        if (params.page === 2) return { items: [nextLearner], total: 21, page: 2, limit: 20 };
        return { items: [learner], total: 21, page: 1, limit: 20 };
      },
    );

    const wrapper = mountOrders();
    await flushPromises();
    const selects = wrapper.findAllComponents({ name: 'ElSelect' });
    const courseSelect = selects.find(
      (component) => component.attributes('data-field') === 'course_id',
    );
    const learnerSelect = selects.find(
      (component) => component.attributes('data-field') === 'learner_id',
    );
    expect(courseSelect).toBeDefined();
    expect(learnerSelect).toBeDefined();

    await wrapper.get('[data-field="course_id"] .el-select__wrapper').trigger('click');
    const courseMore = document.body.querySelector<HTMLButtonElement>(
      '[data-action="load-more-courses"]',
    );
    expect(courseMore).toBeTruthy();
    courseMore?.click();
    await flushPromises();
    expect(catalogApi.listCourses).toHaveBeenLastCalledWith({ page: 2, limit: 20 });

    const courseRemoteMethod = courseSelect?.props('remoteMethod') as
      ((query: string) => void) | undefined;
    courseRemoteMethod?.('Vue');
    await flushPromises();
    expect(catalogApi.listCourses).toHaveBeenLastCalledWith({ q: 'Vue', page: 1, limit: 20 });
    const courseMoreAfterSearch = document.body.querySelector<HTMLButtonElement>(
      '[data-action="load-more-courses"]',
    );
    courseMoreAfterSearch?.click();
    await flushPromises();
    expect(catalogApi.listCourses).toHaveBeenLastCalledWith({ q: 'Vue', page: 2, limit: 20 });

    await wrapper.get('[data-field="learner_id"] .el-select__wrapper').trigger('click');
    const learnerMore = document.body.querySelector<HTMLButtonElement>(
      '[data-action="load-more-learners"]',
    );
    expect(learnerMore).toBeTruthy();
    learnerMore?.click();
    await flushPromises();
    expect(learnersApi.listLearners).toHaveBeenLastCalledWith({ page: 2, limit: 20 });

    const learnerRemoteMethod = learnerSelect?.props('remoteMethod') as
      ((query: string) => void) | undefined;
    learnerRemoteMethod?.('小李');
    await flushPromises();
    expect(learnersApi.listLearners).toHaveBeenLastCalledWith({
      search: '小李',
      page: 1,
      limit: 20,
    });
    const learnerMoreAfterSearch = document.body.querySelector<HTMLButtonElement>(
      '[data-action="load-more-learners"]',
    );
    learnerMoreAfterSearch?.click();
    await flushPromises();
    expect(learnersApi.listLearners).toHaveBeenLastCalledWith({
      search: '小李',
      page: 2,
      limit: 20,
    });
  });
});
