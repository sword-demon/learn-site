<template>
  <div class="campus auth-page" :data-mode="mode">
    <header class="masthead">
      <div class="masthead-inner">
        <router-link to="/" class="brand">
          <div class="seal-mark" aria-hidden="true">
            <span>拾</span><span>阶</span><span>学</span><span>社</span>
          </div>
          <div class="brand-txt">
            <h1>拾阶学社</h1>
            <p>拾级而上 · 日进一阶</p>
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
    <main class="auth-shell">
      <aside class="auth-aside">
        <p class="eyebrow"><span class="eyebrow-rule" />拾阶学社 · 学员入口</p>
        <p class="auth-index latin">{{ copy.asideIndex }}</p>
        <h1 class="display">{{ copy.asideHeadline }}</h1>
        <p v-if="copy.asideBody">{{ copy.asideBody }}</p>
      </aside>
      <section class="auth-card">
        <el-tabs v-model="mode" class="auth-tabs" data-testid="mode-tabs" @tab-click="onTabClick">
          <el-tab-pane label="登录" name="login" />
          <el-tab-pane label="注册" name="register" />
        </el-tabs>
        <p class="badge">{{ copy.badge }}</p>
        <h2 class="display">{{ copy.title }}</h2>
        <p class="lede">{{ copy.lede }}</p>
        <el-form :model="form" @submit.prevent="onSubmit">
          <label class="field">
            手机号
            <el-input
              v-model="form.phone"
              maxlength="11"
              autocomplete="username"
              placeholder="11 位大陆手机号"
              data-testid="phone-input"
            />
          </label>
          <label class="field">
            密码
            <el-input
              v-model="form.password"
              type="password"
              :autocomplete="copy.autocomplete"
              show-password
              placeholder="8–72 位"
              data-testid="password-input"
            />
          </label>
          <label class="field">
            图形验证码
            <!-- ponytail: Figma wants sms.code + sms.expires_at; backend currently returns image captcha -->
            <div class="captcha-row">
              <el-input
                v-model="form.captcha_answer"
                maxlength="8"
                autocomplete="off"
                placeholder="图中字符"
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
            :loading="busy"
            data-testid="submit-button"
            >{{ copy.submit }}</el-button
          >
        </el-form>
        <p class="switch">
          {{ copy.switchPrompt }}
          <a href="#" class="switch-link" data-testid="switch-mode" @click.prevent="switchMode">{{
            copy.switchLabel
          }}</a>
        </p>
        <p class="form-note">{{ copy.note }}</p>
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

// ponytail: H1 route-derived mode via computed + path membership check
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

// ponytail: H1 redirect query extracted once; only honored in login mode
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
        asideHeadline: '从上次停下的地方，继续读下去。',
        asideBody: '课程、学习地图和进度记录都会留在你的个人书架里。',
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
        asideHeadline: '给自己留一间安静的课室。',
        asideBody: '注册后可以加入课程、记录进度，也可以沿着学习地图逐阶段完成。',
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
