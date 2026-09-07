<template>
  <div class="auth-page" :data-mode="mode">
    <header class="auth-header">
      <router-link to="/" class="auth-brand">拾阶学社</router-link>
      <div><router-link to="/">返回首页</router-link>
        <el-button text circle :icon="isNight ? Sunny : Moon" :title="isNight ? '切换日间模式' : '切换夜读模式'"
          :aria-label="isNight ? '切换日间模式' : '切换夜读模式'" @click="toggleNight" />
      </div>
    </header>
    <main class="auth-main">
      <section class="auth-form-section">
        <p class="auth-brand-label">拾阶学社</p>
        <h1>{{ copy.title }}</h1>
        <el-tabs v-model="mode" class="auth-tabs" data-testid="mode-tabs" @tab-click="onTabClick">
          <el-tab-pane label="登录" name="login" />
          <el-tab-pane label="注册" name="register" />
        </el-tabs>
        <el-form :model="form" class="auth-form" label-position="top" @submit.prevent="onSubmit">
          <el-form-item label="手机号码">
            <el-input v-model="form.phone" inputmode="tel" maxlength="11" autocomplete="username"
              placeholder="请输入 11 位手机号" size="large" data-testid="phone-input" />
          </el-form-item>
          <el-form-item label="密码">
            <el-input v-model="form.password" type="password" :autocomplete="copy.autocomplete"
              show-password placeholder="8 - 72 位密码" size="large" data-testid="password-input" />
          </el-form-item>
          <el-form-item label="图形验证码">
            <div class="auth-captcha">
              <el-input v-model="form.captcha_answer" maxlength="8" autocomplete="off" placeholder="请输入验证码"
                size="large" data-testid="captcha-input" />
              <el-button class="captcha-button" :loading="loadingCaptcha" aria-label="刷新图形验证码"
                title="刷新图形验证码" @click="() => loadCaptcha()">
                <img v-if="captcha.image" :src="captcha.image" alt="点击刷新验证码" />
                <span v-else>重新加载</span>
              </el-button>
            </div>
          </el-form-item>
          <el-alert v-if="errorLabel" :title="errorLabel" type="error" :closable="false" show-icon />
          <el-button type="primary" native-type="submit" size="large" class="auth-submit"
            :disabled="busy || loadingCaptcha" :loading="busy" data-testid="submit-button">{{ copy.submit }}</el-button>
        </el-form>
        <p class="auth-switch">{{ copy.switchPrompt }} <button type="button" data-testid="switch-mode" @click="switchMode">{{ copy.switchLabel }}</button></p>
        <nav class="auth-legal" aria-label="服务条款"><router-link to="/terms">用户协议</router-link><router-link to="/help">帮助中心</router-link></nav>
      </section>
    </main>
  </div>
</template>

<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import type { TabPaneName } from 'element-plus';
import { Moon, Sunny } from '@element-plus/icons-vue';
import { fetchCaptcha, loginLearner, registerLearner } from '@/api/learner';
import { useLoginFamilyStore } from '@/api/login';
import { useTheme } from '@/composables/useTheme';
import type { CaptchaChallenge } from '@learn-site/contracts';

defineOptions({ name: 'LoginRegisterView' });

type Mode = 'login' | 'register';

interface Copy {
  badge: string;
  title: string;
  lede: string;
  submit: string;
  autocomplete: string;
  switchPrompt: string;
  switchLabel: string;
  note: string;
  asideIndex: string;
  errorFallback: string;
  errorCodes: Record<string, string>;
}

const route = useRoute();
const router = useRouter();
const session = useLoginFamilyStore();
const { isNight, toggleNight } = useTheme();

const initialMode: Mode = route.path.startsWith('/register') ? 'register' : 'login';
const mode = ref<Mode>(initialMode);

const form = reactive({ phone: '', password: '', captcha_id: '', captcha_answer: '' });
const captcha = reactive<{ image: string }>({ image: '' });
const busy = ref(false);
const loadingCaptcha = ref(false);
const error = ref('');

const redirect = computed(() =>
  mode.value === 'login' &&
  typeof route.query.redirect === 'string' &&
  route.query.redirect.startsWith('/')
    ? route.query.redirect
    : '/',
);

const copy = computed<Copy>(() =>
  mode.value === 'login'
    ? {
        badge: '学员登录',
        title: '欢迎回来',
        lede: '登录需要手机号、密码和一次图形验证码。',
        submit: '登录',
        autocomplete: 'current-password',
        switchPrompt: '还没有账户？',
        switchLabel: '现在注册',
        note: '学员账户与后台账户相互独立，请使用学员手机号登录。',
        asideIndex: 'READ · 01',
        errorFallback: '暂时无法登录',
        errorCodes: {
          CAPTCHA_INVALID: '验证码错误或已过期，请换一张再试',
          LOGIN_INVALID: '手机号或密码不正确',
          INVALID_PHONE: '请输入 11 位大陆手机号',
          VALIDATION_FAILED: '请检查手机号和密码',
        },
      }
    : {
        badge: '学员注册',
        title: '创建学员账户',
        lede: '只用大陆手机号注册，后台账号不能登录这里。',
        submit: '注册并进入',
        autocomplete: 'new-password',
        switchPrompt: '已有账户？',
        switchLabel: '直接登录',
        note: '注册成功后会自动登录，并保留你的学习进度与收藏。',
        asideIndex: 'READ · 02',
        errorFallback: '暂时无法注册',
        errorCodes: {
          CAPTCHA_INVALID: '验证码错误或已过期，请换一张再试',
          PHONE_TAKEN: '这个手机号已经注册',
          CONFLICT: '这个手机号已经注册',
          INVALID_PHONE: '请输入 11 位大陆手机号',
          PASSWORD_LENGTH: '密码需要 8 到 72 位',
          VALIDATION_FAILED: '请检查手机号和密码',
        },
      },
);

