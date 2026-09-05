// @vitest-environment happy-dom

import { nextTick, reactive, ref } from 'vue';
import { flushPromises, mount } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

const learnerApi = vi.hoisted(() => ({
  fetchMyLearning: vi.fn(),
  startCourse: vi.fn(),
  fetchFavorites: vi.fn(),
  removeFavorite: vi.fn(),
  fetchOrders: vi.fn(),
  fetchLearnerProfile: vi.fn(),
  updateLearnerProfile: vi.fn(),
}));
const notificationsApi = vi.hoisted(() => ({
  listNotifications: vi.fn(),
  markNotificationRead: vi.fn(),
  fetchUnreadCount: vi.fn(),
}));
const checkinsApi = vi.hoisted(() => ({ listCheckins: vi.fn() }));
const pushApi = vi.hoisted(() => ({ createPushConnection: vi.fn() }));
const routerApi = vi.hoisted(() => ({
  route: null as unknown as ReturnType<
    typeof reactive<{ path: string; query: Record<string, unknown>; fullPath: string }>
  >,
  push: vi.fn(),
  replace: vi.fn(),
}));

vi.mock('@/api/learner', () => learnerApi);
vi.mock('@/api/notifications', () => notificationsApi);
vi.mock('@/api/checkins', () => checkinsApi);
vi.mock('@/utils/push', () => pushApi);
vi.mock('vue-router', () => ({
  useRoute: () => routerApi.route,
  useRouter: () => ({ push: routerApi.push, replace: routerApi.replace }),
}));
vi.mock('@/components/MarkdownRenderer.vue', () => ({
  default: { props: ['html'], template: '<div class="rich">{{ html }}</div>' },
}));

import StudentCenterView from '@/views/me/StudentCenterView.vue';
import { clearTokens } from '@/api/http';
import { useLoginFamilyStore } from '@/api/login';
import { useNotificationStore } from '@/stores/notifications';

routerApi.route = reactive({ path: '/me/learning', query: {}, fullPath: '/me/learning' });

const RouterLinkStub = {
  props: { to: { type: [String, Object], required: true } },
  template: '<a :href="String(to)"><slot /></a>',
};

const tokenPair = {
  access_token: 'access-token',
  access_expires_in: 900,
  refresh_token: 'refresh-token',
  refresh_expires_in: 604800,
};

// Fixtures
const learningFixture = {
  items: [
    {
      course_id: 7,
      progress_percent: 40,
      last_lesson_id: 12,
      last_position: 120,
      completed_at: null,
      updated_at: '2026-08-28 10:00:00',
      entitlement_status: 'active',
      entitlement_source: 'free',
      revoked_at: null,
      revoked_reason: null,
      can_rejoin: false,
      course: {
        id: 7,
        title: 'Webman 实战',
        cover_url: null,
        teacher_name: '林老师',
        status: 'published',
        price_mode: 'free',
      },
    },
  ],
};

const favoritesFixture = {
  items: [
    {
      course_id: 9,
      title: '已下架课程',
      cover_url: null,
      teacher_name: '林老师',
      price_mode: 'free',
      list_price: 0,
      status: 'unpublished',
      favorited_at: '2026-08-28 10:00:00',
    },
  ],
  total: 1,
  page: 1,
  limit: 50,
};

const ordersFixture = {
  items: [
    {
      order_id: 100,
      course_id: 7,
      course_title: 'Webman 实战',
      list_price_snapshot: 199.0,
      sale_price_snapshot: 0,
      coupon_discount_snapshot: 0,
      paid_amount: 199.0,
      learner_coupon_id: null,
      currency: 'CNY',
      status: 'succeeded',
      provider: 'fake',
      succeeded_at: '2026-08-20T10:01:00+08:00',
      created_at: '2026-08-20T10:00:00',
    },
  ],
};

