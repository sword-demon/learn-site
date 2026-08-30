// @vitest-environment happy-dom

import { reactive } from 'vue';
import { flushPromises, mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import type { CaptchaChallenge, TokenPair } from '@learn-site/contracts';

const learnerApi = vi.hoisted(() => ({
  fetchCaptcha: vi.fn(),
  loginLearner: vi.fn(),
  registerLearner: vi.fn(),
}));
const routerApi = vi.hoisted(() => ({
  route: null as unknown as ReturnType<typeof reactive<{ path: string; query: Record<string, unknown>; fullPath: string }>>,
  replace: vi.fn(),
}));
const sessionApi = vi.hoisted(() => ({
  signIn: vi.fn(),
}));

vi.mock('@/api/learner', () => learnerApi);
vi.mock('@/api/login', () => ({
  useLoginFamilyStore: () => sessionApi,
}));
vi.mock('vue-router', () => ({
  useRoute: () => routerApi.route,
  useRouter: () => ({ replace: routerApi.replace }),
}));

import LoginRegisterView from '@/views/auth/LoginRegisterView.vue';

routerApi.route = reactive({
  path: '/login',
  query: {} as Record<string, unknown>,
  fullPath: '/login',
});

const RouterLinkStub = {
  props: { to: { type: [String, Object], required: true } },
  template: '<a :href="String(to)"><slot /></a>',
};

const captchaFixture: CaptchaChallenge = {
  captcha_id: 'cap-1',
  image: 'data:image/png;base64,QUJD',
  ttl_seconds: 120,
};
const tokenFixture = {
  access_token: 'A',
  access_expires_in: 3600,
  refresh_token: 'R',
  refresh_expires_in: 86400,
} satisfies TokenPair;

describe('LoginRegisterView', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    routerApi.route.path = '/login';
    for (const key of Object.keys(routerApi.route.query)) {
      delete routerApi.route.query[key];
    }
    routerApi.replace.mockResolvedValue(undefined);
    learnerApi.fetchCaptcha.mockResolvedValue(captchaFixture);
    learnerApi.loginLearner.mockResolvedValue(tokenFixture);
    learnerApi.registerLearner.mockResolvedValue(tokenFixture);
  });

  it('renders login copy when initial path is /login', async () => {
    routerApi.route.path = '/login';
    const wrapper = mount(LoginRegisterView, {
      global: { stubs: { RouterLink: RouterLinkStub } },
    });
    await flushPromises();

    expect((wrapper.element as HTMLElement).dataset.mode).toBe('login');
    expect(wrapper.text()).toContain('学员登录');
    expect(wrapper.text()).toContain('用手机号进入课室');
    expect(wrapper.text()).toContain('登录');
    expect(wrapper.find('[data-testid="submit-button"]').text()).toBe('登录');
  });

  it('renders register copy when initial path is /register', async () => {
    routerApi.route.path = '/register';
    const wrapper = mount(LoginRegisterView, {
      global: { stubs: { RouterLink: RouterLinkStub } },
    });
    await flushPromises();

    expect((wrapper.element as HTMLElement).dataset.mode).toBe('register');
    expect(wrapper.text()).toContain('学员注册');
    expect(wrapper.text()).toContain('领一张课室学号');
    expect(wrapper.find('[data-testid="submit-button"]').text()).toBe('注册并进入');
  });

  it('switching modes routes to the matching path and updates mode', async () => {
    routerApi.route.path = '/login';
    const wrapper = mount(LoginRegisterView, {
      global: { stubs: { RouterLink: RouterLinkStub } },
    });
    await flushPromises();

    await wrapper.get('[data-testid="switch-mode"]').trigger('click');

    expect(routerApi.replace).toHaveBeenCalledWith({ path: '/register' });
    expect((wrapper.element as HTMLElement).dataset.mode).toBe('register');
    expect(wrapper.find('[data-testid="submit-button"]').text()).toBe('注册并进入');

    await wrapper.get('[data-testid="switch-mode"]').trigger('click');
    expect(routerApi.replace).toHaveBeenLastCalledWith({ path: '/login' });
  });

  it('login submit honors ?redirect query and falls back to "/" otherwise', async () => {
    routerApi.route.path = '/login';
    routerApi.route.query = { redirect: '/me/learning' };
    const wrapper = mount(LoginRegisterView, {
      global: { stubs: { RouterLink: RouterLinkStub } },
    });
    await flushPromises();

    await wrapper.find('form').trigger('submit');
    await flushPromises();

    expect(learnerApi.loginLearner).toHaveBeenCalledTimes(1);
    expect(sessionApi.signIn).toHaveBeenCalledWith(tokenFixture);
    expect(routerApi.replace).toHaveBeenLastCalledWith('/me/learning');

    // ponytail: external/protocol-relative redirects must be dropped to '/'
    learnerApi.loginLearner.mockClear();
    sessionApi.signIn.mockClear();
    routerApi.replace.mockClear();
    routerApi.route.query.redirect = 'https://evil.example/';
    await wrapper.find('form').trigger('submit');
    await flushPromises();
    expect(routerApi.replace).toHaveBeenLastCalledWith('/');
  });

  it('LOGIN_INVALID surfaces Chinese error label and refreshes captcha', async () => {
    routerApi.route.path = '/login';
    learnerApi.loginLearner.mockRejectedValueOnce(
      Object.assign(new Error('LOGIN_INVALID'), { code: 'LOGIN_INVALID' }),
    );
    const wrapper = mount(LoginRegisterView, {
      global: { stubs: { RouterLink: RouterLinkStub } },
    });
    await flushPromises();

    const captchaCallsBefore = learnerApi.fetchCaptcha.mock.calls.length;
    await wrapper.find('form').trigger('submit');
    await flushPromises();

    expect(wrapper.text()).toContain('手机号或密码不正确');
    // ponytail: failure must refresh captcha for the retry round
    expect(learnerApi.fetchCaptcha.mock.calls.length).toBeGreaterThan(captchaCallsBefore);
    expect(learnerApi.loginLearner).toHaveBeenCalledTimes(1);
  });

  it('PHONE_TAKEN surfaces Chinese register error label', async () => {
    routerApi.route.path = '/register';
    learnerApi.registerLearner.mockRejectedValueOnce(
      Object.assign(new Error('PHONE_TAKEN'), { code: 'PHONE_TAKEN' }),
    );
    const wrapper = mount(LoginRegisterView, {
      global: { stubs: { RouterLink: RouterLinkStub } },
    });
    await flushPromises();

    await wrapper.find('form').trigger('submit');
    await flushPromises();

    expect(wrapper.text()).toContain('这个手机号已经注册');
    expect(learnerApi.registerLearner).toHaveBeenCalledTimes(1);
    expect(learnerApi.loginLearner).not.toHaveBeenCalled();
  });
});