const errorLabel = computed(() => {
  if (!error.value) return '';
  return copy.value.errorCodes[error.value] ?? copy.value.errorFallback;
});

async function loadCaptcha(options?: { preserveError?: boolean }): Promise<void> {
  loadingCaptcha.value = true;
  if (!options?.preserveError) {
    error.value = '';
  }
  try {
    const challenge: CaptchaChallenge = await fetchCaptcha();
    form.captcha_id = challenge.captcha_id;
    form.captcha_answer = '';
    captcha.image = challenge.image;
  } catch {
    if (!options?.preserveError) {
      error.value = 'INTERNAL';
    }
  } finally {
    loadingCaptcha.value = false;
  }
}

async function onSubmit(): Promise<void> {
  if (busy.value) return;
  busy.value = true;
  error.value = '';
  try {
    const pair =
      mode.value === 'login' ? await loginLearner({ ...form }) : await registerLearner({ ...form });
    session.signIn(pair);
    await router.replace(redirect.value);
  } catch (err) {
    await loadCaptcha({ preserveError: true });
    error.value = err instanceof Error ? err.message : 'INTERNAL';
  } finally {
    busy.value = false;
  }
}

function setMode(next: Mode): void {
  if (mode.value !== next) {
    mode.value = next;
  }
  error.value = '';
  form.captcha_answer = '';
  void router.replace({ path: next === 'login' ? '/login' : '/register' });
}

function switchMode(): void {
  setMode(mode.value === 'login' ? 'register' : 'login');
}

function onTabClick(pane: { name: TabPaneName }): void {
  if (pane.name === 'login' || pane.name === 'register') {
    setMode(pane.name);
  }
}

onMounted(() => {
  void loadCaptcha();
});
</script>

<style scoped>
.auth-page { min-height: 100dvh; background: var(--card); }
.auth-header { height: 72px; padding: 0 40px; border-bottom: 1px solid var(--line); display: flex; align-items: center; justify-content: space-between; }
.auth-brand { font-size: 24px; font-weight: 800; color: var(--ink); }
.auth-header > div { display: flex; align-items: center; gap: 24px; }
.auth-header > div > a { font-size: 13px; color: var(--ink-2); }
.auth-main { display: flex; justify-content: center; padding: 64px 24px 48px; }
.auth-form-section { width: 400px; max-width: 100%; }
.auth-brand-label { color: var(--seal); font-size: 14px; margin: 0 0 10px; }
.auth-form-section h1 { margin: 0 0 28px; font-size: 30px; line-height: 1.4; font-weight: 700; }
.auth-tabs { margin-bottom: 16px; }
.auth-tabs :deep(.el-tabs__item) { font-size: 15px; }
.auth-form { display: grid; gap: 22px; }
.auth-form :deep(.el-form-item) { margin: 0; }
.auth-form :deep(.el-form-item__label) { font-size: 14px; color: var(--ink); margin-bottom: 8px; }
.auth-form :deep(.el-input__wrapper) { min-height: 46px; border: 0; border-radius: 6px; background: var(--card); box-shadow: 0 0 0 1px var(--line-2) inset; }
.auth-form :deep(.el-input__wrapper.is-focus) { box-shadow: 0 0 0 1px var(--seal) inset, 0 0 0 3px var(--seal-soft); }
.auth-form :deep(.el-input__inner) { outline: 0; border: 0; background: transparent; }
.auth-captcha { display: grid; grid-template-columns: minmax(0, 1fr) 132px; gap: 12px; width: 100%; }
.captcha-button { height: 46px; margin: 0; padding: 0; overflow: hidden; border-radius: 6px; }
.captcha-button img { display: block; width: 130px; height: 44px; object-fit: contain; }
.auth-submit { width: 100%; height: 46px; margin-top: 2px; font-size: 15px; border-radius: 6px; }
.auth-switch { margin: 24px 0 0; font-size: 14px; color: var(--ink-2); text-align: center; }
.auth-switch button { border: 0; padding: 0; background: transparent; color: var(--seal); cursor: pointer; }
.auth-legal { display: flex; gap: 24px; justify-content: center; margin-top: 40px; padding-top: 24px; border-top: 1px solid var(--line); }
.auth-legal a { color: var(--ink-3); font-size: 12px; }
</style>