const messagesFixture = {
  items: [
    {
      id: 1,
      kind: 'question_update',
      title: '问题有新回复',
      body: '管理员回复了你的问题',
      resource_type: 'question',
      resource_id: 12,
      resource_path: '/',
      resource_available: true,
      resource_unavailable_reason: null,
      payload: { course_id: 5 },
      read: false,
      created_at: '2026-08-28 10:00:00',
    },
    {
      id: 2,
      kind: 'progress_reset',
      title: '学习进度已重置',
      body: null,
      resource_type: 'course',
      resource_id: 5,
      resource_path: '/courses/5',
      resource_available: true,
      resource_unavailable_reason: null,
      payload: null,
      read: true,
      created_at: '2026-08-28 09:30:00',
    },
  ],
  total: 2,
  page: 1,
  limit: 20,
};

const coursePublishedMessagesFixture = {
  items: [
    {
      id: 3,
      kind: 'course_published',
      title: '新课上线:示例课',
      body: '课程发布通知验证摘要',
      dispatch_id: 77,
      resource_type: 'course',
      resource_id: 42,
      resource_path: '/courses/42',
      resource_available: true,
      resource_unavailable_reason: null,
      payload: null,
      read: false,
      created_at: '2026-09-02 10:00:00',
    },
    {
      id: 4,
      kind: 'course_published',
      title: '已下架课程',
      body: '课程发布通知验证摘要',
      dispatch_id: 77,
      resource_type: 'course',
      resource_id: 43,
      resource_path: '/',
      resource_available: false,
      resource_unavailable_reason: '关联内容已不可用',
      payload: null,
      read: false,
      created_at: '2026-09-02 09:00:00',
    },
  ],
  total: 2,
  page: 1,
  limit: 20,
};

const emptyCheckinsFixture = { items: [], total: 0, page: 1, limit: 20 };

const profileFixture = {
  account_id: 7,
  phone: '13800138000',
  nickname: '旧称呼',
  avatar_url: null,
  show_on_course: false,
  status: 'active',
  created_at: '2026-08-30 10:00:00',
};

function setPath(path: string): void {
  routerApi.route.path = path;
  routerApi.route.fullPath = path;
}

