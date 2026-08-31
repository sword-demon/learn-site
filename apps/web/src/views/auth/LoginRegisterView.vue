<template>
  <div class="campus auth-page" :data-mode="mode">
    <header class="masthead auth-page__masthead">
      <div class="masthead-inner auth-page__masthead-inner">
        <router-link to="/" class="brand">
          <div class="seal-mark" aria-hidden="true">
            <span>拾</span><span>阶</span><span>学</span><span>社</span>
          </div>
          <div class="brand-txt">
            <h1>拾阶学社</h1>
          </div>
        </router-link>
        <div class="masthead-tools">
          <el-button
            circle
            class="btn-night"
            :icon="isNight ? Sunny : Moon"
            :title="isNight ? '切换日间模式' : '夜读模式'"
            :aria-label="isNight ? '切换日间模式' : '切换夜读模式'"
            :aria-pressed="isNight"
            @click="toggleNight"
          />
        </div>
      </div>
    </header>

    <main class="auth-page__main">
      <section class="auth-card-stitch">
        <aside class="auth-card-stitch__aside" aria-hidden="true">
          <h1>拾阶而上</h1>
          <p>逐级攀登，知识生辉</p>
        </aside>

        <div class="auth-card-stitch__body">
          <el-tabs v-model="mode" class="auth-tabs" data-testid="mode-tabs" @tab-click="onTabClick">
            <el-tab-pane label="登录" name="login" />
            <el-tab-pane label="注册" name="register" />
          </el-tabs>

          <p class="badge">{{ copy.badge }}</p>
          <h2 class="display auth-card-stitch__title">{{ copy.title }}</h2>
          <p class="lede">{{ copy.lede }}</p>

          <el-form class="auth-card-stitch__form" :model="form" @submit.prevent="onSubmit">
            <label class="field auth-field-underline">
              手机号码
              <el-input
                v-model="form.phone"
                maxlength="11"
                autocomplete="username"
                placeholder="请输入手机号"
                data-testid="phone-input"
              />
            </label>
            <label class="field auth-field-underline">
              密码
              <el-input
                v-model="form.password"
                type="password"
                :autocomplete="copy.autocomplete"
                show-password
                placeholder="请输入密码"
                data-testid="password-input"
              />
            </label>
            <label class="field auth-field-underline">
              图形验证码
              <div class="captcha-row">
                <el-input
                  v-model="form.captcha_answer"
                  maxlength="8"
                  autocomplete="off"
                  placeholder="验证码"
                  data-testid="captcha-input"
                />
                <el-button
                  class="captcha-btn"
                  :loading="loadingCaptcha"
                  aria-label="刷新图形验证码"
                  @click="() => loadCaptcha()"
                >
                  <img v-if="captcha.image" :src="captcha.image" alt="点击刷新验证码" />
                  <span v-else>加载验证码</span>
                </el-button>
              </div>
            </label>
            <el-alert
              v-if="errorLabel"
              :title="errorLabel"
              type="error"
              :closable="false"
              show-icon
            />
            <el-button
              type="primary"
              native-type="submit"
              class="auth-card-stitch__submit"
              :loading="busy"
              data-testid="submit-button"
            >
              {{ copy.submit }}
            </el-button>
          </el-form>

          <p class="switch">
            {{ copy.switchPrompt }}
            <a href="#" class="switch-link" data-testid="switch-mode" @click.prevent="switchMode">{{
              copy.switchLabel
            }}</a>
          </p>
          <p class="form-note">{{ copy.note }}</p>
        </div>
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
  asideHeadline: string;
  asideBody: string;
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
        title: '用手机号进入课室',
        lede: '登录需要手机号、密码和一次图形验证码。',
        submit: '登录',
        autocomplete: 'current-password',
        switchPrompt: '还没有学号？',
        switchLabel: '注册',
        note: '学员账户与后台账户相互独立，请使用学员手机号登录。',
        asideIndex: 'READ / 01',
        asideHeadline: '拾阶而上',
        asideBody: '拾级而上 · 日进一阶',
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
        title: '领一张课室学号',
        lede: '只用大陆手机号注册，后台账号不能登录这里。',
        submit: '注册并进入',
        autocomplete: 'new-password',
        switchPrompt: '已经有学号？',
        switchLabel: '登录',
        note: '注册成功后会自动登录，并保留你的学习进度与收藏。',
        asideIndex: 'READ / 02',
        asideHeadline: '拾阶而上',
        asideBody: '拾级而上 · 日进一阶',
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
.auth-page__masthead {
  border-bottom: 1px solid var(--line-2);
}

.auth-page__masthead-inner {
  min-height: 64px;
  padding: 0 24px;
}

.auth-page__main {
  display: flex;
  align-items: center;
  justify-content: center;
  min-height: calc(100dvh - 64px);
  padding: 40px 24px 64px;
}

.auth-card-stitch {
  display: flex;
  width: min(800px, 100%);
  overflow: hidden;
  border: 1px solid var(--line);
  border-radius: 12px;
  background: var(--card);
  box-shadow: var(--shadow);
}

.auth-card-stitch__aside {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  width: 30%;
  min-width: 200px;
  padding: 40px 24px;
  background: var(--seal);
  color: #fff;
  text-align: center;
}

.auth-card-stitch__aside h1 {
  margin: 0;
  font-family: var(--serif);
  font-size: 32px;
  line-height: 1.3;
  letter-spacing: 0.2em;
  writing-mode: vertical-rl;
}

.auth-card-stitch__aside p {
  margin: 16px 0 0;
  font-size: 12px;
  color: var(--seal-soft);
  writing-mode: vertical-rl;
  letter-spacing: 0.12em;
}

.auth-card-stitch__body {
  flex: 1;
  padding: 32px;
}

.auth-card-stitch__title {
  margin: 12px 0 8px;
  font-size: 28px;
}

.auth-card-stitch__form {
  display: grid;
  gap: 20px;
  margin-top: 20px;
}

.auth-field-underline :deep(.el-input__wrapper) {
  border-radius: 0;
  box-shadow: 0 1px 0 0 var(--line) inset;
  background: transparent;
}

.auth-field-underline :deep(.el-input__wrapper.is-focus) {
  box-shadow: 0 2px 0 0 var(--seal) inset;
}

.auth-card-stitch__submit {
  width: 100%;
  min-height: 44px;
  margin-left: 0;
}

@media (max-width: 720px) {
  .auth-card-stitch {
    flex-direction: column;
  }

  .auth-card-stitch__aside {
    width: 100%;
    min-width: 0;
    min-height: 140px;
    padding: 24px;
  }

  .auth-card-stitch__aside h1,
  .auth-card-stitch__aside p {
    writing-mode: horizontal-tb;
  }

  .auth-card-stitch__body {
    padding: 24px 20px;
  }
}
</style>