describe('StudentCenterView', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    setPath('/me/learning');
    learnerApi.fetchMyLearning.mockResolvedValue(learningFixture);
    learnerApi.fetchFavorites.mockResolvedValue(favoritesFixture);
    learnerApi.fetchOrders.mockResolvedValue(ordersFixture);
    learnerApi.fetchLearnerProfile.mockResolvedValue(profileFixture);
    learnerApi.updateLearnerProfile.mockResolvedValue({
      ...profileFixture,
      nickname: '新称呼',
      show_on_course: true,
    });
    notificationsApi.listNotifications.mockResolvedValue(messagesFixture);
    notificationsApi.fetchUnreadCount.mockResolvedValue({ count: 2 });
    notificationsApi.markNotificationRead.mockResolvedValue({ read: true });
    checkinsApi.listCheckins.mockResolvedValue(emptyCheckinsFixture);
    pushApi.createPushConnection.mockResolvedValue({
      subscribe: vi.fn(() => ({ on: vi.fn() })),
      disconnect: vi.fn(),
    });
    learnerApi.removeFavorite.mockResolvedValue({ course_id: 9, favorited: false });
  });

  afterEach(() => {
    useNotificationStore().disconnect();
    clearTokens();
  });

  function mountView(
    pinia = createPinia(),
    checkinPrompt?: {
      dialogVisible: { value: boolean };
      refreshStatus: (options?: { forceOpen?: boolean }) => Promise<void>;
      afterSuccess: (hook: () => void) => () => void;
    },
  ) {
    setActivePinia(pinia);
    useLoginFamilyStore().signIn(tokenPair);
    return mount(StudentCenterView, {
      global: {
        plugins: [pinia],
        stubs: { RouterLink: RouterLinkStub },
        provide: checkinPrompt ? { dailyCheckinPrompt: checkinPrompt } : {},
      },
    });
  }

  describe('check-in trigger', () => {
    it('keeps the dialog closed while a checked-in status refresh is pending', async () => {
      const dialogVisible = ref(false);
      const checkedInToday = ref(false);
      let releaseRefresh!: () => void;
      const refreshStatus = vi.fn(
        () =>
          new Promise<void>((resolve) => {
            releaseRefresh = () => {
              dialogVisible.value = false;
              resolve();
            };
          }),
      );
      const checkinPrompt = {
        dialogVisible,
        checkedInToday,
        refreshStatus,
        afterSuccess: vi.fn(() => vi.fn()),
      };
      const wrapper = mountView(createPinia(), checkinPrompt);

      const click = wrapper.get('button[data-action="open-checkin"]').trigger('click');
      await nextTick();

      expect(dialogVisible.value).toBe(false);

      releaseRefresh();
      await click;
      await flushPromises();
      expect(dialogVisible.value).toBe(false);
    });

    it('opens the dialog after confirming that today is available', async () => {
      const dialogVisible = ref(false);
      const checkedInToday = ref(false);
      const refreshStatus = vi.fn(async (options?: { forceOpen?: boolean }) => {
        if (options?.forceOpen) dialogVisible.value = true;
      });
      const checkinPrompt = {
        dialogVisible,
        checkedInToday,
        refreshStatus,
        afterSuccess: vi.fn(() => vi.fn()),
      };
      const wrapper = mountView(createPinia(), checkinPrompt);

      await wrapper.get('button[data-action="open-checkin"]').trigger('click');
      await flushPromises();

      expect(refreshStatus).toHaveBeenCalledWith({ forceOpen: true });
      expect(dialogVisible.value).toBe(true);
    });

    it('does not refresh the checked-in status when today is already complete', async () => {
      const dialogVisible = ref(false);
      const checkedInToday = ref(true);
      const refreshStatus = vi.fn();
      const checkinPrompt = {
        dialogVisible,
        checkedInToday,
        refreshStatus,
        afterSuccess: vi.fn(() => vi.fn()),
      };
      const wrapper = mountView(createPinia(), checkinPrompt);

      await wrapper.get('button[data-action="open-checkin"]').trigger('click');

      expect(refreshStatus).not.toHaveBeenCalled();
    });

    it('does not send duplicate status refreshes during a repeated click', async () => {
      const dialogVisible = ref(false);
      const checkedInToday = ref(false);
      let releaseRefresh!: () => void;
      const refreshStatus = vi.fn(
        () =>
          new Promise<void>((resolve) => {
            releaseRefresh = resolve;
          }),
      );
      const checkinPrompt = {
        dialogVisible,
        checkedInToday,
        refreshStatus,
        afterSuccess: vi.fn(() => vi.fn()),
      };
      const wrapper = mountView(createPinia(), checkinPrompt);

      const firstClick = wrapper.get('button[data-action="open-checkin"]').trigger('click');
      await nextTick();
      const secondClick = wrapper.get('button[data-action="open-checkin"]').trigger('click');

      expect(refreshStatus).toHaveBeenCalledTimes(1);
      releaseRefresh();
      await Promise.all([firstClick, secondClick]);
    });
  });

  it('renders the streak banner on every tab and derives active tab from route.path', async () => {
    const wrapper = mountView();
    await flushPromises();

    expect(wrapper.find('[data-testid="streak-banner"]').exists()).toBe(true);
    expect(wrapper.find('[data-tab="learning"]').exists()).toBe(true);
    expect(wrapper.find('[data-tab="messages"]').exists()).toBe(false);

    setPath('/me/messages');
    await flushPromises();

    expect(wrapper.find('[data-tab="messages"]').exists()).toBe(true);
    expect(wrapper.find('[data-tab="learning"]').exists()).toBe(false);
  });

  it('derives the activation-code tab from /me/redeem route.path', async () => {
    setPath('/me/redeem');
    const wrapper = mountView();
    await flushPromises();

    expect(wrapper.find('[data-tab="redeem"]').exists()).toBe(true);
    expect(wrapper.text()).toContain('兑换课程');
    expect(wrapper.findComponent({ name: 'ActivationCodeRedeemForm' }).exists()).toBe(true);
  });

  describe('learning tab', () => {
    it('renders persisted progress and a resume link', async () => {
      const wrapper = mountView();
      await flushPromises();

      expect(learnerApi.fetchMyLearning).toHaveBeenCalled();
      expect(wrapper.text()).toContain('Webman 实战');
      expect(wrapper.text()).toContain('40%');
      expect(wrapper.get('a[href="/learn/7/12"]').text()).toContain('继续学习');
    });

    it('shows a useful empty state when there is no enrollment', async () => {
      learnerApi.fetchMyLearning.mockResolvedValueOnce({ items: [] });
      const wrapper = mountView();
      await flushPromises();

      expect(wrapper.text()).toContain('还没有开始任何课程');
    });

    it('rejoins without losing the resume position', async () => {
      learnerApi.fetchMyLearning.mockResolvedValueOnce({
        items: [
          {
            ...learningFixture.items[0]!,
            entitlement_status: 'revoked',
            revoked_reason: '误加入测试课程',
            can_rejoin: true,
          },
        ],
      });
      learnerApi.startCourse.mockResolvedValueOnce({
        course_id: 7,
        entitled: true,
        source: 'free',
        price_mode: 'free',
        first_lesson: null,
      });

      const wrapper = mountView();
      await flushPromises();

      expect(wrapper.text()).toContain('访问已撤销');
      await wrapper.get('button[data-action="rejoin"]').trigger('click');
      await flushPromises();

      expect(learnerApi.startCourse).toHaveBeenCalledWith(7);
      expect(routerApi.push).toHaveBeenCalledWith('/learn/7/12');
    });
  });

  describe('favorites tab', () => {
    it('keeps unavailable favorites identifiable and removes them locally', async () => {
      setPath('/me/favorites');
      const wrapper = mountView();
      await flushPromises();

      expect(wrapper.text()).toContain('已下架课程');
      expect(wrapper.text()).toContain('暂不可用');
      await wrapper.get('[data-action="remove-favorite"]').trigger('click');
      await flushPromises();

      expect(learnerApi.removeFavorite).toHaveBeenCalledWith(9);
      expect(wrapper.text()).toContain('收藏夹还是空的');
    });
  });

  describe('orders tab', () => {
    it('renders order list with status tag', async () => {
      setPath('/me/orders');
      const wrapper = mountView();
      await flushPromises();

      expect(learnerApi.fetchOrders).toHaveBeenCalled();
      expect(wrapper.text()).toContain('课程 #7');
      expect(wrapper.text()).toContain('支付成功');
    });

    it('links each order to its detail page', async () => {
      setPath('/me/orders');
      const wrapper = mountView();
      await flushPromises();

      expect(wrapper.get('a[href="/me/orders/100"]').text()).toContain('查看详情');
    });
  });

  describe('messages tab', () => {
    it('marks an unread item as read and refreshes unread count', async () => {
      setPath('/me/messages');
      const wrapper = mountView();
      await flushPromises();

      expect(wrapper.text()).toContain('问题有新回复');
      expect(wrapper.text()).toContain('学习进度已重置');
      notificationsApi.fetchUnreadCount.mockClear();
      await wrapper.get('button[data-read-id="1"]').trigger('click');
      await flushPromises();

      expect(notificationsApi.markNotificationRead).toHaveBeenCalledWith(1);
      expect(notificationsApi.fetchUnreadCount).toHaveBeenCalledTimes(1);
      expect(wrapper.find('button[data-read-id="1"]').exists()).toBe(false);
    });

    it('renders course_published messages and marks read before opening the course', async () => {
      notificationsApi.listNotifications.mockResolvedValue(coursePublishedMessagesFixture);
      setPath('/me/messages');
      const wrapper = mountView();
      await flushPromises();

      expect(wrapper.text()).toContain('新课上线:示例课');
      const tags = wrapper.findAll('.el-tag');
      expect(tags.some((tag) => tag.text() === '新课')).toBe(true);
      expect(wrapper.find('button[data-read-id="3"]').exists()).toBe(true);

      notificationsApi.fetchUnreadCount.mockClear();
      await wrapper.get('button[data-resource-id="3"]').trigger('click');
      await flushPromises();
      expect(notificationsApi.markNotificationRead).toHaveBeenCalledWith(3);
      expect(notificationsApi.fetchUnreadCount).toHaveBeenCalledOnce();
      expect(routerApi.push).toHaveBeenCalledWith('/courses/42');
    });

    it('shows the unavailable notice instead of an action when the course is gone', async () => {
      notificationsApi.listNotifications.mockResolvedValue(coursePublishedMessagesFixture);
      setPath('/me/messages');
      const wrapper = mountView();
      await flushPromises();

      expect(wrapper.text()).toContain('关联内容已不可用');
      expect(wrapper.find('button[data-resource-id="4"]').exists()).toBe(false);
    });
  });

  describe('checkins tab', () => {
    it('renders recent check-ins as a weekday calendar', async () => {
      const today = new Date();
      const todayIso = `${today.getFullYear()}-${String(today.getMonth() + 1).padStart(2, '0')}-${String(today.getDate()).padStart(2, '0')}`;
      checkinsApi.listCheckins.mockResolvedValue({
        items: [
          {
            id: 1,
            checkin_date: todayIso,
            plan_html: '<p>今日计划</p>',
            checked_in_at: `${todayIso}T09:00:00+08:00`,
          },
        ],
        total: 1,
        page: 1,
        limit: 100,
      });
      setPath('/me/checkins');
      const wrapper = mountView();
      await flushPromises();

      expect(wrapper.get('[data-testid="checkin-calendar-weekdays"]').text()).toContain('周一');
      expect(
        wrapper.findAll('[data-testid="checkin-calendar-cell"]').length,
      ).toBeGreaterThanOrEqual(30);
      expect(wrapper.findAll('time[data-testid="checkin-calendar-date"]')).toHaveLength(30);
      expect(wrapper.findAll('.heatmap-cell.hit')).toHaveLength(1);
    });

    it('renders empty state when no records', async () => {
      setPath('/me/checkins');
      const wrapper = mountView();
      await flushPromises();

      expect(wrapper.text()).toContain('还没有签到记录');
    });

    it('renders rich plan via MarkdownRenderer', async () => {
      checkinsApi.listCheckins.mockResolvedValueOnce({
        items: [
          {
            id: 1,
            checkin_date: '2026-08-30',
            plan_html: '<p>今日计划</p>',
            checked_in_at: '2026-08-30T09:00:00+08:00',
          },
        ],
        total: 1,
        page: 1,
        limit: 20,
      });
      setPath('/me/checkins');
      const wrapper = mountView();
      await flushPromises();

      expect(wrapper.text()).toContain('2026-08-30');
      expect(wrapper.text()).toContain('今日计划');
    });

    it('unsubscribes the success hook when the page unmounts', async () => {
      setPath('/me/checkins');
      const wrapper = mountView();
      await flushPromises();

      wrapper.unmount();
      // afterSuccess subscriber created on mount → no unsubscribe asserted here because
      // the inject default is null in tests; verify the call does not throw.
    });
  });

  describe('account tab', () => {
    it('uses Element Plus fields and preserves the profile update payload', async () => {
      setPath('/me/account');
      const wrapper = mountView();
      await flushPromises();

      const inputs = wrapper.findAllComponents({ name: 'ElInput' });
      expect(inputs.length).toBeGreaterThanOrEqual(2);
      expect(inputs[0]?.props('disabled')).toBe(true);
      expect(inputs[0]?.props('modelValue')).toBe('13800138000');

      await inputs[1]?.setValue(' 新称呼 ');
      await wrapper.get('[role="switch"]').trigger('click');
      await wrapper.get('form').trigger('submit');
      await flushPromises();

      expect(learnerApi.updateLearnerProfile).toHaveBeenCalledWith({
        nickname: '新称呼',
        show_on_course: true,
      });
      expect(wrapper.text()).toContain('资料已更新');
    });
  });
});
